<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Controller\WebAdmin;

use Doctrine\ORM\EntityManagerInterface;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Entity\ParticipantMail\ParticipantMailBulk;
use OswisOrg\OswisCalendarBundle\Repository\Participant\ParticipantMailBulkRepository;
use OswisOrg\OswisCalendarBundle\Repository\Participant\ParticipantRepository;
use OswisOrg\OswisCalendarBundle\Service\Participant\MailPreviewService;
use OswisOrg\OswisCalendarBundle\Service\Participant\ParticipantBulkMailService;
use OswisOrg\OswisCoreBundle\Entity\TwigTemplate\TwigTemplate;
use OswisOrg\OswisCoreBundle\Exceptions\OswisException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
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
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Stored campaign/snippet templates offered in the composer — campaigns for the "send a whole
     * stored mail" mode, snippets for click-to-insert {% include %} into the free body.
     *
     * @return array<string, list<TwigTemplate>>
     */
    private function offerableTemplates(): array
    {
        $repo = $this->em->getRepository(TwigTemplate::class);

        return [
            'campaigns' => $repo->findBy(['kind' => TwigTemplate::KIND_CAMPAIGN], ['name' => 'ASC']),
            'snippets'  => $repo->findBy(['kind' => TwigTemplate::KIND_SNIPPET], ['name' => 'ASC']),
        ];
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

        $templates = $this->offerableTemplates();

        return $this->render('@OswisOrgOswisCalendar/web_admin/bulk_mail/compose.html.twig', [
            'title'          => 'Hromadný e-mail :: ADMIN',
            'pageTitle'      => 'Hromadný e-mail',
            'ids'            => $ids,
            'idsCsv'         => implode(',', $ids),
            'recipientCount' => count($ids),
            'campaigns'      => $templates['campaigns'],
            'snippets'       => $templates['snippets'],
            'variableCatalog' => MailPreviewService::variableCatalog(),
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
        $templateSlug = trim((string) $request->request->get('templateSlug', ''));

        // "Stored template" mode → render the whole campaign/snippet (DatabaseLoader resolves a slug).
        if ('' !== $templateSlug) {
            $result = $this->mailPreview->renderTemplate($templateSlug, $participant, [], $subject);

            return new Response($result['html']);
        }

        // Free-body mode: body is trusted Twig (entity-API variables + conditional blocks) → render +
        // sanitize first, then drop into the ad-hoc wrapper. A body Twig error shows inline, not blank.
        try {
            $renderedBody = $this->mailPreview->renderBodyFragment($body, $participant);
        } catch (\Throwable $exception) {
            $renderedBody = sprintf(
                '<div style="color:#842029;background:#f8d7da;border:1px solid #f5c2c7;padding:.75rem;'
                .'border-radius:.375rem;font-family:monospace;white-space:pre-wrap;">'
                .'<strong>Chyba v těle (Twig):</strong><br>%s</div>',
                htmlspecialchars($exception->getMessage(), ENT_QUOTES),
            );
        }

        $result = $this->mailPreview->renderTemplate(
            ParticipantBulkMailService::AD_HOC_TEMPLATE,
            $participant,
            ['bodyHtml' => $renderedBody, 'adminName' => $this->adminName(), 'type' => 'ad-hoc-bulk-preview'],
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
        $templateSlug = trim((string) $request->request->get('templateSlug', ''));
        // A bulk needs a subject + recipients + either a free body OR a stored template.
        if ([] === $ids || '' === trim($subject) || ('' === trim($body) && '' === $templateSlug)) {
            $this->addFlash('warning', 'Vyplňte předmět, vyberte příjemce a zadejte text nebo uloženou šablonu.');

            return $this->redirectToRoute('oswis_org_oswis_calendar_web_admin_participants_list');
        }
        if (count($ids) > self::MAX_RECIPIENTS) {
            $this->addFlash('danger', sprintf('Příliš mnoho příjemců (max %d).', self::MAX_RECIPIENTS));

            return $this->redirectToRoute('oswis_org_oswis_calendar_web_admin_participants_list');
        }

        // Queue-validation: the service renders the message against the first recipient and rejects a
        // bulk whose Twig won't compile — so a typo never reaches real recipients via the drain.
        try {
            $bulk = $this->bulkMailService->queue($subject, $body, $ids, $this->adminName(), '' !== $templateSlug ? $templateSlug : null);
        } catch (OswisException $exception) {
            $this->addFlash('danger', 'E-mail nelze zařadit – chyba v těle (Twig): '.$exception->getMessage());

            return $this->redirectToRoute('oswis_org_oswis_calendar_web_admin_participants_list');
        }
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
     * @return array{0: string, 1: string} [subject, raw body] — the body is stored verbatim as trusted
     *                                      Twig; it is rendered and HTML-sanitized at send/preview time
     *                                      (see {@see MailPreviewService::renderBodyFragment}), not here.
     */
    private function readMessage(Request $request): array
    {
        return [
            trim((string) $request->request->get('subject', '')),
            (string) $request->request->get('body', ''),
        ];
    }

    private function adminName(): ?string
    {
        $user = $this->getUser();

        return $user instanceof UserInterface ? $user->getUserIdentifier() : null;
    }
}
