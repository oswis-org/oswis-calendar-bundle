<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Service\Participant;

use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Repository\Participant\ParticipantRepository;
use OswisOrg\OswisCoreBundle\Entity\AppUser\AppUser;
use OswisOrg\OswisCoreBundle\Mail\Rendering\MailRenderer;
use Twig\Environment;

/**
 * Faithful, server-side e-mail PREVIEW — the single source of truth shared by the bulk composer and
 * the #139 template editor (which until now edited blind). Renders either a named template (file path
 * such as '@OswisOrgOswisCalendar/…' or a DB-stored slug resolved by the DatabaseLoader) OR an
 * arbitrary, TRUSTED Twig source (the unsaved editor textarea) against a real sample participant,
 * through the exact same MJML pipeline that real sends use. So "the preview is what the recipient
 * gets" holds — minus client-specific quirks (Gmail/Outlook) and per-recipient attachments (QR codes
 * are embedded via cid: only at send time → empty here).
 *
 * Trusted render = same trust level the #139 editor already grants (only ROLE_ADMIN can reach this).
 * Render errors are caught and returned as a readable HTML block so a syntax/runtime error surfaces
 * inside the preview iframe instead of bubbling up as a 500. No send, no persistence, no side effects.
 */
final class MailPreviewService
{
    public function __construct(
        private readonly Environment $twig,
        private readonly ParticipantRepository $participantRepository,
        private readonly ParticipantMailContextFactory $contextFactory,
        private readonly MailRenderer $renderer,
    ) {
    }

    /**
     * Pick a sample recipient for a preview: an explicit participant id wins; otherwise the most
     * recently created active participant. Returns null when the id is unknown or there are no
     * participants at all (callers render a friendly "no sample" message).
     */
    public function pickSampleParticipant(?int $participantId = null): ?Participant
    {
        if (null !== $participantId && $participantId > 0) {
            $participant = $this->participantRepository->find($participantId);

            return $participant instanceof Participant ? $participant : null;
        }
        $samples = $this->participantRepository->findSampleParticipants(1);

        return $samples[0] ?? null;
    }

    /**
     * Kontext náhledu — {@see ParticipantMailContextFactory::createNeutral()}; `$extra['appUser']`
     * vybere konkrétního adresáta (u organizace), jinak první.
     *
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    public function buildContext(Participant $participant, array $extra = []): array
    {
        $appUser = ($extra['appUser'] ?? null) instanceof AppUser ? $extra['appUser'] : null;

        return $this->contextFactory->createNeutral($participant, $appUser, $extra);
    }

    /**
     * Render a named template (file path or DB-stored slug) for the sample participant.
     *
     * @param array<string, mixed> $extra
     *
     * @return array{html: string, subject: ?string, error: ?string}
     */
    public function renderTemplate(string $templateName, Participant $participant, array $extra = [], ?string $subject = null): array
    {
        $context = $this->buildContext($participant, $extra);
        try {
            return [
                'html'    => $this->twig->render($templateName, $context),
                'subject' => $this->renderSubject($subject, $context),
                'error'   => null,
            ];
        } catch (\Throwable $exception) {
            return ['html' => $this->errorHtml($exception), 'subject' => $subject, 'error' => $exception->getMessage()];
        }
    }

    /**
     * Render arbitrary TRUSTED Twig source (e.g. the unsaved content of the #139 editor textarea) for
     * the sample participant — same trust level as the persisted template the admin is editing.
     *
     * @param array<string, mixed> $extra
     *
     * @return array{html: string, subject: ?string, error: ?string}
     */
    public function renderSource(string $source, Participant $participant, array $extra = [], ?string $subject = null): array
    {
        $context = $this->buildContext($participant, $extra);
        try {
            return [
                'html'    => $this->twig->createTemplate($source)->render($context),
                'subject' => $this->renderSubject($subject, $context),
                'error'   => null,
            ];
        } catch (\Throwable $exception) {
            return ['html' => $this->errorHtml($exception), 'subject' => $subject, 'error' => $exception->getMessage()];
        }
    }

    /**
     * Předmět přes {@see MailRenderer::renderSubject()} (stejně jako při odeslání); rozbitý předmět
     * v náhledu zůstane, jak je napsaný — podstatný je náhled textu, chyby hlásí kontrola.
     *
     * @param array<string, mixed> $context
     */
    private function renderSubject(?string $subject, array $context): ?string
    {
        if (null === $subject || '' === trim($subject)) {
            return $subject;
        }
        try {
            return $this->renderer->renderSubject($subject, $context);
        } catch (\Throwable) {
            return $subject;
        }
    }

    private function errorHtml(\Throwable $exception): string
    {
        return sprintf(
            '<div style="font-family:monospace;color:#842029;background:#f8d7da;border:1px solid #f5c2c7;'
            .'border-radius:.375rem;padding:1rem;white-space:pre-wrap;">'
            .'<strong>Chyba při vykreslení náhledu:</strong><br><br>%s</div>',
            htmlspecialchars($exception->getMessage(), ENT_QUOTES),
        );
    }
}
