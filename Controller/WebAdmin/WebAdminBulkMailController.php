<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Controller\WebAdmin;

use Doctrine\ORM\EntityManagerInterface;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Entity\ParticipantMail\ParticipantMailBulk;
use OswisOrg\OswisCalendarBundle\Form\WebAdmin\BulkMailType;
use OswisOrg\OswisCalendarBundle\Repository\Participant\ParticipantMailBulkRepository;
use OswisOrg\OswisCalendarBundle\Repository\Participant\ParticipantRepository;
use OswisOrg\OswisCalendarBundle\Service\Participant\ParticipantBulkMailService;
use OswisOrg\OswisCalendarBundle\Service\Participant\ParticipantManualMail;
use OswisOrg\OswisCalendarBundle\Service\Participant\ParticipantManualMailer;
use OswisOrg\OswisCoreBundle\Entity\AppUser\AppUser;
use OswisOrg\OswisCoreBundle\Entity\MailAttachment\MailAttachment;
use OswisOrg\OswisCoreBundle\Entity\MailTestInbox\MailTestInbox;
use OswisOrg\OswisCoreBundle\Mail\Attachment\MailAttachmentStore;
use OswisOrg\OswisCoreBundle\Entity\TwigTemplate\TwigTemplate;
use OswisOrg\OswisCoreBundle\Exceptions\OswisException;
use OswisOrg\OswisCoreBundle\Mail\Quota\MailDailyQuota;
use OswisOrg\OswisCoreBundle\Mail\Validation\MailValidationResult;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Ad-hoc bulk e-mail to selected participants (Fáze G, Inkrement 2a). Recipients come from the
 * participant list selection (checkboxes / select-all over a scoped + flag-faceted view — so
 * "send to faculty / accommodation X" = filter the list by that flag, select all, compose here).
 * Real mail → cap + preview + confirmation + durable outbox drained in batches (JS auto-drain now,
 * cron later). {@see ParticipantBulkMailService}.
 */
#[IsGranted('ROLE_ADMIN')]
final class WebAdminBulkMailController extends AbstractController
{
    use ManualMailRequestTrait;

    /** Hard ceiling on recipients per bulk (real mail; mirrors the export cap rationale). */
    private const int MAX_RECIPIENTS = 1000;

    public function __construct(
        private readonly ParticipantBulkMailService $bulkMailService,
        private readonly ParticipantRepository $participantRepository,
        private readonly ParticipantMailBulkRepository $bulkRepository,
        private readonly EntityManagerInterface $em,
        private readonly MailDailyQuota $quota,
        private readonly ParticipantManualMailer $manualMailer,
    ) {
    }

    /**
     * Kam smí odejít zkouška sobě (spec §5.3 a): adresa přihlášeného správce a zkušební schránky z Nastavení.
     *
     * @return array<string, string> popisek => adresa
     */
    private function zkusebniAdresy(): array
    {
        $adresy = [];
        $user = $this->getUser();
        if ($user instanceof AppUser && '' !== ($email = mb_strtolower(trim($user->getEmail())))) {
            $adresy[sprintf('%s (moje adresa)', $email)] = $email;
        }
        foreach ($this->em->getRepository(MailTestInbox::class)->findBy([], ['email' => 'ASC']) as $inbox) {
            if (!in_array($inbox->getEmail(), $adresy, true)) {
                $adresy[null !== $inbox->getNote() ? sprintf('%s (%s)', $inbox->getEmail(), $inbox->getNote()) : $inbox->getEmail()] = $inbox->getEmail();
            }
        }

        return $adresy;
    }

    /**
     * Denní limit pro stránky hromadných mailů (dávka 3.1).
     *
     * @return array{sent: int, remaining: ?int, bulk: int, hard: int}
     */
    private function denniLimit(): array
    {
        return [
            'sent'      => $this->quota->sentToday(),
            'remaining' => $this->quota->remainingForBulk(),
            'bulk'      => $this->quota->bulkLimit(),
            'hard'      => $this->quota->hardLimit(),
        ];
    }

    /**
     * Stored campaign templates offered in the composer for the "send a whole stored mail" mode.
     * (Bloky k vložení do vlastního textu jsou v katalogu jako `{{ blok('…') }}`, spec 2026-09-13 §3.4.)
     *
     * @return list<TwigTemplate>
     */
    private function campaignTemplates(): array
    {
        return $this->em->getRepository(TwigTemplate::class)->findBy(['kind' => TwigTemplate::KIND_CAMPAIGN], ['name' => 'ASC']);
    }

