<?php

namespace OswisOrg\OswisCalendarBundle\Controller\WebAdmin;

use OswisOrg\OswisCalendarBundle\Entity\ParticipantMail\ParticipantMail;
use OswisOrg\OswisCalendarBundle\Repository\Participant\ParticipantRepository;
use OswisOrg\OswisCalendarBundle\Service\Communication\CommunicationTimelineService;
use OswisOrg\OswisCalendarBundle\Service\Participant\ParticipantMailService;
use OswisOrg\OswisCalendarBundle\Service\Participant\ParticipantService;
use OswisOrg\OswisAddressBookBundle\Entity\AbstractClass\AbstractContact;
use OswisOrg\OswisAddressBookBundle\Entity\Person;
use OswisOrg\OswisCoreBundle\Exceptions\OswisException;
use OswisOrg\OswisCoreBundle\Interfaces\AddressBook\ContactInterface;
use Doctrine\ORM\EntityManagerInterface;
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
        private readonly EntityManagerInterface $em,
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
            $this->participantMailService->resend($mail);
            $this->addFlash('success', sprintf(
                'E-mail "%s" znovu odeslán účastníkovi #%d.',
                $mail->getSubject() ?? $mail->getType() ?? 'systémový',
                $participantId,
            ));
        } catch (\Throwable $e) {
            $this->addFlash('error', sprintf('Re-send selhal: %s', $e->getMessage()));
        }

        return new RedirectResponse($this->generateUrl(
            'oswis_org_oswis_calendar_web_admin_participant_detail',
            ['participantId' => $participantId, '_fragment' => 'komunikace'],
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

        // Only ParticipantMail (system + ad-hoc) rows are resend-able.
        // IMAP-imported and manual-note rows have no underlying mailer.
        $resendableMailIds = [];
        foreach ($entries as $entry) {
            if ($entry instanceof ParticipantMail && $entry->getId() !== null) {
                $resendableMailIds[$entry->getId()] = true;
            }
        }

        return $this->render('@OswisOrgOswisCalendar/web_admin/participant.html.twig', [
            'participant'        => $participant,
            'entries'            => $entries,
            'isAdmin'            => true,
            'showFullDetail'     => true,
            'participantId'      => $participantId,
            'resendableMailIds'  => $resendableMailIds,
            'page_title'         => sprintf('Přihláška #%d', $participantId),
            'pageTitle'          => sprintf('Přihláška #%d', $participantId),
        ]);
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
        $this->participantService->sendAutoMails(null, $type, $limit);

        // Admin message skeleton (keeps the admin menu) — not the public message page.
        return $this->render('@OswisOrgOswisCore/web_admin/message.html.twig', [
            'title'     => 'Akce provedena.',
            'pageTitle' => 'Akce provedena.',
            'message'   => 'E-maily rozeslány.',
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
     */
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
        // Lightweight load (NOT the full detail graph): the full graph hydration mutates
        // getName()/sortableName on L2-cached entities, and a subsequent em->flush() would then
        // compute a changeset over the whole graph and exhaust memory. We persist this single
        // scalar with a targeted DQL UPDATE + L2 eviction instead — no flush of the UoW.
        $participant = $this->participantService->getParticipant(
            [
                ParticipantRepository::CRITERIA_ID              => $participantId,
                ParticipantRepository::CRITERIA_INCLUDE_DELETED => true,
            ],
            false,
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
     * Resolve a safe post-action redirect target: the `return` form field if it is a
     * same-host admin (/web_admin/...) path, otherwise the participant detail page.
     * Never trusts an absolute/off-site URL (open-redirect guard).
     */
    private function safeListRedirect(Request $request, int $participantId): string
    {
        $return = (string) $request->request->get('return', '');
        if (str_starts_with($return, '/web_admin/') && !str_contains($return, "\n") && !str_contains($return, "\r")) {
            return $return;
        }

        return $this->generateUrl(
            'oswis_org_oswis_calendar_web_admin_participant_arrival',
            ['participantId' => $participantId, 'arrival' => '0'],
        );
    }
}
