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
    ) {
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
     * @param list<int> $ids
     *
     * @return FormInterface<mixed>
     */
    private function bulkForm(array $ids): FormInterface
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

        return $this->createForm(BulkMailType::class, [
            'idsCsv'   => implode(',', $ids),
            'mailMode' => BulkMailType::MODE_BODY,
        ], [
            'action'    => $this->generateUrl('oswis_org_oswis_calendar_web_admin_bulk_mail_queue'),
            'campaigns' => $campaigns,
            'preview'   => [
                'url'           => $this->generateUrl('oswis_org_oswis_calendar_web_admin_message_preview'),
                'valuesUrl'     => $this->generateUrl('oswis_org_oswis_calendar_web_admin_message_values'),
                'audienceUrl'   => $this->generateUrl('oswis_org_oswis_calendar_web_admin_message_audience'),
                'recipients'    => $recipients,
                'subjectField'  => 'bulk_mail_subject',
                'templateField' => 'bulk_mail_templateSlug',
            ],
        ]);
    }

    /**
     * Formulář hromadné zprávy — i po neúspěšném zařazení, s tím, co autor napsal, a s výsledkem kontroly.
     *
     * @param list<int>            $ids
     * @param FormInterface<mixed> $form
     * @param Participant|null     $participant přihláška, ze které se píše „Nová zpráva" (jeden příjemce)
     */
    private function renderCompose(array $ids, FormInterface $form, ?MailValidationResult $validation = null, ?Participant $participant = null): Response
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
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
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
        // Chyba (i neplatný CSRF) = formulář zpátky i s tím, co autor napsal, a hláškou u pole.
        if (!$form->isValid()) {
            return $this->renderCompose($ids, $form);
        }
        /** @var array{subject: string, mailMode: string, templateSlug: ?string, body: ?string, sendAt: ?\DateTimeImmutable} $data */
        $data = $form->getData();
        $kampan = BulkMailType::MODE_TEMPLATE === $data['mailMode'];
        $mail = new ParticipantManualMail(
            trim($data['subject']),
            $kampan ? '' : (string) $data['body'],
            $kampan ? $data['templateSlug'] : null,
            $this->adminName(),
        );
        // Kontrola VŠECH příjemců — s chybou zprávu nejde zařadit, s varováním až po potvrzení autora
        // (dřív se varování ukázalo až po zařazení, kdy už mail odcházel — 13. 9. 2026).
        $validation = $this->bulkMailService->validate($mail, $ids);
        if (!$validation->isConfirmedBy($request->request->getString('confirmWarnings'))) {
            return $this->renderCompose($ids, $form, $validation);
        }
        try {
            $bulk = $this->bulkMailService->queue($mail, $ids, $validation, $data['sendAt']);
        } catch (OswisException $exception) {
            $this->addFlash('danger', 'Zprávu nejde zařadit: '.$exception->getMessage());

            return $this->renderCompose($ids, $form, $validation);
        }
        $zacatek = $bulk->getSendAfter();
        $this->addFlash('success', null !== $data['sendAt'] && null !== $zacatek
            ? sprintf('Hromadný e-mail pro %d příjemců je naplánovaný na %s. Do té doby ho tady jde zrušit.', count($ids), $zacatek->format('j. n. Y H:i'))
            : sprintf('Hromadný e-mail pro %d příjemců se začne odesílat za %d sekund — do té doby ho tady jde zrušit.', count($ids), ParticipantMailBulk::ODKLAD_SEKUND));

        return $this->redirectToRoute('oswis_org_oswis_calendar_web_admin_bulk_mail_status', ['highlight' => $bulk->getId()]);
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