    /** Step 1: open the compose form for the selected recipients (POSTed from the list bulk bar). */
    public function compose(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('bulk_mail', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Neplatný CSRF token.');
        }
        $ids = $this->readIds($request);
        if (null !== ($presmerovani = $this->prilisMaloNeboMoc($ids))) {
            return $presmerovani;
        }

        return $this->renderCompose($ids, $this->bulkForm($ids));
    }

    /**
     * „Nová zpráva" jedné přihlášce (z detailu přihlášky) — TENTÝŽ formulář a fronta jako hromadný e-mail
     * (1 = N s N = 1; rozhodnutí uživatele 1. 10. 2026): 30 s na zrušení, plánování, uložená kampaň, denní limit,
     * kontrola. Dřív zvláštní cesta (`WebAdminAdHocMailController`), která posílala hned a nic z toho neuměla.
     */
    public function composeOne(int $participantId): Response
    {
        $participant = $this->participantRepository->find($participantId);
        if (!$participant instanceof Participant) {
            throw $this->createNotFoundException('Přihláška nenalezena.');
        }

        return $this->renderCompose([$participantId], $this->bulkForm([$participantId]), participant: $participant);
    }

    /**
     * Formulář hromadné zprávy ({@see BulkMailType}) nad danými příjemci. Náhled vedle textu nabízí každého z nich.
     *
     * @param list<int>                 $ids
     * @param array<string, mixed>|null $data výchozí obsah (otevřený koncept, nebo text vrácený po souběhu)
     *
     * @return FormInterface<mixed>
     */
    private function bulkForm(array $ids, ?array $data = null): FormInterface
    {
        $campaigns = [];
        foreach ($this->campaignTemplates() as $template) {
            if ('' !== ($slug = $template->getSlug())) {
                $campaigns[sprintf('%s (%s)', $template->getName() ?? $slug, $slug)] = $slug;
            }
        }
        $recipients = [];
        foreach ($this->participantRepository->findByIds($ids) as $participant) {
            $recipients[] = ['id' => $participant->getId(), 'label' => trim('#'.$participant->getId().' '.($participant->getContact()?->getName() ?? ''))];
        }

        return $this->createForm(BulkMailType::class, ['idsCsv' => implode(',', $ids), 'mailMode' => BulkMailType::MODE_BODY] + ($data ?? []), [
            'action'        => $this->generateUrl('oswis_org_oswis_calendar_web_admin_bulk_mail_queue'),
            'campaigns'     => $campaigns,
            'testAddresses' => $this->zkusebniAdresy(),
            'preview'   => [
                'url'           => $this->generateUrl('oswis_org_oswis_calendar_web_admin_message_preview'),
                'valuesUrl'     => $this->generateUrl('oswis_org_oswis_calendar_web_admin_message_values'),
                'audienceUrl'   => $this->generateUrl('oswis_org_oswis_calendar_web_admin_message_audience'),
                'recipients'    => $recipients,
                'subjectField'     => 'bulk_mail_subject',
                'templateField'    => 'bulk_mail_templateSlug',
                'attachmentsField' => 'bulk_mail_prilohy',
            ],
        ]);
    }

