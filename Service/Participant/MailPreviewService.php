<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Service\Participant;

use OswisOrg\OswisAddressBookBundle\Entity\AbstractClass\AbstractContact;
use OswisOrg\OswisAddressBookBundle\Entity\Person;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Repository\Participant\ParticipantRepository;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
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
    /**
     * Mirrors the f / a / salName seeding at the top of message.html.twig so a free body FRAGMENT
     * (which does not extend that base) still has the Czech salutation helpers in scope.
     */
    private const string SALUTATION_PRELUDE =
        "{% set f = f is defined ? f : (contact.formal|default(appUser.formal|default(true))) %}"
        ."{% set a = a is defined ? a : (contact.czechSuffixA|default(appUser.czechSuffixA|default(''))) %}"
        ."{% set salName = salutationName|default(contact.salutationName|default(appUser.salutationName|default)) %}";

    public function __construct(
        private readonly Environment $twig,
        private readonly ParticipantRepository $participantRepository,
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
     * Superset render context covering every mail kind (summary / payment / campaign / ad-hoc), with
     * send-neutral safe defaults for the per-recipient bits that cannot exist in a preview (the QR
     * payment images are embedded via cid: at real send time → empty strings here). $extra overrides
     * the defaults (e.g. the ad-hoc body, the composing admin's name). Mirrors the $data arrays built
     * by {@see ParticipantMailService} so the preview matches the real send.
     *
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    public function buildContext(Participant $participant, array $extra = []): array
    {
        $contact = $participant->getContact();
        $appUser = null;
        foreach ($participant->getContactPersons(true) as $contactPerson) {
            if ($contactPerson instanceof AbstractContact && null !== $contactPerson->getAppUser()) {
                $appUser = $contactPerson->getAppUser();
                break;
            }
        }
        $base = [
            'participant'      => $participant,
            'appUser'          => $appUser,
            'contact'          => $contact,
            'salutationName'   => $contact instanceof Person ? $contact->getSalutationName() : $contact?->getName(),
            'type'             => 'preview',
            'category'         => null,
            'participantToken' => null,
            'isIS'             => false,
            'registrations'    => $participant->getParticipantRegistrations(true),
            'payment'          => null,
            'depositQr'        => '',
            'restQr'           => '',
        ];

        return array_merge($base, $extra);
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
     * Render a TRUSTED body FRAGMENT (not a full template — it does not extend message.html.twig) as
     * Twig against the recipient context, then sanitize the rendered HTML. The fragment may use
     * entity-API variables and conditional blocks — e.g. {{ contact.salutationName }} or
     * {% if participant.event(false).slug == 'seznamovak-up-2026-1' %}…{% endif %} — and the Czech
     * salutation helpers (f / a / salName) thanks to the prepended prelude. Throws on a Twig error so
     * the caller can decide (queue-validation rejects; the drain falls back to the raw body). The
     * sanitized output is then wrapped by the ad-hoc template's content_inner via {{ bodyHtml|raw }}.
     *
     * @param array<string, mixed> $extra overrides (e.g. the specific recipient appUser of an org)
     */
    public function renderBodyFragment(string $bodyTwig, Participant $participant, array $extra = []): string
    {
        $context = $this->buildContext($participant, $extra);

        return $this->sanitizeHtml($this->twig->createTemplate(self::SALUTATION_PRELUDE.$bodyTwig)->render($context));
    }

    /**
     * Sanitize rendered body HTML — safe elements + http/https/mailto/tel links only. Applied to the
     * Twig OUTPUT (not the trusted source), so admins keep full Twig power while the delivered markup
     * stays safe. Same config the bulk composer used on input before bodies became trusted Twig.
     */
    public function sanitizeHtml(string $html): string
    {
        return (new HtmlSanitizer(
            (new HtmlSanitizerConfig())
                ->allowSafeElements()
                ->allowLinkSchemes(['http', 'https', 'mailto', 'tel'])
                ->allowRelativeLinks(false)
                ->allowRelativeMedias(false),
        ))->sanitize($html);
    }

    /**
     * Render a subject line — as trusted Twig source when it contains Twig markup, else verbatim.
     * A broken subject template falls back to the raw string (the body preview is what matters).
     *
     * @param array<string, mixed> $context
     */
    private function renderSubject(?string $subject, array $context): ?string
    {
        if (null === $subject || '' === trim($subject)) {
            return $subject;
        }
        if (!str_contains($subject, '{{') && !str_contains($subject, '{%')) {
            return $subject;
        }
        try {
            return trim($this->twig->createTemplate($subject)->render($context));
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
