<?php

namespace OswisOrg\OswisCalendarBundle\Controller\WebAdmin;

use DateTime;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Entity\ParticipantMail\ParticipantMail;
use OswisOrg\OswisCalendarBundle\Exception\FlagCapacityExceededException;
use OswisOrg\OswisCalendarBundle\Exception\FlagOutOfRangeException;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantPayment;
use OswisOrg\OswisCalendarBundle\Repository\Participant\ParticipantRepository;
use OswisOrg\OswisCalendarBundle\Service\Event\EventService;
use OswisOrg\OswisCalendarBundle\Service\Accommodation\RoommatePreferenceService;
use OswisOrg\OswisCalendarBundle\Service\Communication\CommunicationTimelineService;
use OswisOrg\OswisCalendarBundle\Entity\Registration\RegistrationFlagGroupOffer;
use OswisOrg\OswisCalendarBundle\Entity\Registration\RegistrationOffer;
use OswisOrg\OswisCalendarBundle\Service\Participant\ParticipantChangeService;
use OswisOrg\OswisCalendarBundle\Service\Participant\ParticipantFlagUpdateService;
use OswisOrg\OswisCalendarBundle\Service\Participant\ParticipantMailService;
use OswisOrg\OswisCalendarBundle\Service\Participant\ParticipantPaymentService;
use OswisOrg\OswisCalendarBundle\Service\Participant\ParticipantService;
use OswisOrg\OswisCalendarBundle\Service\WebAdmin\AdminReturnUrl;
use OswisOrg\OswisAddressBookBundle\Entity\AbstractClass\AbstractContact;
use OswisOrg\OswisCoreBundle\Entity\AbstractClass\AbstractMail;
use OswisOrg\OswisCoreBundle\Entity\AppUser\AppUser;
use OswisOrg\OswisAddressBookBundle\Entity\ContactDetail;
use OswisOrg\OswisAddressBookBundle\Entity\ContactDetailCategory;
use OswisOrg\OswisAddressBookBundle\Entity\Person;
use OswisOrg\OswisCoreBundle\Exceptions\OswisException;
use OswisOrg\OswisCoreBundle\Interfaces\AddressBook\ContactInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class WebAdminParticipantsController extends AbstractController
{
    public function __construct(
        private readonly ParticipantService $participantService,
        private readonly CommunicationTimelineService $timelineService,
        private readonly ParticipantMailService $participantMailService,
        private readonly ParticipantChangeService $changeService,
        private readonly ParticipantFlagUpdateService $flagUpdateService,
        private readonly RoommatePreferenceService $roommatePreferenceService,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        // Pro srovnání duplicitních přihlášek (default akce + dotaz na duplicity).
        private readonly EventService $eventService,
        private readonly ParticipantRepository $participantRepository,
        // Ruční zadání platby (hotovost u stolu, převod, který se neimportoval).
        private readonly ParticipantPaymentService $paymentService,
    ) {
    }

    /**
     * Re-send a system mail (summary, payment, activation) — fresh delivery,
     * fresh ParticipantMail row, recipient = original recipient. Useful when
     * the participant says they didn't get the mail or deleted it by mistake.
     */
    #[IsGranted('ROLE_ADMIN')]
    public function resendMail(Request $request, int $participantId, int $mailId): Response
    {
        if (!$this->isCsrfTokenValid('participant_resend_mail_'.$mailId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Neplatný CSRF token.');
        }
        $mail = $this->em->find(ParticipantMail::class, $mailId)
            ?? throw $this->createNotFoundException('E-mail nenalezen.');
        $belongsToParticipant = $mail->getParticipant()?->getId() === $participantId;
        if (!$belongsToParticipant) {
            throw $this->createAccessDeniedException('E-mail nepatří tomuto účastníkovi.');
        }
        try {
            $novy = $this->participantMailService->resend($mail);
            $popis = $mail->getSubject() ?? $mail->getType() ?? 'systémový';
            // `sent` je jediný důkaz odeslání — bez téhle kontroly hlásil admin úspěch
            // i tehdy, když mailer zprávu odmítl.
            if ($novy->isSent()) {
                $this->addFlash('success', sprintf('E-mail "%s" znovu odeslán účastníkovi #%d.', $popis, $participantId));
            } else {
                $this->addFlash('error', sprintf(
                    'E-mail "%s" se NEODESLAL: %s',
                    $popis,
                    $novy->getStatusMessage() ?? 'mailer neuvedl důvod, viz log',
                ));
            }
        } catch (\Throwable $e) {
            $this->addFlash('error', sprintf('Re-send selhal: %s', $e->getMessage()));
        }

        return new RedirectResponse($this->generateUrl(
            'oswis_org_oswis_calendar_web_admin_participant_detail',
            ['participantId' => $participantId, '_fragment' => 'komunikace'],
        ));
    }

    /**
     * Add/remove a participant's registration flags for ONE category from the admin detail page.
     *
     * Works even when the participant has no flag group for that category yet — the group is
     * materialised on demand (admin-only categories like Sleva / Zkrácený pobyt / Poznámky k platbě
     * never auto-create at registration because setFlagGroupsByOffer filters onlyPublic=true). The
     * heavy lifting (validation, capacity, soft-delete, activation, usage recompute) lives in
     * {@see ParticipantFlagUpdateService::setFlags()}. After a successful save the unified
     * "registration changed" notification fires ({@see ParticipantMailService::notifyParticipantChanged}
     * — diff-based, no-op when nothing notifiable changed), matching the app/API PUT path and the
     * already-unified admin move + delete flows. With the `admin_override` checkbox the capacity
     * ceiling is bypassed ($admin=true).
     *
     * Uses a lightweight em->find() + flush (the proven bulk-reassign persist path), not the full
     * detail-graph hydration, to avoid the L2-cache/getName() memory blow-up on large graphs.
     */
    #[IsGranted('ROLE_MANAGER')]
    public function editFlags(Request $request, int $participantId): Response
    {
        if (!$this->isCsrfTokenValid('participant_edit_flags_'.$participantId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Neplatný CSRF token.');
        }
        $participant = $this->em->find(Participant::class, $participantId);
        if (!$participant instanceof Participant || $participant->isDeleted()) {
            throw $this->createNotFoundException('Účastník nenalezen.');
        }

        $groupOfferId = (int) $request->request->get('flagGroupOfferId');
        $groupOffer = $groupOfferId > 0 ? $this->em->find(RegistrationFlagGroupOffer::class, $groupOfferId) : null;
        if (!$groupOffer instanceof RegistrationFlagGroupOffer) {
            $this->addFlash('error', 'Neznámá kategorie příznaků.');

            return $this->redirectToParticipantFlags($participantId);
        }

        $flagOfferIds = [];
        foreach ((array) $request->request->all('flagOfferIds') as $rawId) {
            if (is_numeric($rawId)) {
                $flagOfferIds[] = (int) $rawId;
            }
        }
        $admin = (bool) $request->request->get('admin_override', false);
        $textValues = [];
        foreach ((array) $request->request->all('flagTextValues') as $rawId => $rawText) {
            if (is_numeric($rawId)) {
                $textValues[(int) $rawId] = is_string($rawText) ? $rawText : null;
            }
        }

        try {
            $this->flagUpdateService->setFlags($participant, $groupOffer, $flagOfferIds, $admin, $textValues);
            $this->addFlash('success', sprintf(
                'Příznaky kategorie „%s" u účastníka #%d uloženy%s.',
                $groupOffer->getFlagCategory()?->getName() ?? '?',
                $participantId,
                $admin ? ' (povoleno překročení kapacity)' : '',
            ));
            // Parity with the app/API path: notify AFTER the flush inside setFlags() (the diff reads
            // versioned records created by the write). Failure must not break the admin flow.
            try {
                $this->participantMailService->notifyParticipantChanged($participant);
            } catch (\Throwable $mailException) {
                $this->addFlash('warning', sprintf(
                    'Příznaky uloženy, ale oznámení o změně se účastníkovi nepodařilo odeslat: %s',
                    $mailException->getMessage(),
                ));
            }
        } catch (FlagCapacityExceededException $e) {
            $this->addFlash('error', sprintf(
                'Kapacita překročena: %s. Zaškrtni „povolit překročení kapacity" pro vynucení.',
                $e->getMessage(),
            ));
        } catch (FlagOutOfRangeException $e) {
            $this->addFlash('error', sprintf('Mimo povolený počet příznaků: %s', $e->getMessage()));
        } catch (\Throwable $e) {
            $this->addFlash('error', sprintf('Příznaky neuloženy: %s', $e->getMessage()));
        }

        return $this->redirectToParticipantFlags($participantId);
    }

    private function redirectToParticipantFlags(int $participantId): RedirectResponse
    {
        return new RedirectResponse($this->generateUrl(
            'oswis_org_oswis_calendar_web_admin_participant_detail',
            ['participantId' => $participantId, '_fragment' => 'priznaky'],
        ));
    }

    /**
     * Full admin detail page for one participant: contact, registration,
     * payments, flags, notes and the embedded communication timeline.
     *
     * The legacy `arrival()` route still renders the same template without
     * timeline entries (kept for the lightweight check-in screen).
     */
    #[IsGranted('ROLE_MANAGER')]
    public function detail(int $participantId): Response
    {
        $participant = $this->participantService->getParticipant(
            [
                ParticipantRepository::CRITERIA_ID              => $participantId,
                ParticipantRepository::CRITERIA_INCLUDE_DELETED => true,
            ],
            true,
        ) ?? throw $this->createNotFoundException('Účastník nenalezen.');

        $entries = [];
        try {
            $entries = $this->timelineService->forParticipant($participant, includeInternal: true);
        } catch (\Throwable) {
            // Timeline failures must not prevent the detail page from rendering.
        }

        // Registration history (read-only) — full chronology reconstructed from the versioned
        // junction entities. Best-effort: a reconstruction failure must not break the detail page.
        $history = [];
        try {
            $history = $this->changeService->buildHistory($participant);
        } catch (\Throwable $exception) {
            // Spolknutí je záměr (stránka se nesmí rozbít), ale bez záznamu by admin viděl prázdnou
            // historii a nepoznal, že se REKONSTRUKCE nepovedla — vypadá to jako „žádné změny".
            $this->logger->warning('Historii přihlášky se nepodařilo sestavit: '.$exception->getMessage());
        }

        // Only ParticipantMail (system + ad-hoc) rows are resend-able. IMAP-imported and manual-note
        // rows have no underlying mailer. Payment confirmations, activation requests and
        // registration-changed mails are excluded too: their templates need data a bare re-send
        // cannot reconstruct ({@see ParticipantMail::NON_RESENDABLE_TYPES}).
        $resendableMailIds = [];
        foreach ($entries as $entry) {
            if ($entry instanceof ParticipantMail
                && $entry->getId() !== null
                && ParticipantMail::isResendableType($entry->getType())) {
                $resendableMailIds[$entry->getId()] = true;
            }
        }

        // Flag editor model: every category reachable for this participant (incl. admin-only ones
        // the participant has no group for yet). Best-effort — must not break the detail page.
        $flagSelectionModel = [];
        try {
            $flagSelectionModel = $this->flagUpdateService->getFlagSelectionModel($participant);
        } catch (\Throwable $exception) {
            // Totéž: bez záznamu se editor příznaků tiše vykreslí PRÁZDNÝ a vypadá to, že účastník
            // žádné příznaky nabízené nemá — přitom jen selhalo sestavení modelu.
            $this->logger->warning('Model editoru příznaků se nepodařilo sestavit: '.$exception->getMessage());
        }

        // Drobečková navigace libovolné hloubky: Úvod › Účastníci › akce › turnus › jméno.
        $crumbs = [[
            'label' => 'Účastníci',
            'url'   => $this->generateUrl('oswis_org_oswis_calendar_web_admin_participants_list'),
        ]];
        $turnus = $participant->getEvent();
        $superEvent = $turnus?->getSuperEvent();
        if (null !== $superEvent && null !== $superEvent->getName()) {
            $crumbs[] = ['label' => $superEvent->getName()];
        }
        if (null !== $turnus && null !== $turnus->getName()) {
            $crumbs[] = ['label' => $turnus->getName()];
        }
        $crumbs[] = ['label' => ($participant->getContact()?->getName() ?? 'Účastník').' #'.$participantId];

        // Sloučená timeline: komunikační záznamy + kondenzované změny přihlášky, nejnovější nahoře.
        // Změny se stejným časovým razítkem se sloučí do jedné položky (proti hlučnému per-příznak logu).
        $timeline = [];
        foreach ($entries as $commEntry) {
            // `vlakno` = předmět bez „Re:"/„Fwd:". Podle něj šablona pozná, že řádek je
            // pokračování konverzace, a nezopakuje potřetí týž tučný předmět. Klíč vlákna
            // z entity se k tomu použít NEDÁ — je odvozený i od adresy, takže naše odpověď
            // a odpověď účastníka mají různý.
            $timeline[] = [
                'at'     => $commEntry->getOccurredAt(),
                'kind'   => 'comm',
                'entry'  => $commEntry,
                'change' => null,
                'vlakno' => AbstractMail::normalizeSubject($commEntry->getSubject()),
            ];
        }
        $historyGroups = [];
        foreach ($history as $ev) {
            $tsKey = $ev['at']->format('Y-m-d\TH:i:s');
            if (!isset($historyGroups[$tsKey])) {
                $historyGroups[$tsKey] = ['at' => $ev['at'], 'events' => []];
            }
            $historyGroups[$tsKey]['events'][] = $ev;
        }
        foreach ($historyGroups as $group) {
            // Stejné klíče jako u komunikace — díky tomu je `$timeline` jednotné pole
            // a nemusí se jeho tvar popisovat anotací.
            $timeline[] = [
                'at'     => $group['at'],
                'kind'   => 'change',
                'entry'  => null,
                'change' => $this->summarizeHistoryGroup($group['events']),
                'vlakno' => null,
            ];
        }
        usort($timeline, static function (array $a, array $b): int {
            $ta = $a['at'] instanceof \DateTimeInterface ? $a['at']->getTimestamp() : PHP_INT_MIN;
            $tb = $b['at'] instanceof \DateTimeInterface ? $b['at']->getTimestamp() : PHP_INT_MIN;

            return $tb <=> $ta;
        });

        // Nabídky pro přesun jednotlivce z detailu. Omezeno na TÝŽ ročník (nadřazenou akci):
        // přesun mezi turnusy je běžný provoz, přesun do jiného roku je téměř vždy omyl a v dlouhém
        // seznamu by se na něj snadno kliklo. Kdo opravdu potřebuje napříč ročníky, má hromadný
        // průvodce ({@see WebAdminBulkReassignController}).
        $moveOffers = [];
        if (null !== $superEvent && null !== $superEvent->getId()) {
            $moveOffers = $this->em->createQuery(
                'SELECT o FROM '.RegistrationOffer::class.' o'
                .' LEFT JOIN o.event e LEFT JOIN e.superEvent se'
                .' WHERE e.id = :superId OR se.id = :superId'
                .' ORDER BY e.startDateTime ASC, o.name ASC',
            )->setParameter('superId', $superEvent->getId())->setMaxResults(50)->getResult();
        }

        // Spolubydlení se čte z OBOU stran vazby: požadavek zadá tým typicky jen u jednoho
        // z dvojice, ale na detailu toho druhého musí být vidět taky — jinak by u něj vypadal
        // jako by žádnou domluvu neměl.
        $roommatePreferences = $this->roommatePreferenceService->findRelatedTo($participant);

        return $this->render('@OswisOrgOswisCalendar/web_admin/participant.html.twig', [
            'participant'         => $participant,
            'roommatePreferences' => $roommatePreferences,
            'moveOffers'          => $moveOffers,
            'crumbs'              => $crumbs,
            'timeline'            => $timeline,
            'entries'             => $entries,
            'history'             => $history,
            'flagSelectionModel'  => $flagSelectionModel,
            'isAdmin'             => true,
            'showFullDetail'      => true,
            'participantId'       => $participantId,
            'resendableMailIds'   => $resendableMailIds,
            'page_title'          => sprintf('Přihláška #%d', $participantId),
            'pageTitle'           => sprintf('Přihláška #%d', $participantId),
        ]);
    }

    /**
     * Shrne skupinu změn přihlášky se stejným časovým razítkem do JEDNÉ položky timeline (kondenzace
     * hlučného per-příznak logu): vznik přihlášky / změna turnusu / úprava příznaků + výčet změn.
     *
     * @param list<array{at: \DateTimeInterface, verb: 'created'|'added'|'removed', kind: 'participant'|'registration'|'flag', category: string|null, label: string}> $events
     *
     * @return array{icon: string, color: string, title: string, lines: list<string>}
     */
    private function summarizeHistoryGroup(array $events): array
    {
        $created = false;
        /** @var list<string> $flagAdded */
        $flagAdded = [];
        /** @var list<string> $flagRemoved */
        $flagRemoved = [];
        /** @var list<string> $regAdded */
        $regAdded = [];
        /** @var list<string> $regRemoved */
        $regRemoved = [];
        foreach ($events as $ev) {
            if ('participant' === $ev['kind'] && 'created' === $ev['verb']) {
                $created = true;
                continue;
            }
            if ('flag' === $ev['kind']) {
                if ('removed' === $ev['verb']) {
                    $flagRemoved[] = $ev['label'];
                } else {
                    $flagAdded[] = $ev['label'];
                }
            } elseif ('registration' === $ev['kind']) {
                if ('removed' === $ev['verb']) {
                    $regRemoved[] = $ev['label'];
                } else {
                    $regAdded[] = $ev['label'];
                }
            }
        }
        $lines = [];
        foreach ($regAdded as $l) {
            $lines[] = $l;
        }
        foreach ($regRemoved as $l) {
            $lines[] = 'zrušeno: '.$l;
        }
        if ([] !== $flagAdded) {
            $lines[] = '+ '.implode(', ', $flagAdded);
        }
        if ([] !== $flagRemoved) {
            $lines[] = '− '.implode(', ', $flagRemoved);
        }
        if ($created) {
            return ['icon' => '★', 'color' => '#006FAD', 'title' => 'Přihláška vytvořena', 'lines' => $lines];
        }
        if ([] !== $regAdded || [] !== $regRemoved) {
            return ['icon' => '➡', 'color' => '#6f42c1', 'title' => 'Změna turnusu', 'lines' => $lines];
        }

        return ['icon' => '🔖', 'color' => '#198754', 'title' => 'Úprava příznaků', 'lines' => $lines];
    }

    /**
     * @throws OswisException
     */
    #[IsGranted('ROLE_ADMIN')]
    public function sendAutoMails(Request $request, int $limit = 100, ?string $type = null): Response
    {
        // State-changing real-mail send: POST + CSRF only (was a CSRF-able GET).
        if (!$this->isCsrfTokenValid('send_automails', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Neplatný CSRF token.');
        }
        // Hlásit SKUTEČNÝ výsledek, ne paušální „odesláno". Dřív se návratová hodnota zahodila
        // a stránka tvrdila „E-maily rozeslány." i tehdy, když se neodeslalo nic nebo všechno
        // selhalo — tichý úspěch, po kterém se admin nemá jak dozvědět, že se nic nestalo.
        // (Rozesílka navíc může být přeskočená, pokud zrovna běží cron — viz zámek
        // v ParticipantService::sendAutoMails().)
        $vysledek = $this->participantService->sendAutoMails(null, $type, $limit);
        $odeslano = $vysledek['sent'];
        $selhalo = $vysledek['failed'];
        $chyby = $vysledek['errors'];

        $zprava = 0 === $odeslano && 0 === $selhalo
            ? 'Neodeslán žádný e-mail — nikdo nový nečekal na automatický e-mail.'
            : sprintf('Odesláno e-mailů: %d.', $odeslano).($selhalo > 0 ? sprintf(' Selhalo: %d.', $selhalo) : '');
        if ([] !== $chyby) {
            $zprava .= ' '.implode(' | ', array_slice($chyby, 0, 5));
            if (count($chyby) > 5) {
                $zprava .= sprintf(' (a dalších %d)', count($chyby) - 5);
            }
        }

        // Admin message skeleton (keeps the admin menu) — not the public message page.
        return $this->render('@OswisOrgOswisCore/web_admin/message.html.twig', [
            'title'     => 'Akce provedena.',
            'pageTitle' => 'Akce provedena.',
            'message'   => $zprava,
            'backUrl'   => $this->generateUrl('oswis_org_oswis_core_web_admin_homepage'),
        ]);
    }

    /**
     * Show one participant's detail page used as an arrival check-in screen.
     *
     * The `$arrival` route argument is currently only used to pick the lookup
     * strictness: `true` shows only an active (non-deleted, activated)
     * participant, anything else (`false` / `null`) widens the lookup to also
     * include deleted/non-activated rows. No DB mutation happens here despite
     * the route name — the actual arrival timestamp is set elsewhere.
     *
     * Renders the same template as `detail()`, so it must never sit at a lower
     * level than `detail()` — otherwise it becomes a way around it.
     */
    #[IsGranted('ROLE_MANAGER')]
    public function arrival(int $participantId, ?bool $arrival = true): Response
    {
        $strict = (true === $arrival);
        $participant = $this->participantService->getParticipant(
            [
                ParticipantRepository::CRITERIA_ID              => $participantId,
                ParticipantRepository::CRITERIA_INCLUDE_DELETED => !$strict,
            ],
            !$strict,
        );

        return $this->render('@OswisOrgOswisCalendar/web_admin/participant.html.twig', [
            'participant' => $participant,
        ]);
    }

    /**
     * Resend the activation e-mail to the participant (admin-initiated).
     * Creates a fresh token via ParticipantService::requestActivation() and
     * redirects the admin back to the participant detail with a flash message.
     */
    #[IsGranted('ROLE_ADMIN')]
    public function resendActivation(Request $request, int $participantId): Response
    {
        if (!$this->isCsrfTokenValid('participant_resend_activation_'.$participantId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Neplatný CSRF token.');
        }
        $participant = $this->participantService->getParticipant(
            [
                ParticipantRepository::CRITERIA_ID              => $participantId,
                ParticipantRepository::CRITERIA_INCLUDE_DELETED => true,
            ],
            true,
        ) ?? throw $this->createNotFoundException('Účastník nenalezen.');

        try {
            $this->participantService->requestActivation($participant);
            $this->addFlash('success', sprintf(
                'Aktivační e-mail účastníkovi #%d znovu odeslán.',
                $participantId,
            ));
        } catch (OswisException $e) {
            $this->addFlash('error', sprintf(
                'Aktivační e-mail nešel odeslat: %s',
                $e->getMessage(),
            ));
        }

        return new RedirectResponse($this->generateUrl(
            'oswis_org_oswis_calendar_web_admin_participant_arrival',
            ['participantId' => $participantId, 'arrival' => '0'],
        ));
    }

    /**
     * Send the registration summary (= the mail with the payment instructions and the QR codes)
     * to the participant's contact persons, on demand.
     *
     * Unlike the per-entry "↻ Odeslat znovu" in the timeline, this does NOT need an existing mail
     * row to copy — it renders a fresh summary from the participant's CURRENT data, so it is the
     * recovery path when the summary was never sent at all (see the `userConfirmedAt` guard in
     * {@see ParticipantService::activate()}) or when the participant lost it. Summary data is fully
     * reconstructible (amounts, QR, ICS), so the content is always truthful — that is why this type
     * is re-sendable while payment confirmations and activation requests are not.
     */
    #[IsGranted('ROLE_ADMIN')]
    public function sendSummary(Request $request, int $participantId): Response
    {
        if (!$this->isCsrfTokenValid('participant_send_summary_'.$participantId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Neplatný CSRF token.');
        }
        $participant = $this->participantService->getParticipant(
            [
                ParticipantRepository::CRITERIA_ID              => $participantId,
                ParticipantRepository::CRITERIA_INCLUDE_DELETED => true,
            ],
            true,
        ) ?? throw $this->createNotFoundException('Účastník nenalezen.');

        try {
            // Hlásit až podle SKUTEČNÉHO doručení. Selhání SMTP nevyhodí výjimku (zaloguje se
            // a uloží do `statusMessage`), takže dřív tu svítilo „odesláno" i tehdy, když
            // nedorazilo nic — a nikdo se to nedozvěděl.
            $doruceno = $this->participantMailService->sendSummary($participant);
            if (0 === $doruceno) {
                // `sendSummary()` na nedoručení ZÁMĚRNĚ nevyhazuje výjimku (shodilo by to registraci
                // i aktivaci — viz jeho docblock), takže se tu musí zeptat na návratovou hodnotu.
                $this->addFlash('error', sprintf(
                    'Shrnutí přihlášky #%d se NEPODAŘILO doručit ani jednomu příjemci — podrobnosti v logu.',
                    $participantId,
                ));
            } else {
                $this->addFlash('success', sprintf(
                    'Shrnutí přihlášky (pokyny k platbě) odesláno účastníkovi #%d (%d příjemc%s).',
                    $participantId,
                    $doruceno,
                    1 === $doruceno ? 'i' : 'ům',
                ));
            }
        } catch (\Throwable $e) {
            $this->addFlash('error', sprintf('Shrnutí nešlo odeslat: %s', $e->getMessage()));
        }

        return new RedirectResponse($this->generateUrl(
            'oswis_org_oswis_calendar_web_admin_participant_detail',
            ['participantId' => $participantId, '_fragment' => 'komunikace'],
        ));
    }

    /**
     * Restore a soft-deleted participant (set deletedAt back to null).
     * Cascade-deleted children (flags, registrations) stay deleted — admin
     * can verify and act on them from the participant detail page.
     */
    #[IsGranted('ROLE_ADMIN')]
    public function restore(Request $request, int $participantId): Response
    {
        if (!$this->isCsrfTokenValid('participant_restore_'.$participantId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Neplatný CSRF token.');
        }
        $participant = $this->participantService->getParticipant(
            [
                ParticipantRepository::CRITERIA_ID              => $participantId,
                ParticipantRepository::CRITERIA_INCLUDE_DELETED => true,
            ],
            true,
        ) ?? throw $this->createNotFoundException('Účastník nenalezen.');

        $this->participantService->restore($participant);
        $this->addFlash('success', sprintf(
            'Účastník #%d obnoven. Případné smazané flagy a registrace zůstávají smazané — zkontroluj na detailu.',
            $participantId,
        ));

        // Hard-coded redirect to participant detail — never accept a redirect
        // URL from the request body (avoids open-redirect on admin actions).
        return new RedirectResponse($this->generateUrl(
            'oswis_org_oswis_calendar_web_admin_participant_arrival',
            ['participantId' => $participantId, 'arrival' => '0'],
        ));
    }

    /**
     * Soft-delete a participant (reversible via the restore action). Used by the quick
     * action on the unified participant list. Redirects back to the referring list view
     * when safe (same-host admin URL only), otherwise to the participant detail.
     */
    #[IsGranted('ROLE_ADMIN')]
    public function delete(Request $request, int $participantId): Response
    {
        if (!$this->isCsrfTokenValid('participant_delete_'.$participantId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Neplatný CSRF token.');
        }
        $participant = $this->participantService->getParticipant(
            [
                ParticipantRepository::CRITERIA_ID              => $participantId,
                ParticipantRepository::CRITERIA_INCLUDE_DELETED => true,
            ],
            true,
        ) ?? throw $this->createNotFoundException('Účastník nenalezen.');

        $this->participantService->delete($participant);
        $this->addFlash('success', sprintf('Účastník #%d smazán (lze obnovit).', $participantId));

        return new RedirectResponse($this->safeListRedirect($request, $participantId));
    }

    /**
     * Manually override the participant contact's gender, or clear it back to automatic
     * name-based detection. Needed when the vokativ auto-detection is wrong (ambiguous or
     * foreign names — unknowns default to male) or doesn't match the person (e.g. trans
     * participants). Affects gender classification, the Czech salutation and byl/byla
     * everywhere the contact is used.
     */
    #[IsGranted('ROLE_MANAGER')]
    public function setGender(Request $request, int $participantId): Response
    {
        if (!$this->isCsrfTokenValid('participant_set_gender_'.$participantId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Neplatný CSRF token.');
        }
        // Načítáme zlehka, ne celý detailní graf: hydratace celého grafu mutuje getName()
        // a sortableName na entitách v L2 cache, takže by následný em->flush() počítal změny
        // nad celým grafem a došla by paměť. Tenhle jeden skalár proto ukládáme cíleným
        // DQL UPDATE + zneplatněním L2 — bez flushe jednotky práce.
        // ⚠️ Druhý parametr je `$includeNotActivated`, NE přepínač načítání grafu. Stálo tu `false`,
        // což tiše vyřazovalo každou přihlášku, jejíž účet ještě nebyl aktivovaný — administrace
        // pak na uložení vrátila 404. Nejhorší na tom je, kdo do té skupiny padá: účastník, kterému
        // aktivační mail nedorazil kvůli překlepu v adrese (prod #3846 „…@gmal.com"). Tedy přesně
        // ten, kvůli kterému oprava kontaktu existuje. Administrátor musí smět zapsat vždy —
        // aktivace účtu s právem opravit údaje nesouvisí.
        $participant = $this->participantService->getParticipant(
            [
                ParticipantRepository::CRITERIA_ID              => $participantId,
                ParticipantRepository::CRITERIA_INCLUDE_DELETED => true,
            ],
            true,
        ) ?? throw $this->createNotFoundException('Účastník nenalezen.');

        $contact = $participant->getContactForRead();
        if (!$contact instanceof Person || null === $contact->getId()) {
            $this->addFlash('error', 'Kontakt účastníka není osoba — pohlaví nelze nastavit.');

            return new RedirectResponse($this->generateUrl(
                'oswis_org_oswis_calendar_web_admin_participant_detail',
                ['participantId' => $participantId],
            ));
        }

        // Whitelist to male/female; anything else (incl. '') → null = auto-detect from name.
        $requested = (string) $request->request->get('gender', '');
        $value = in_array($requested, [ContactInterface::GENDER_MALE, ContactInterface::GENDER_FEMALE], true) ? $requested : null;
        $personId = $contact->getId();

        $this->em->createQuery(
            'UPDATE '.Person::class.' p SET p.genderOverride = :g WHERE p.id = :id'
        )->setParameter('g', $value)->setParameter('id', $personId)->execute();
        // DQL UPDATE bypasses the L2 cache — evict so the detail re-read shows the new value.
        // JOINED inheritance caches under the root entity (AbstractContact), so evict both;
        // also evict the Participant (its cached contact association would otherwise still
        // resolve the stale Person on the post-redirect detail view).
        $cache = $this->em->getCache();
        if (null !== $cache) {
            $cache->evictEntity(AbstractContact::class, $personId);
            $cache->evictEntity(Person::class, $personId);
            $cache->evictEntity(\OswisOrg\OswisCalendarBundle\Entity\Participant\Participant::class, $participantId);
        }

        $label = match ($value) {
            ContactInterface::GENDER_MALE   => 'muž (ručně)',
            ContactInterface::GENDER_FEMALE => 'žena (ručně)',
            default                         => 'automaticky dle jména',
        };
        $this->addFlash('success', sprintf('Pohlaví účastníka #%d: %s.', $participantId, $label));

        return new RedirectResponse($this->generateUrl(
            'oswis_org_oswis_calendar_web_admin_participant_detail',
            ['participantId' => $participantId],
        ));
    }

    /**
     * Srovnání duplicitních přihlášek — kdo má pod letošní akcí víc než jednu živou přihlášku.
     *
     * ⚠️ **Nic tu nenavrhuje, co je „správně".** Na datech 19. 8. 2026 byly ze tří případů dva
     * omyl (dvakrát týž turnus) a jeden **legitimní** — člověk jel oba turnusy a poslal dvě různé
     * platby. Rozhodnutí patří týmu; obrazovka jen ukáže, co na které přihlášce visí.
     *
     * Rušení přihlášky se NEDUPLIKUJE — používá se existující akce `participant_delete`.
     * Chybějící díl byl **přesun plateb**: dokud peníze sedí na té přihlášce, která se má zrušit,
     * není co dělat.
     */
    #[IsGranted('ROLE_MANAGER')]
    public function duplicates(): Response
    {
        $event = $this->eventService->getDefaultEvent();
        $skupiny = [];
        if (null !== $event) {
            foreach ($this->participantRepository->findDuplicateRegistrationDetails($event) as $radek) {
                // Klíč už není contactId: skupina může vzniknout i shodou telefonu napříč
                // různými kontakty (člověk se přihlásil podruhé s jinou adresou).
                $klic = $radek['klic'];
                $skupiny[$klic]['name'] = $radek['name'];
                $skupiny[$klic]['duvod'] = $radek['duvod'];
                $skupiny[$klic]['prihlasky'][] = $radek;
            }
        }

        return $this->render('@OswisOrgOswisCalendar/web_admin/participants/duplicates.html.twig', [
            'event'    => $event,
            'skupiny'  => $skupiny,
            'title'    => 'Duplicitní přihlášky',
        ]);
    }

    /**
     * Přesune VŠECHNY platby z jedné přihlášky na druhou.
     *
     * Proč to musí existovat: když se člověk přihlásí dvakrát a zaplatí na tu přihlášku, která
     * se má zrušit, nešlo s tím doteď nic dělat — zrušením by se peníze „ztratily" z pohledu
     * druhé přihlášky. Obě přihlášky musí patřit TÉMUŽ člověku, jinak by se dala platba
     * přesunout komukoliv.
     *
     * Zapisuje se cíleným SQL UPDATE: přesouvá se jen cizí klíč, žádná hodnota platby se nemění,
     * takže není co počítat přes UnitOfWork (a hydratace plného grafu je tu stejně nežádoucí).
     */
    #[IsGranted('ROLE_MANAGER')]
    public function movePayments(Request $request, int $participantId): Response
    {
        if (!$this->isCsrfTokenValid('participant_move_payments_'.$participantId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Neplatný CSRF token.');
        }
        $cilId = (int) $request->request->get('targetParticipantId', 0);
        if ($cilId === $participantId || $cilId <= 0) {
            $this->addFlash('error', 'Cílová přihláška není platná.');

            return $this->zpetNaDuplicity();
        }
        $spojeni = $this->em->getConnection();
        $kontaktZdroje = $this->kontaktPrihlasky($participantId);
        $kontaktCile = $this->kontaktPrihlasky($cilId);
        if (null === $kontaktZdroje || $kontaktZdroje !== $kontaktCile) {
            // Pojistka proti překlepu v id: peníze se smí posunout jen mezi přihláškami TÉHOŽ člověka.
            $this->addFlash('error', 'Přesun zamítnut — přihlášky nepatří stejnému člověku.');

            return $this->zpetNaDuplicity();
        }
        $presunuto = $spojeni->executeStatement(
            'UPDATE calendar_participant_payment SET participant_id = :cil WHERE participant_id = :zdroj',
            ['cil' => $cilId, 'zdroj' => $participantId],
        );
        $cache = $this->em->getCache();
        if (null !== $cache) {
            $cache->evictEntity(\OswisOrg\OswisCalendarBundle\Entity\Participant\Participant::class, $participantId);
            $cache->evictEntity(\OswisOrg\OswisCalendarBundle\Entity\Participant\Participant::class, $cilId);
        }
        $this->addFlash(
            0 === $presunuto ? 'info' : 'success',
            0 === $presunuto
                ? sprintf('Přihláška #%d žádné platby nemá — nebylo co přesunout.', $participantId)
                : sprintf('Přesunuto %d plateb z přihlášky #%d na #%d.', $presunuto, $participantId, $cilId),
        );

        return $this->zpetNaDuplicity();
    }

    /**
     * Převod JEDNÉ platby na přihlášku JINÉHO člověka — typicky když někdo nejede a jde za něj kamarád,
     * takže se má převést i záloha.
     *
     * ⚠️ Vědomě oddělené od `movePayments()`: ten smí posouvat peníze jen mezi přihláškami TÉHOŽ
     * člověka (duplicity) a chrání se před překlepem v id. Tady se peníze převádějí mezi LIDMI,
     * takže je to samostatná akce, **jen pro `ROLE_ADMIN`** a s vlastním potvrzením.
     *
     * ⚠️ **Nepřesouvá se řádek platby.** Vzniknou DVĚ nové platby typu `internal`: záporná na
     * zdrojové přihlášce a kladná na cílové — tak, jak to tým dělá i ručně (upřesnění usera
     * 19. 8. 2026). Původní bankovní platba zůstane nedotčená, takže vazba na výpis drží
     * a na zdrojové přihlášce je vidět, že tam peníze byly a kam odešly.
     *
     * Cíl se zadává **id přihlášky nebo e-mailem** — id se z jiné přihlášky opisuje špatně.
     */
    #[IsGranted('ROLE_ADMIN')]
    public function transferPayment(Request $request, int $paymentId): Response
    {
        if (!$this->isCsrfTokenValid('payment_transfer_'.$paymentId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Neplatný CSRF token.');
        }
        $platba = $this->em->find(ParticipantPayment::class, $paymentId);
        $zdrojId = $platba?->getParticipant()?->getId();
        if (!$platba instanceof ParticipantPayment || null === $zdrojId) {
            throw $this->createNotFoundException('Platba nenalezena.');
        }
        $zadani = trim((string) $request->request->get('targetParticipant', ''));
        $cilId = $this->najdiPrihlasku($zadani);
        if (null === $cilId) {
            $this->addFlash('error', sprintf('Cílová přihláška „%s" nenalezena — zadej ID přihlášky nebo e-mail.', $zadani));

            return $this->zpetNaDetail($zdrojId);
        }
        if ($cilId === $zdrojId) {
            $this->addFlash('info', 'Platba už na téhle přihlášce je.');

            return $this->zpetNaDetail($zdrojId);
        }

        $castka = (float) $platba->getNumericValue();
        if (0.0 === $castka) {
            $this->addFlash('error', 'Nulovou platbu není co převádět.');

            return $this->zpetNaDetail($zdrojId);
        }
        $spojeni = $this->em->getConnection();
        $ted = new \DateTimeImmutable();
        $kdo = (string) ($this->getUser()?->getUserIdentifier() ?? 'neznámý admin');

        // Tým to takhle dělá i ručně: na jedné přihlášce ZÁPORNÁ platba, na druhé KLADNÁ
        // (upřesnění usera 19. 8. 2026). Typ `internal` už se v datech používá — 27 záporných
        // plateb tohohle typu tam bylo i před touhle funkcí.
        //
        // ⚠️ PŮVODNÍ bankovní platba se NEMĚNÍ. Je to záznam ze skutečného výpisu; kdyby se
        // přepsala nebo přesunula, ztratila by se vazba na banku a na zdrojové přihlášce by
        // vypadalo, že tam peníze nikdy nebyly.
        //
        // ⚠️ `confirmed_by_mail_at` se vyplňuje SCHVÁLNĚ: cron rozesílá potvrzení každé platbě,
        // která ho má prázdné (`findAwaitingConfirmationIds`), takže bez toho by oběma lidem
        // odešel mail o „přijaté platbě" — u té záporné obzvlášť nesmyslný.
        $vloz = static function (int $prihlaska, float $hodnota, string $poznamka) use ($spojeni, $ted): void {
            $spojeni->executeStatement(
                'INSERT INTO calendar_participant_payment'
                .' (participant_id, numeric_value, type, note, date_time, created_at, updated_at, confirmed_by_mail_at)'
                .' VALUES (:prihlaska, :hodnota, :typ, :poznamka, :ted, :ted, :ted, :ted)',
                [
                    'prihlaska' => $prihlaska,
                    'hodnota'   => $hodnota,
                    'typ'       => ParticipantPayment::TYPE_INTERNAL,
                    'poznamka'  => $poznamka,
                    'ted'       => $ted->format('Y-m-d H:i:s'),
                ],
            );
        };
        // Znění podle toho, jak si to tým píše ručně už od 2021 („PŘEVOD ZÁLOHY NA VERONIKU
        // TURKOVOU" / „PŘEVOD ZÁLOHY OD ŠARLOTY ŠPUNDOVÉ") — tedy se JMÉNEM, ne jen číslem
        // přihlášky. Číslo se přidává navíc, ať je vazba jednoznačná i u dvou stejných jmen.
        $jmenoZdroje = $this->jmenoPrihlasky($zdrojId) ?? ('přihláška #'.$zdrojId);
        $jmenoCile = $this->jmenoPrihlasky($cilId) ?? ('přihláška #'.$cilId);
        $vloz(
            $zdrojId,
            -$castka,
            sprintf('Převod zálohy na %s (#%d), %s, %s.', $jmenoCile, $cilId, $kdo, $ted->format('j. n. Y')),
        );
        $vloz(
            $cilId,
            $castka,
            sprintf('Převod zálohy od %s (#%d), %s, %s.', $jmenoZdroje, $zdrojId, $kdo, $ted->format('j. n. Y')),
        );

        $cache = $this->em->getCache();
        if (null !== $cache) {
            $cache->evictEntity(\OswisOrg\OswisCalendarBundle\Entity\Participant\Participant::class, $zdrojId);
            $cache->evictEntity(\OswisOrg\OswisCalendarBundle\Entity\Participant\Participant::class, $cilId);
        }
        $this->logger->info(sprintf('Převod zálohy %s Kč z přihlášky #%d na #%d (%s).', $castka, $zdrojId, $cilId, $kdo));
        $this->addFlash('success', sprintf(
            'Převedeno %s Kč na přihlášku #%d — na téhle přihlášce přibyla záporná platba, na cílové kladná.'
            .' Původní bankovní platba zůstala beze změny a účastníkům se nic nerozesílalo.',
            $castka,
            $cilId,
        ));

        return $this->zpetNaDetail($zdrojId);
    }

    /** Jméno člověka za přihláškou — do poznámky u převodu, aby byla čitelná i bez proklikávání. */
    private function jmenoPrihlasky(int $participantId): ?string
    {
        $jmeno = $this->em->getConnection()->fetchOne(
            'SELECT c.name FROM calendar_participant_contact pc'
            .' JOIN address_book_abstract_contact c ON c.id = pc.contact_id'
            .' WHERE pc.participant_id = :id AND pc.deleted_at IS NULL ORDER BY pc.id ASC LIMIT 1',
            ['id' => $participantId],
        );

        return is_string($jmeno) && '' !== $jmeno ? $jmeno : null;
    }

    /**
     * Najde ŽIVOU přihlášku podle jejího id nebo podle e-mailu kontaktu.
     *
     * Zadávat cizí přihlášku samotným číslem je past na překlep, takže se bere i e-mail —
     * ten má admin po ruce z komunikace.
     */
    private function najdiPrihlasku(string $zadani): ?int
    {
        if ('' === $zadani) {
            return null;
        }
        $spojeni = $this->em->getConnection();
        if (ctype_digit($zadani)) {
            $id = $spojeni->fetchOne(
                'SELECT id FROM calendar_participant WHERE id = :id AND deleted_at IS NULL',
                ['id' => (int) $zadani],
            );

            return is_numeric($id) ? (int) $id : null;
        }
        $id = $spojeni->fetchOne(
            'SELECT p.id FROM calendar_participant p
               JOIN calendar_participant_contact pc ON pc.participant_id = p.id AND pc.deleted_at IS NULL
               JOIN address_book_contact_detail d ON d.contact_id = pc.contact_id
               JOIN address_book_contact_detail_category c ON c.id = d.category_id AND c.type = :typ
              WHERE p.deleted_at IS NULL AND d.content = :mail
              ORDER BY p.id DESC LIMIT 1',
            ['typ' => ContactDetailCategory::TYPE_EMAIL, 'mail' => $zadani],
        );

        return is_numeric($id) ? (int) $id : null;
    }

    /** Id kontaktu, kterému přihláška patří (nebo null). Slouží jako pojistka u přesunu plateb. */
    private function kontaktPrihlasky(int $participantId): ?int
    {
        $id = $this->em->getConnection()->fetchOne(
            'SELECT contact_id FROM calendar_participant_contact'
            .' WHERE participant_id = :id AND deleted_at IS NULL ORDER BY id ASC LIMIT 1',
            ['id' => $participantId],
        );

        return is_numeric($id) ? (int) $id : null;
    }

    private function zpetNaDuplicity(): RedirectResponse
    {
        return new RedirectResponse(
            $this->generateUrl('oswis_org_oswis_calendar_web_admin_participants_duplicates')
        );
    }

    /**
     * Oprava ÚDAJŮ KONTAKTU u přihlášky — jméno, e-mail, telefon.
     *
     * Proč to vzniklo: ve web adminu **neexistovala žádná cesta, jak opravit překlep ve jméně
     * ani změnit e-mail či telefon** (ověřeno 19. 8. 2026 — routa na editaci kontaktu prostě
     * nebyla). Přitom na těchto polích visí rozesílání pošty, párování plateb i příjezdová
     * evidence. User 19. 8.: „editaci kontaktu můžeme dát rovnou do editace přihlášky, aby se
     * upravilo obojí najednou".
     *
     * ⚠️ **Jméno se NEROZKLÁDÁ tady.** `setName()` má netriviální parser (tituly, prostřední
     * jména, jednoslovná jména, spojovníky) a právě jeho obcházení vyrobilo na produkci
     * „napůl uložené přihlášky" bez jména. Voláme tedy `setName()` na entitě a teprve
     * VÝSLEDNÉ části zapíšeme cíleným DQL UPDATE.
     *
     * ⚠️ **Nesmí se flushovat celý graf** (viz `setGender()`): hydratace plného detailu mutuje
     * `getName()`/`sortableName` na L2-cachovaných entitách a následný flush by počítal
     * changeset přes celý graf a vyčerpal paměť. Proto lightweight načtení + DQL + evikce L2.
     */
    #[IsGranted('ROLE_MANAGER')]
    /**
     * Ruční zadání platby na detailu přihlášky.
     *
     * Do 26. 8. 2026 to v administraci NEŠLO VŮBEC: platba mohla vzniknout jedině importem
     * bankovního výpisu, nebo se převést od jiné přihlášky. Hotovost převzatá u stolu ani
     * převod, který se z výpisu nespároval, se zadat nedaly — a v appce to přitom šlo.
     * (Nález usera; matice parity to vedla jako `web-admin ❌ / appka ✅`.)
     *
     * ⚠️ Potvrzení účastníkovi se odesílá OKAMŽITĚ, ne cronem, proto je to vědomá volba
     * a výchozí stav je NEposílat: ruční zadání bývá oprava nebo hotovost předaná z ruky do
     * ruky, kdy člověk stojí u stolu a mail mu je k ničemu.
     */
    public function addPayment(Request $request, int $participantId): Response
    {
        if (!$this->isCsrfTokenValid('participant_payment_new_'.$participantId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Neplatný CSRF token.');
        }
        $participant = $this->participantService->getParticipant(
            [
                ParticipantRepository::CRITERIA_ID              => $participantId,
                ParticipantRepository::CRITERIA_INCLUDE_DELETED => true,
            ],
            true,
        ) ?? throw $this->createNotFoundException('Účastník nenalezen.');

        $castka = (int) round((float) str_replace([' ', ','], ['', '.'], (string) $request->request->get('numericValue')));
        if (0 === $castka) {
            $this->addFlash('error', 'Částka musí být nenulová (záporná = vratka).');

            return $this->zpetNaDetail($participantId);
        }

        $typ = (string) $request->request->get('type', ParticipantPayment::TYPE_CASH);
        if (!in_array($typ, ParticipantPayment::ALLOWED_TYPES, true)) {
            $this->addFlash('error', 'Neznámý typ platby.');

            return $this->zpetNaDetail($participantId);
        }

        $datum = trim((string) $request->request->get('dateTime', ''));
        try {
            $kdy = '' === $datum ? new DateTime() : new DateTime($datum);
        } catch (\Exception) {
            $this->addFlash('error', 'Datum platby se nepodařilo přečíst.');

            return $this->zpetNaDetail($participantId);
        }

        $platba = new ParticipantPayment($castka, $kdy, $typ);
        $poznamka = trim((string) $request->request->get('internalNote', ''));
        if ('' !== $poznamka) {
            $platba->setInternalNote($poznamka);
        }
        // Ruční platba nemá `externalId` z výpisu — díky tomu ji kontrola duplicit v service
        // přeskočí a nezamění ji s importovanou. Autora zapisuje Blameable.
        $poslatPotvrzeni = '1' === (string) $request->request->get('sendConfirmation', '0');

        $vytvorena = $this->paymentService->create($platba, $poslatPotvrzeni, $participant);
        if (null === $vytvorena) {
            $this->addFlash('error', 'Platbu se nepodařilo uložit — podrobnosti jsou v logu.');

            return $this->zpetNaDetail($participantId);
        }

        $this->logger->info(sprintf(
            'Ručně zadána platba #%s (%d Kč, %s) k přihlášce #%d, potvrzení %s.',
            $vytvorena->getId() ?? '?',
            $castka,
            $typ,
            $participantId,
            $poslatPotvrzeni ? 'odesláno' : 'neodesláno',
        ));
        $this->addFlash('success', sprintf(
            'Platba %s\u{a0}Kč uložena.%s',
            number_format($castka, 0, ',', ' '),
            $poslatPotvrzeni ? ' Účastníkovi odešlo potvrzení.' : '',
        ));

        return $this->zpetNaDetail($participantId);
    }

    #[IsGranted('ROLE_MANAGER')]
    public function editContact(Request $request, int $participantId): Response
    {
        if (!$this->isCsrfTokenValid('participant_edit_contact_'.$participantId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Neplatný CSRF token.');
        }
        // ⚠️ Druhý parametr je `$includeNotActivated`, NE přepínač načítání grafu. Stálo tu `false`,
        // což tiše vyřazovalo každou přihlášku, jejíž účet ještě nebyl aktivovaný — administrace
        // pak na uložení vrátila 404. Nejhorší na tom je, kdo do té skupiny padá: účastník, kterému
        // aktivační mail nedorazil kvůli překlepu v adrese (prod #3846 „…@gmal.com"). Tedy přesně
        // ten, kvůli kterému oprava kontaktu existuje. Administrátor musí smět zapsat vždy —
        // aktivace účtu s právem opravit údaje nesouvisí.
        $participant = $this->participantService->getParticipant(
            [
                ParticipantRepository::CRITERIA_ID              => $participantId,
                ParticipantRepository::CRITERIA_INCLUDE_DELETED => true,
            ],
            true,
        ) ?? throw $this->createNotFoundException('Účastník nenalezen.');

        $contact = $participant->getContactForRead();
        if (!$contact instanceof Person || null === $contact->getId()) {
            $this->addFlash('error', 'Kontakt účastníka není osoba — údaje nelze upravit.');

            return $this->zpetNaDetail($participantId);
        }
        $personId = $contact->getId();

        $zmeny = [];
        $chyby = [];

        $ucet = $contact->getAppUser();

        $noveJmeno = trim((string) $request->request->get('contactName', ''));
        if ('' !== $noveJmeno && $noveJmeno !== (string) $contact->getName()) {
            $zmeny[] = $this->ulozJmeno($personId, $noveJmeno);
            // Jméno musí sedět i na ÚČTU, jinak se rozejde s přihláškou: účet má vlastní
            // `name`/`given_name`/… a bere se z něj oslovení i podpis v aplikaci.
            if (null !== $ucet?->getId()) {
                $this->ulozJmenoUctu($ucet->getId(), $noveJmeno);
            }
        }

        foreach ([
            ['pole' => 'contactEmail', 'typ' => ContactDetailCategory::TYPE_EMAIL, 'popis' => 'e-mail'],
            ['pole' => 'contactPhone', 'typ' => ContactDetailCategory::TYPE_PHONE, 'popis' => 'telefon'],
        ] as $udaj) {
            $nova = trim((string) $request->request->get($udaj['pole'], ''));
            if ('' === $nova) {
                continue;
            }
            if ('e-mail' === $udaj['popis'] && !filter_var($nova, FILTER_VALIDATE_EMAIL)) {
                $chyby[] = sprintf('„%s" není platná e-mailová adresa — e-mail nezměněn.', $nova);

                continue;
            }
            $puvodni = ContactDetailCategory::TYPE_EMAIL === $udaj['typ'] ? $contact->getEmail() : $contact->getPhone();
            if ($nova === (string) $puvodni) {
                continue;
            }
            $ulozeno = $this->ulozKontaktniUdaj($personId, $udaj['typ'], $nova);
            if (null === $ulozeno) {
                $chyby[] = sprintf('%s se nepodařilo uložit — kontakt zatím žádný nemá a nový se zakládá jinde.', ucfirst($udaj['popis']));

                continue;
            }
            $zmeny[] = sprintf('%s → %s', $udaj['popis'], $nova);
            // ⚠️ E-mail se MUSÍ změnit i na účtu. `getMailerAddress()` bere adresu účtu
            // přednostně, takže při změně jen na kontaktu by pošta dál chodila na starou
            // adresu — a účastník by se dožadoval, proč mu nic nechodí.
            if (ContactDetailCategory::TYPE_EMAIL === $udaj['typ'] && null !== $ucet?->getId()) {
                $zmeny[] = $this->ulozMailUctu($ucet->getId(), (string) $ucet->getEmail(), (string) $ucet->getUsername(), $nova);
            }
        }

        $this->vyhodZCache($personId, $participantId);

        foreach ($chyby as $chyba) {
            $this->addFlash('error', $chyba);
        }
        if ([] === $zmeny) {
            if ([] === $chyby) {
                $this->addFlash('info', 'Žádná změna — údaje zůstaly stejné.');
            }

            return $this->zpetNaDetail($participantId);
        }
        $this->addFlash('success', sprintf('Upraveno u přihlášky #%d: %s.', $participantId, implode(', ', $zmeny)));

        return $this->zpetNaDetail($participantId);
    }

    /**
     * Zapíše jméno rozložené SKUTEČNÝM parserem entity (viz varování v `editContact()`).
     *
     * @return string popis změny do hlášky
     */
    private function ulozJmeno(int $personId, string $noveJmeno): string
    {
        $osoba = $this->em->find(Person::class, $personId);
        if (!$osoba instanceof Person) {
            return 'jméno';
        }
        $osoba->setName($noveJmeno);
        // ⚠️ DVA samostatné UPDATE, ne jeden. Dědičnost je JOINED: `name`/`sortableName` sedí
        // v tabulce kontaktu, ostatní části jména v tabulce osoby. Jeden DQL UPDATE přes obojí
        // se nechoval správně — do `name` se uložilo jen křestní jméno („Test" místo
        // „Test Kontaktovský", odhaleno testem 19. 8. 2026).
        $this->em->createQuery(
            'UPDATE '.AbstractContact::class.' c SET c.name = :name, c.sortableName = :sortable WHERE c.id = :id'
        )
            ->setParameter('name', $osoba->getName())
            ->setParameter('sortable', $osoba->getSortableName())
            ->setParameter('id', $personId)
            ->execute();
        $this->em->createQuery(
            'UPDATE '.Person::class.' p SET p.givenName = :given, p.additionalName = :additional,'
            .' p.familyName = :family, p.honorificPrefix = :prefix, p.honorificSuffix = :suffix,'
            .' p.nickname = :nickname WHERE p.id = :id'
        )
            ->setParameter('given', $osoba->getGivenName())
            ->setParameter('additional', $osoba->getAdditionalName())
            ->setParameter('family', $osoba->getFamilyName())
            ->setParameter('prefix', $osoba->getHonorificPrefix())
            ->setParameter('suffix', $osoba->getHonorificSuffix())
            ->setParameter('nickname', $osoba->getNickname())
            ->setParameter('id', $personId)
            ->execute();
        // Entita zůstala „špinavá" po setName(); odpojíme ji, ať ji nechytí případný cizí flush.
        $this->em->detach($osoba);

        return sprintf('jméno → %s', $noveJmeno);
    }

    /**
     * Srovná jméno na uživatelském účtu s kontaktem.
     *
     * `core_app_user` je JEDNA tabulka (žádná JOINED dědičnost jako u kontaktu), takže jediný
     * UPDATE stačí. Rozklad na části zase dělá parser entity, ne tenhle kód.
     */
    private function ulozJmenoUctu(int $ucetId, string $noveJmeno): void
    {
        $ucet = $this->em->find(AppUser::class, $ucetId);
        if (!$ucet instanceof AppUser) {
            return;
        }
        $ucet->setName($noveJmeno);
        $this->em->createQuery(
            'UPDATE '.AppUser::class.' u SET u.name = :name, u.sortableName = :sortable,'
            .' u.givenName = :given, u.additionalName = :additional, u.familyName = :family,'
            .' u.honorificPrefix = :prefix, u.honorificSuffix = :suffix, u.nickname = :nickname'
            .' WHERE u.id = :id'
        )
            ->setParameter('name', $ucet->getName())
            ->setParameter('sortable', $ucet->getSortableName())
            ->setParameter('given', $ucet->getGivenName())
            ->setParameter('additional', $ucet->getAdditionalName())
            ->setParameter('family', $ucet->getFamilyName())
            ->setParameter('prefix', $ucet->getHonorificPrefix())
            ->setParameter('suffix', $ucet->getHonorificSuffix())
            ->setParameter('nickname', $ucet->getNickname())
            ->setParameter('id', $ucetId)
            ->execute();
        $this->em->detach($ucet);
        $this->em->getCache()?->evictEntity(AppUser::class, $ucetId);
    }

    /**
     * Změní e-mail účtu — a s ním i přihlašovací jméno, POKUD bylo shodné se starým e-mailem.
     *
     * Většina účtů vzniká z přihlášky, takže `username` == e-mail; kdyby se změnil jen e-mail,
     * zůstalo by přihlašovací jméno viset na staré adrese a působilo by to jako chyba.
     * Vlastní přihlašovací jméno (např. `zakjakub`) se ale NEPŘEPISUJE — to by člověku
     * změnilo účet pod rukama.
     *
     * @return string popis změny do hlášky
     */
    private function ulozMailUctu(int $ucetId, string $puvodniMail, string $puvodniLogin, string $novyMail): string
    {
        $menitLogin = '' !== $puvodniLogin && 0 === strcasecmp($puvodniLogin, $puvodniMail);
        $dotaz = $this->em->createQuery(
            $menitLogin
                ? 'UPDATE '.AppUser::class.' u SET u.email = :mail, u.username = :mail WHERE u.id = :id'
                : 'UPDATE '.AppUser::class.' u SET u.email = :mail WHERE u.id = :id'
        );
        $dotaz->setParameter('mail', $novyMail)->setParameter('id', $ucetId)->execute();
        $this->em->getCache()?->evictEntity(AppUser::class, $ucetId);

        return $menitLogin ? 'e-mail i přihlašovací jméno účtu' : 'e-mail účtu';
    }

    /**
     * Přepíše obsah PRVNÍHO kontaktního údaje daného typu (e-mail / telefon).
     *
     * `getEmail()`/`getPhone()` čtou právě první záznam, takže se mění ten, který je všude vidět.
     * Když kontakt údaj daného typu nemá, vrací null — zakládání nového záznamu (i s kategorií)
     * sem nepatří, dělá se při registraci.
     *
     * @return string|null nová hodnota, nebo null když není co přepsat
     */
    private function ulozKontaktniUdaj(int $personId, string $typ, string $hodnota): ?string
    {
        /** @var list<array{id: int|string}> $radky */
        $radky = $this->em->createQuery(
            'SELECT d.id FROM '.ContactDetail::class.' d'
            .' JOIN d.detailCategory c WHERE d.contact = :contact AND c.type = :typ'
            .' ORDER BY d.id ASC'
        )
            ->setParameter('contact', $personId)
            ->setParameter('typ', $typ)
            ->setMaxResults(1)
            ->getScalarResult();
        if ([] === $radky) {
            return null;
        }
        $id = (int) $radky[0]['id'];
        $this->em->createQuery('UPDATE '.ContactDetail::class.' d SET d.content = :obsah WHERE d.id = :id')
            ->setParameter('obsah', $hodnota)
            ->setParameter('id', $id)
            ->execute();
        $cache = $this->em->getCache();
        $cache?->evictEntity(ContactDetail::class, $id);

        return $hodnota;
    }

    /**
     * DQL UPDATE obchází L2 cache — vyhodit oba konce dědičnosti i přihlášku,
     * jinak detail po přesměrování ukáže starou hodnotu (totéž řeší `setGender()`).
     */
    private function vyhodZCache(int $personId, int $participantId): void
    {
        $cache = $this->em->getCache();
        if (null === $cache) {
            return;
        }
        $cache->evictEntity(AbstractContact::class, $personId);
        $cache->evictEntity(Person::class, $personId);
        $cache->evictEntity(\OswisOrg\OswisCalendarBundle\Entity\Participant\Participant::class, $participantId);
    }

    private function zpetNaDetail(int $participantId): RedirectResponse
    {
        return new RedirectResponse($this->generateUrl(
            'oswis_org_oswis_calendar_web_admin_participant_detail',
            ['participantId' => $participantId],
        ));
    }

    /**
     * Nastaví partial-stay (plánovaný pozdější příjezd / dřívější odjezd) — VOLNÝ TEXT, plní tým z e-mailu/
     * poznámky. Lightweight DQL UPDATE + L2 evikce (jako setGender) — vyhne se getName/L2 OOM na plném grafu.
     */
    #[IsGranted('ROLE_MANAGER')]
    public function setPartialStay(Request $request, int $participantId): Response
    {
        if (!$this->isCsrfTokenValid('participant_partial_stay_'.$participantId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Neplatný CSRF token.');
        }
        $arrival = trim((string) $request->request->get('plannedArrival', ''));
        $departure = trim((string) $request->request->get('plannedDeparture', ''));
        $this->em->createQuery(
            'UPDATE '.Participant::class.' p SET p.plannedArrival = :a, p.plannedDeparture = :d WHERE p.id = :id'
        )
            ->setParameter('a', '' === $arrival ? null : $arrival)
            ->setParameter('d', '' === $departure ? null : $departure)
            ->setParameter('id', $participantId)
            ->execute();
        $cache = $this->em->getCache();
        if (null !== $cache) {
            $cache->evictEntity(Participant::class, $participantId);
        }
        $this->addFlash('success', sprintf('Zkrácený pobyt účastníka #%d uložen.', $participantId));

        return new RedirectResponse($this->generateUrl(
            'oswis_org_oswis_calendar_web_admin_participant_detail',
            ['participantId' => $participantId, '_fragment' => 'partial-stay'],
        ));
    }

    /**
     * Resolve a safe post-action redirect target: the admin page the form was on (`Referer`),
     * otherwise the participant detail page. Never trusts an off-site URL (open-redirect guard).
     * {@see AdminReturnUrl} for why this is not a hidden URL field any more.
     */
    private function safeListRedirect(Request $request, int $participantId): string
    {
        $return = AdminReturnUrl::fromReferer($request);
        if (null !== $return) {
            return $return;
        }

        return $this->generateUrl(
            'oswis_org_oswis_calendar_web_admin_participant_arrival',
            ['participantId' => $participantId, 'arrival' => '0'],
        );
    }
}