    /**
     * Formulář hromadné zprávy — i po neúspěšném zařazení, s tím, co autor napsal, a s výsledkem kontroly.
     *
     * @param list<int>            $ids
     * @param FormInterface<mixed> $form
     * @param Participant|null     $participant přihláška, ze které se píše „Nová zpráva" (jeden příjemce)
     * @param array{zprav: int, prihlasek: int, vypadnou: list<array{id: int, popis: string, duvod: string}>}|null $kontrola panel kontroly před odesláním
     */
    private function renderCompose(array $ids, FormInterface $form, ?MailValidationResult $validation = null, ?Participant $participant = null, ?array $kontrola = null, ?int $status = null, ?ParticipantMailBulk $koncept = null): Response
    {
        if (null === $participant && 1 === count($ids)) {
            // Návrat formuláře po chybě: pořád jde o zprávu jedné přihlášce (odkaz zpět, nadpis).
            $participant = $this->participantRepository->find($ids[0]);
            $participant = $participant instanceof Participant ? $participant : null;
        }
        return $this->render('@OswisOrgOswisCalendar/web_admin/bulk_mail/compose.html.twig', [
            'title'          => (null === $participant ? 'Hromadný e-mail' : sprintf('Nová zpráva účastníkovi #%d', $participant->getId() ?? 0)).' :: ADMIN',
            'pageTitle'      => null === $participant ? 'Hromadný e-mail' : sprintf('Nová zpráva účastníkovi #%d', $participant->getId() ?? 0),
            'participant'    => $participant,
            'recipientCount' => count($ids),
            'recipients'     => $this->participantRepository->findByIds($ids),
            'form'           => $form,
            'validation'     => $validation,
            'limit'          => $this->denniLimit(),
            'kontrola'       => $kontrola,
            'koncept'        => $koncept ?? $this->konceptZFormulare($form),
            'prilohy'        => $this->prilohyZFormulare($form, $ids),
            'prilohyMax'     => ['soubor' => MailAttachmentStore::MAX_SOUBOR, 'celkem' => MailAttachmentStore::MAX_CELKEM],
        ], new Response(status: $status ?? ($form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK)));
    }

