<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Controller\WebAdmin;

use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Entity\ParticipantMail\ParticipantMailBulk;
use OswisOrg\OswisCalendarBundle\Repository\Participant\ParticipantMailBulkRepository;
use OswisOrg\OswisCalendarBundle\Repository\Participant\ParticipantRepository;
use OswisOrg\OswisCalendarBundle\Service\Participant\MailPreviewService;
use OswisOrg\OswisCalendarBundle\Service\Participant\ParticipantBulkMailService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\User\UserInterface;
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
    /** Hard ceiling on recipients per bulk (real mail; mirrors the export cap rationale). */
    private const int MAX_RECIPIENTS = 1000;

    public function __construct(
        private readonly ParticipantBulkMailService $bulkMailService,
        private readonly ParticipantRepository $participantRepository,
        private readonly ParticipantMailBulkRepository $bulkRepository,
        private readonly MailPreviewService $mailPreview,
    ) {
    }

    /** Step 1: open the compose form for the selected recipients (POSTed from the list bulk bar). */
    public function compose(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('bulk_mail', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Neplatný CSRF token.');
        }
        $ids = $this->readIds($request);
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

        return $this->render('@OswisOrgOswisCalendar/web_admin/bulk_mail/compose.html.twig', [
            'title'        => 'Hromadný e-mail :: ADMIN',
            'pageTitle'    => 'Hromadný e-mail',
            'ids'          => $ids,
            'idsCsv'       => implode(',', $ids),
            'recipientCount' => count($ids),
        ]);
    }

    /** Live preview: render the ad-hoc template (through MJML) for the first recipient. No send. */
    public function preview(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('bulk_mail_preview', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Neplatný CSRF token.');
        }
        $ids = $this->readIds($request);
        $first = $ids[0] ?? null;
        $participant = null !== $first ? $this->participantRepository->find($first) : null;
        if (!$participant instanceof Participant) {
            return new Response('<p style="font-family:sans-serif;color:#666">Náhled nelze vytvořit – příjemce nenalezen.</p>');
        }
        [$subject, $body] = $this->readMessage($request);

        $result = $this->mailPreview->renderTemplate(
            ParticipantBulkMailService::AD_HOC_TEMPLATE,
            $participant,
            ['bodyHtml' => $body, 'adminName' => $this->adminName(), 'type' => 'ad-hoc-bulk-preview'],
            $subject,
        );

        return new Response($result['html']);
    }

    /** Step 2: queue the bulk (snapshot of recipients). Sends nothing; the drain does. */
    public function queue(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('bulk_mail', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Neplatný CSRF token.');
        }
        $ids = $this->readIds($request);
        [$subject, $body] = $this->readMessage($request);
        if ([] === $ids || '' === trim($subject) || '' === trim($body)) {
            $this->addFlash('warning', 'Vyplňte předmět i text a vyberte příjemce.');

            return $this->redirectToRoute('oswis_org_oswis_calendar_web_admin_participants_list');
        }
        if (count($ids) > self::MAX_RECIPIENTS) {
            $this->addFlash('danger', sprintf('Příliš mnoho příjemců (max %d).', self::MAX_RECIPIENTS));

            return $this->redirectToRoute('oswis_org_oswis_calendar_web_admin_participants_list');
        }

        $bulk = $this->bulkMailService->queue($subject, $body, $ids, $this->adminName());
        $this->addFlash('success', sprintf('Hromadný e-mail zařazen: %d příjemců. Spustí se odesílání.', count($ids)));

        return $this->redirectToRoute('oswis_org_oswis_calendar_web_admin_bulk_mail_status', ['highlight' => $bulk->getId()]);
    }

    /** Status page: list bulks + progress; JS on the page auto-drains pending ones in batches. */
    public function status(Request $request): Response
    {
        return $this->render('@OswisOrgOswisCalendar/web_admin/bulk_mail/status.html.twig', [
            'title'     => 'Hromadné e-maily :: ADMIN',
            'pageTitle' => 'Hromadné e-maily',
            'bulks'     => $this->bulkRepository->findRecent(30),
            'highlight' => $request->query->getInt('highlight'),
        ]);
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
     * Parse recipient participant IDs from the request (ids[] or comma-joined idsCsv), positive
     * unique ints only.
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
        $ids = [];
        foreach ($raw as $value) {
            if (is_numeric($value) && (int) $value > 0) {
                $ids[(int) $value] = true; // dedup (int keys)
            }
        }

        return array_keys($ids);
    }

    /**
     * @return array{0: string, 1: string} sanitized [subject, bodyHtml]
     */
    private function readMessage(Request $request): array
    {
        $subject = trim((string) $request->request->get('subject', ''));
        $rawBody = (string) $request->request->get('body', '');
        $sanitizer = new HtmlSanitizer(
            (new HtmlSanitizerConfig())
                ->allowSafeElements()
                ->allowLinkSchemes(['http', 'https', 'mailto', 'tel'])
                ->allowRelativeLinks(false)
                ->allowRelativeMedias(false),
        );

        return [$subject, $sanitizer->sanitize($rawBody)];
    }

    private function adminName(): ?string
    {
        $user = $this->getUser();

        return $user instanceof UserInterface ? $user->getUserIdentifier() : null;
    }
}
