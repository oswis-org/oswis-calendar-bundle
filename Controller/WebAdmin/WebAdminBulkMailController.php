<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Controller\WebAdmin;

use Doctrine\ORM\EntityManagerInterface;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Entity\ParticipantMail\ParticipantMailBulk;
use OswisOrg\OswisCalendarBundle\Repository\Participant\ParticipantMailBulkRepository;
use OswisOrg\OswisCalendarBundle\Repository\Participant\ParticipantRepository;
use OswisOrg\OswisCalendarBundle\Service\Participant\ParticipantBulkMailService;
use OswisOrg\OswisCalendarBundle\Service\Participant\ParticipantManualMail;
use OswisOrg\OswisCalendarBundle\Service\Participant\ParticipantManualMailer;
use OswisOrg\OswisCoreBundle\Entity\TwigTemplate\TwigTemplate;
use OswisOrg\OswisCoreBundle\Exceptions\OswisException;
use OswisOrg\OswisCoreBundle\Mail\Catalog\MailCatalog;
use OswisOrg\OswisCoreBundle\Mail\Validation\MailProblem;
use OswisOrg\OswisCoreBundle\Mail\Validation\MailValidationResult;
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
        private readonly ParticipantManualMailer $mailer,
        private readonly EntityManagerInterface $em,
        private readonly MailCatalog $mailCatalog,
    ) {
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

        return $this->renderCompose($ids);
    }

    /**
     * Formulář hromadné zprávy — i po neúspěšném zařazení, s tím, co autor napsal, a s výsledkem kontroly.
     *
     * @param list<int> $ids
     */
    private function renderCompose(array $ids, ?ParticipantManualMail $mail = null, ?MailValidationResult $validation = null): Response
    {
        $status = null !== $mail ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK;

        return $this->render('@OswisOrgOswisCalendar/web_admin/bulk_mail/compose.html.twig', [
            'title'           => 'Hromadný e-mail :: ADMIN',
            'pageTitle'       => 'Hromadný e-mail',
            'ids'             => $ids,
            'idsCsv'          => implode(',', $ids),
            'recipientCount'  => count($ids),
            'recipients'      => $this->participantRepository->findByIds($ids),
            'campaigns'       => $this->campaignTemplates(),
            'variableCatalog' => $this->mailCatalog->groupedForPanel(),
            'subject'         => $mail->subject ?? '',
            'body'            => $mail->body ?? '',
            'templateSlug'    => $mail->templateSlug ?? '',
            'validation'      => $validation,
        ], new Response(status: $status));
    }

    /** Live preview through MJML for a chosen recipient (default: the first). No send. */
    public function preview(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('bulk_mail_preview', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Neplatný CSRF token.');
        }
        $ids = $this->readIds($request);
        // Preview for a specific recipient if asked (and it really is in the snapshot), else the first.
        $chosenRaw = (string) $request->request->get('previewParticipantId', '');
        $chosen = ctype_digit($chosenRaw) ? (int) $chosenRaw : 0;
        $previewId = ($chosen > 0 && in_array($chosen, $ids, true)) ? $chosen : ($ids[0] ?? null);
        $participant = null !== $previewId ? $this->participantRepository->find($previewId) : null;
        if (!$participant instanceof Participant) {
            return new Response('<p style="font-family:sans-serif;color:#666">Náhled nelze vytvořit – příjemce nenalezen.</p>');
        }
        $mail = $this->readMessage($request);
        // Nejdřív kontrola: s chybou se místo náhledu ukáže, co opravit (dřív prázdné místo nebo Twig doslova).
        $validation = $this->mailer->validate($mail, [$participant]);
        if ($validation->hasErrors()) {
            return new Response(self::problemsHtml($validation->errors()));
        }
        try {
            return new Response($this->mailer->preview($mail, $participant)['html']);
        } catch (\Throwable $exception) {
            return new Response(self::problemsHtml([new MailProblem(MailProblem::ERROR, $exception->getMessage())]));
        }
    }

    /**
     * Chyby do náhledového iframu (styl je uvnitř náhledu, ne v administraci).
     *
     * @param list<MailProblem> $problems
     */
    private static function problemsHtml(array $problems): string
    {
        $items = array_map(static fn (MailProblem $p): string => '<li>'.htmlspecialchars($p->message, ENT_QUOTES).'</li>', $problems);

        return '<div style="font-family:sans-serif;color:#842029;background:#f8d7da;border:1px solid #f5c2c7;'
            .'border-radius:.375rem;padding:1rem;"><strong>Zprávu nejde odeslat — oprav prosím:</strong><ul>'
            .implode('', $items).'</ul></div>';
    }

    /** Step 2: queue the bulk (snapshot of recipients). Sends nothing; the drain does. */
    public function queue(Request $request): Response
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
            $this->addFlash('danger', sprintf('Příliš mnoho příjemců (max %d).', self::MAX_RECIPIENTS));

            return $this->redirectToRoute('oswis_org_oswis_calendar_web_admin_participants_list');
        }
        $mail = $this->readMessage($request);
        // Předmět + text NEBO uložená kampaň. Chyba = formulář zpátky i s tím, co autor napsal.
        if ('' === trim($mail->subject) || ('' === trim($mail->body) && !$mail->usesTemplate())) {
            $this->addFlash('warning', 'Vyplň předmět a text zprávy, nebo vyber uloženou kampaň.');

            return $this->renderCompose($ids, $mail);
        }
        // Kontrola VŠECH příjemců — s chybou zprávu nejde zařadit, takže se k lidem nedostane.
        $validation = $this->bulkMailService->validate($mail, $ids);
        if ($validation->hasErrors()) {
            return $this->renderCompose($ids, $mail, $validation);
        }
        try {
            $bulk = $this->bulkMailService->queue($mail, $ids, $validation);
        } catch (OswisException $exception) {
            $this->addFlash('danger', 'Zprávu nejde zařadit: '.$exception->getMessage());

            return $this->renderCompose($ids, $mail, $validation);
        }
        foreach ($validation->warnings() as $problem) {
            $this->addFlash('warning', $problem->message);
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

    /** Předmět, text (Twig) a případná uložená kampaň z formuláře — vykreslí se až pro každého příjemce. */
    private function readMessage(Request $request): ParticipantManualMail
    {
        return new ParticipantManualMail(
            trim((string) $request->request->get('subject', '')),
            (string) $request->request->get('body', ''),
            (string) $request->request->get('templateSlug', ''),
            $this->adminName(),
        );
    }

    private function adminName(): ?string
    {
        $user = $this->getUser();

        return $user instanceof UserInterface ? $user->getUserIdentifier() : null;
    }
}