    /** Step 2: queue the bulk (snapshot of recipients). Sends nothing; the drain does. */
    public function queue(Request $request): Response
    {
        $idsCsv = $request->request->all('bulk_mail')['idsCsv'] ?? '';
        $ids = self::parseIds(is_string($idsCsv) ? explode(',', $idsCsv) : []);
        if (null !== ($presmerovani = $this->prilisMaloNeboMoc($ids))) {
            return $presmerovani;
        }
        $form = $this->bulkForm($ids);
        $form->handleRequest($request);
        if (!$form->isSubmitted()) {
            return $this->redirectToRoute('oswis_org_oswis_calendar_web_admin_participants_list');
        }
        $autosave = $request->request->has('autosave');
        // Chyba (i neplatný CSRF) = formulář zpátky i s tím, co autor napsal, a hláškou u pole.
        if (!$form->isValid()) {
            return $autosave
                ? new JsonResponse(['error' => 'invalid'], Response::HTTP_UNPROCESSABLE_ENTITY)
                : $this->renderCompose($ids, $form);
        }
        /** @var array{subject: ?string, mailMode: ?string, templateSlug: ?string, body: ?string, sendAt: ?\DateTimeImmutable, konceptId: ?string, konceptRevize: ?string, prilohy: ?string} $data */
        $data = $form->getData();
        $kampan = BulkMailType::MODE_TEMPLATE === $data['mailMode'];
        $koncept = self::konceptZDat($data);
        if (BulkMailType::ukladaKoncept($form)) {
            return $this->ulozitKoncept($ids, $data, $koncept, $autosave);
        }
        if ($autosave) {
            // Automatické ukládání smí jen ukládat koncept — nikdy nic neodeslat ani nezařadit.
            return new JsonResponse(['error' => 'invalid'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if (BulkMailType::posilaZkousku($form)) {
            return $this->poslatZkousku($ids, $data, $koncept, $form);
        }
        $mail = $this->zpravaZFormulare($ids, $data);
        // Kontrola VŠECH příjemců — s chybou zprávu nejde zařadit, s varováním až po potvrzení autora
        // (dřív se varování ukázalo až po zařazení, kdy už mail odcházel — 13. 9. 2026).
        $validation = $this->bulkMailService->validate($mail, $ids);
        // Kontrola před odesláním (spec §5.2): první odeslání formuláře jen ukáže panel kontroly (kolik zpráv,
        // kdo vypadne a proč, limit, výsledek kontroly textu); zařadí až tlačítko „Odeslat" z panelu (`odeslat`).
        // Text se mezitím dá upravit — každé odeslání se kontroluje znovu.
        $prehled = $this->bulkMailService->prehledPrijemcu($ids);
        if ($validation->hasErrors() || 0 === $prehled['zprav']) {
            return $this->renderCompose($ids, $form, $validation, kontrola: $prehled);
        }
        if (!$request->request->has('odeslat') || !$validation->isConfirmedBy($request->request->getString('confirmWarnings'))) {
            return $this->renderCompose($ids, $form, $validation, kontrola: $prehled, status: $request->request->has('odeslat') ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK);
        }
        try {
            $bulk = $this->bulkMailService->queue($mail, $ids, $validation, $data['sendAt'], $koncept);
        } catch (OswisException $exception) {
            $this->addFlash('danger', 'Zprávu nejde zařadit: '.$exception->getMessage());
            // Koncept se mezitím změnil / odeslal: text zůstane, ale už bez vazby na koncept (uloží se jako nový).
            $form = null !== $koncept ? $this->bulkForm($ids, ['konceptId' => null, 'konceptRevize' => null] + $data) : $form;

            return $this->renderCompose($ids, $form, $validation, status: Response::HTTP_CONFLICT);
        }
        $zacatek = $bulk->getSendAfter();
        $this->addFlash('success', null !== $data['sendAt'] && null !== $zacatek
            ? sprintf('Zpráva (%d zpráv pro %d přihlášek) je naplánovaná na %s. Do té doby ji tady jde zrušit.', $prehled['zprav'], $prehled['prihlasek'], $zacatek->format('j. n. Y H:i'))
            : sprintf('Zpráva (%d zpráv pro %d přihlášek) se začne odesílat za %d sekund — do té doby ji tady jde zrušit.', $prehled['zprav'], $prehled['prihlasek'], ParticipantMailBulk::ODKLAD_SEKUND));

        return $this->redirectToRoute('oswis_org_oswis_calendar_web_admin_bulk_mail_status', ['highlight' => $bulk->getId()]);
    }

    /**
     * Zpráva z odeslaného formuláře. `$sTextem` = i v režimu kampaně ponechat napsaný text (ukládání konceptu — přepnutím
     * zpět se nesmí ztratit); jinak se v režimu kampaně text neposílá. Jednomu příjemci jdou všechny soubory jako příloha.
     *
     * @param list<int>    $ids
     * @param array<mixed> $data
     */
    private function zpravaZFormulare(array $ids, array $data, bool $sTextem = false): ParticipantManualMail
    {
        $kampan = BulkMailType::MODE_TEMPLATE === ($data['mailMode'] ?? null);
        $slug = $data['templateSlug'] ?? null;
        $mail = new ParticipantManualMail(
            trim(is_string($data['subject'] ?? null) ? $data['subject'] : ''),
            $kampan && !$sTextem ? '' : (is_string($data['body'] ?? null) ? $data['body'] : ''),
            $kampan && is_string($slug) ? $slug : null,
            $this->adminName(),
            attachments: ParticipantManualMail::prilohyZJson(is_string($data['prilohy'] ?? null) ? $data['prilohy'] : null),
        );

        return 1 === count($ids) ? $mail->vseJakoPriloha() : $mail;
    }

    /**
     * Uložit rozepsanou zprávu jako koncept (dávka 3.3) — i neúplnou; nic se neodesílá. Uloží se i text vlastní zprávy
     * v režimu kampaně, aby se přepnutím zpět neztratil.
     *
     * @param list<int>                                                                                                                                 $ids
     * @param array{subject: ?string, mailMode: ?string, templateSlug: ?string, body: ?string, sendAt: ?\DateTimeImmutable, konceptId: ?string, konceptRevize: ?string, prilohy: ?string} $data
     * @param array{id: int, revision: int}|null                                                                                                          $koncept
     * @param bool                                                                                                                                         $autosave průběžné ukládání ze stránky (JSON místo přesměrování)
     */
    private function ulozitKoncept(array $ids, array $data, ?array $koncept, bool $autosave = false): Response
    {
        $mail = $this->zpravaZFormulare($ids, $data, sTextem: true);
        $bulk = $this->bulkMailService->saveDraft($mail, $ids, $data['sendAt'], $koncept);
        if ($autosave) {
            return null === $bulk
                ? new JsonResponse(['error' => 'conflict', 'message' => ParticipantBulkMailService::KONCEPT_ZMENEN], Response::HTTP_CONFLICT)
                : new JsonResponse(['id' => $bulk->getId(), 'revision' => $bulk->getRevision(), 'savedAt' => $bulk->getUpdatedAt()?->format(\DATE_ATOM)]);
        }
        if (null === $bulk) {
            $this->addFlash('danger', ParticipantBulkMailService::KONCEPT_ZMENEN);

            return $this->renderCompose($ids, $this->bulkForm($ids, ['konceptId' => null, 'konceptRevize' => null] + $data), status: Response::HTTP_CONFLICT);
        }
        $this->addFlash('success', 'Koncept uložen. Najdeš ho i na stránce „Hromadné e-maily" mezi rozepsanými.');

        return $this->redirectToRoute('oswis_org_oswis_calendar_web_admin_bulk_mail_draft', ['id' => $bulk->getId()]);
    }

    /**
     * Zkouška sobě (dávka 3.4, spec §5.3 a): zprávu nejdřív uloží jako koncept (zkouška se zaznamená u něj a kontrola
     * před odesláním ji ukáže), zkontroluje ji pro příjemce z náhledu a pošle mu vykreslenou verzi s „[ZKOUŠKA]" na
     * zaškrtnuté adresy — jen z povolených (správce + zkušební schránky). Nic se nezařadí ani nezapíše k přihlášce.
     *
     * @param list<int>                                                                                                                                 $ids
     * @param array{subject: ?string, mailMode: ?string, templateSlug: ?string, body: ?string, sendAt: ?\DateTimeImmutable, konceptId: ?string, konceptRevize: ?string, prilohy: ?string} $data
     * @param array{id: int, revision: int}|null                                                                                                          $koncept
     * @param FormInterface<mixed>                                                                                                                       $form
     */
    private function poslatZkousku(array $ids, array $data, ?array $koncept, FormInterface $form): Response
    {
        $povolene = $this->zkusebniAdresy();
        $vybrane = $form->get('zkouskaAdresy')->getData();
        $adresy = array_values(array_intersect(is_array($vybrane) ? array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $vybrane) : [], $povolene));
        if ([] === $adresy) {
            $this->addFlash('danger', 'Zkouška nemá kam odejít — zaškrtni svou adresu nebo zkušební schránku.');

            return $this->renderCompose($ids, $form);
        }
        $pro = $form->get('zkouskaPro')->getData();
        $participantId = is_numeric($pro) && in_array((int) $pro, $ids, true) ? (int) $pro : $ids[0];
        $participant = $this->participantRepository->find($participantId);
        if (!$participant instanceof Participant) {
            $this->addFlash('danger', sprintf('Přihláška #%d, pro kterou se má zkouška vykreslit, neexistuje.', $participantId));

            return $this->renderCompose($ids, $form);
        }
        $mail = $this->zpravaZFormulare($ids, $data);
        // Chyba v textu = zkouška neodejde (stejná kontrola jako odeslání, jen pro vybraného příjemce).
        $validation = $this->bulkMailService->validate($mail, [$participantId]);
        if ($validation->hasErrors()) {
            return $this->renderCompose($ids, $form, $validation);
        }
        $ulozeni = $this->zpravaZFormulare($ids, $data, sTextem: true);
        $bulk = $this->bulkMailService->saveDraft($ulozeni, $ids, $data['sendAt'], $koncept);
        if (null === $bulk) {
            $this->addFlash('danger', ParticipantBulkMailService::KONCEPT_ZMENEN.' Zkouška neodešla.');

            return $this->renderCompose($ids, $this->bulkForm($ids, ['konceptId' => null, 'konceptRevize' => null] + $data), status: Response::HTTP_CONFLICT);
        }
        $chyby = [];
        $odeslano = 0;
        foreach ($adresy as $adresa) {
            $chyba = $this->manualMailer->sendTest($mail, $participant, $adresa);
            if (null === $chyba) {
                ++$odeslano;
            } else {
                $chyby[] = sprintf('%s: %s', $adresa, $chyba);
            }
        }
        $bulk->recordTest(new \DateTimeImmutable(), $this->adminName(), $adresy, $participantId, $odeslano, $chyby);
        $this->em->flush();
        if ([] === $chyby) {
            $this->addFlash('success', sprintf('Zkouška (vykreslená pro #%d) odešla na %s. Zpráva je uložená jako koncept.', $participantId, implode(', ', $adresy)));
        } else {
            $this->addFlash('danger', sprintf('Zkouška neodešla všude (%d z %d): %s', $odeslano, count($adresy), implode(' | ', $chyby)));
        }

        return $this->redirectToRoute('oswis_org_oswis_calendar_web_admin_bulk_mail_draft', ['id' => $bulk->getId()]);
    }

    /** Otevřít rozepsaný koncept (dávka 3.3). Odeslaný / smazaný → stránka stavu s vysvětlením. */
    public function draft(int $id): Response
    {
        $bulk = $this->bulkRepository->find($id);
        if (!$bulk instanceof ParticipantMailBulk || !$bulk->isDraft()) {
            $this->addFlash('warning', sprintf('Koncept #%d už neexistuje — byl odeslán nebo smazán.', $id));

            return $this->redirectToRoute('oswis_org_oswis_calendar_web_admin_bulk_mail_status', ['highlight' => $id]);
        }
        $ids = array_values(array_filter($bulk->getParticipantIds(), static fn (int $pid): bool => $pid > 0));
        $form = $this->bulkForm($ids, [
            'subject'       => $bulk->getSubject(),
            'mailMode'      => $bulk->hasTemplate() ? BulkMailType::MODE_TEMPLATE : BulkMailType::MODE_BODY,
            'templateSlug'  => $bulk->getTemplateSlug(),
            'body'          => $bulk->getBodyHtml(),
            'sendAt'        => $bulk->getSendAfter(),
            'konceptId'     => (string) $bulk->getId(),
            'konceptRevize' => (string) $bulk->getRevision(),
            'prilohy'       => [] === $bulk->getAttachments() ? '' : (string) json_encode($bulk->getAttachments()),
        ]);

        return $this->renderCompose($ids, $form, koncept: $bulk);
    }

    /** Smazat koncept (POST, CSRF). Jen dokud je to koncept — odeslaný či zařazený zůstane. */
    public function deleteDraft(Request $request, int $id): Response
    {
        if (!$this->isCsrfTokenValid('bulk_mail_draft_delete_'.$id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Neplatný CSRF token.');
        }
        // Jedním příkazem a jen koncept: souběžné „Odeslat" z jiného okna se nesmaže.
        $smazano = $this->em->getConnection()->executeStatement(
            'DELETE FROM calendar_participant_mail_bulk WHERE id = ? AND status = ?',
            [$id, ParticipantMailBulk::STATUS_DRAFT],
        );
        $this->addFlash(1 === $smazano ? 'success' : 'warning', 1 === $smazano
            ? sprintf('Koncept #%d smazán.', $id)
            : sprintf('Koncept #%d už neexistuje nebo byl odeslán — nic se nesmazalo.', $id));

        return $this->redirectToRoute('oswis_org_oswis_calendar_web_admin_bulk_mail_status');
    }

    /**
     * Koncept z odeslaného formuláře: ID a revize, ze které autor vycházel; bez nich null (nová zpráva).
     *
     * @param array<mixed> $data
     *
     * @return array{id: int, revision: int}|null
     */
    private static function konceptZDat(array $data): ?array
    {
        $id = $data['konceptId'] ?? null;
        $revize = $data['konceptRevize'] ?? null;
        if (!is_numeric($id) || (int) $id <= 0 || !is_numeric($revize)) {
            return null;
        }

        return ['id' => (int) $id, 'revision' => (int) $revize];
    }

    /**
     * Přílohy z formuláře pro seznam na stránce a kontrolu před odesláním (název, velikost, způsob, stažení správcem).
     *
     * @param FormInterface<mixed> $form
     * @param list<int>            $ids
     *
     * @return list<array{id: int, name: string, size: int, sizeLabel: string, mode: string}>
     */
    private function prilohyZFormulare(FormInterface $form, array $ids): array
    {
        $data = $form->getData();
        $mail = $this->zpravaZFormulare($ids, is_array($data) ? $data : []);
        $popis = [];
        foreach ($this->manualMailer->prilohy($mail)['popis'] as $priloha) {
            $popis[] = $priloha + ['sizeLabel' => MailAttachment::velikost($priloha['size'])];
        }

        return $popis;
    }

    /**
     * Koncept, ke kterému formulář patří (nadpis „Koncept #…") — jen dokud je to pořád koncept.
     *
     * @param FormInterface<mixed> $form
     */
    private function konceptZFormulare(FormInterface $form): ?ParticipantMailBulk
    {
        $data = $form->getData();
        $koncept = is_array($data) ? self::konceptZDat($data) : null;
        $bulk = null !== $koncept ? $this->bulkRepository->find($koncept['id']) : null;

        return $bulk instanceof ParticipantMailBulk && $bulk->isDraft() ? $bulk : null;
    }

    /**
     * Bez příjemců nebo nad strop → zpět na seznam přihlášek s hláškou; jinak null.
     *
     * @param list<int> $ids
     */
    private function prilisMaloNeboMoc(array $ids): ?Response
    {
        if ([] === $ids) {
            $this->addFlash('warning', 'Nebyli vybráni žádní příjemci.');

            return $this->redirectToRoute('oswis_org_oswis_calendar_web_admin_participants_list');
        }
        if (count($ids) > self::MAX_RECIPIENTS) {
            $this->addFlash('danger', sprintf(
                'Hromadný e-mail je omezen na %d příjemců, vybráno %d. Zužte výběr.',
                self::MAX_RECIPIENTS,
                count($ids),
            ));

            return $this->redirectToRoute('oswis_org_oswis_calendar_web_admin_participants_list');
        }

        return null;
    }

    /** Status page: list bulks + progress; JS on the page auto-drains pending ones in batches. */
    public function status(Request $request): Response
    {
        return $this->render('@OswisOrgOswisCalendar/web_admin/bulk_mail/status.html.twig', [
            'title'     => 'Hromadné e-maily :: ADMIN',
            'pageTitle' => 'Hromadné e-maily',
            'bulks'     => $this->bulkRepository->findRecent(30),
            'drafts'    => $this->bulkRepository->findDrafts(),
            'highlight' => $request->query->getInt('highlight'),
            'now'       => new \DateTimeImmutable(),
            'limit'     => $this->denniLimit(),
        ]);
    }

    /**
     * Zrušit rozesílku před spuštěním, nebo zastavit zbytek během odesílání (POST, CSRF) — dávka 3.2.
     * Odeslané zůstává odeslané; rozesílka se kontroluje před každým příjemcem, takže zastavení platí hned.
     */
    public function cancel(Request $request, int $id): Response
    {
        if (!$this->isCsrfTokenValid('bulk_mail_cancel_'.$id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Neplatný CSRF token.');
        }
        $bulk = $this->bulkRepository->find($id);
        if (!$bulk instanceof ParticipantMailBulk) {
            throw $this->createNotFoundException('Hromadný e-mail nenalezen.');
        }
        if ($bulk->isFinished()) {
            $this->addFlash('warning', sprintf('Hromadný e-mail #%d už je %s — nebylo co rušit.', $id, $bulk->isDone() ? 'odeslaný' : 'zrušený'));
        } else {
            $zacal = $bulk->getProcessedCount() > 0;
            $bulk->cancel($this->adminName());
            $this->em->flush();
            $this->addFlash('success', $zacal
                ? sprintf('Hromadný e-mail #%d zastaven — odešel %d z %d příjemců, zbytek se neodešle.', $id, $bulk->getProcessedCount(), $bulk->getTotalCount())
                : sprintf('Hromadný e-mail #%d zrušen — neodešel nikomu.', $id));
        }

        return $this->redirectToRoute('oswis_org_oswis_calendar_web_admin_bulk_mail_status', ['highlight' => $id]);
    }

    /** Drain one batch of a bulk (POST, CSRF) → JSON progress. Used by the status-page auto-drain. */
    public function drain(Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('bulk_mail_drain', (string) $request->request->get('_token'))) {
            return new JsonResponse(['error' => 'csrf'], Response::HTTP_FORBIDDEN);
        }
        $bulkId = $request->request->getInt('bulkId');
        $bulk = $bulkId > 0 ? $this->bulkRepository->find($bulkId) : null;
        if (!$bulk instanceof ParticipantMailBulk) {
            return new JsonResponse(['error' => 'not-found'], Response::HTTP_NOT_FOUND);
        }
        $progress = $this->bulkMailService->drainBatch($bulk, 15);

        return new JsonResponse(['bulkId' => $bulkId] + $progress);
    }

    /**
     * Recipient participant IDs from the list bulk bar (ids[] or comma-joined idsCsv).
     *
     * @return list<int>
     */
    private function readIds(Request $request): array
    {
        $raw = $request->request->all('ids');
        if ([] === $raw) {
            $csv = (string) $request->request->get('idsCsv', '');
            $raw = '' === $csv ? [] : explode(',', $csv);
        }

        return self::parseIds($raw);
    }

    /**
     * Positive unique ints only.
     *
     * @param array<mixed> $raw
     *
     * @return list<int>
     */
    private static function parseIds(array $raw): array
    {
        $ids = [];
        foreach ($raw as $value) {
            if (is_numeric($value) && (int) $value > 0) {
                $ids[(int) $value] = true; // dedup (int keys)
            }
        }

        return array_keys($ids);
    }
}
