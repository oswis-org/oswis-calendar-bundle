<?php

/**
 * @noinspection PhpUnused
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Entity\ParticipantMail;

use DateTime;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Index;
use Doctrine\ORM\Mapping\Table;
use OswisOrg\OswisCalendarBundle\Repository\Participant\ParticipantMailBulkRepository;

/**
 * Durable outbox for ad-hoc bulk e-mails composed in web admin. A bulk row holds the message (subject
 * + verbatim HTML body) plus a snapshot of recipient participant IDs; it is drained in capped batches
 * by a cron command / JS auto-drain (synchronous SMTP can't loop over hundreds in one request).
 *
 * Deliberately a PLAIN entity — no Gedmo Blameable relation. It is persisted/updated from a CLI drain
 * where the Blameable security actor is absent; a Blameable relation there triggered the persist
 * recursion class that caused the IMAP OOM. The composing admin is stored as a plain string instead.
 *
 * @author Jakub Zak <mail@jakubzak.eu>
 */
#[Entity(repositoryClass: ParticipantMailBulkRepository::class)]
#[Table(name: 'calendar_participant_mail_bulk')]
#[Index(name: 'IDX_PARTICIPANT_MAIL_BULK_STATUS', columns: ['status'])]
class ParticipantMailBulk
{
    /**
     * Rozepsaný koncept (dávka 3.3, spec §5.1) — neodesílá se NIKDY: rozesílka ani cron ho nevidí
     * ({@see ParticipantMailBulkRepository::findPending()} bere jen `queued`/`sending`), odejde až po „Odeslat"
     * z kontroly před odesláním ({@see zaradit()}).
     */
    public const STATUS_DRAFT = 'draft';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_SENDING = 'sending';

    public const STATUS_DONE = 'done';
    /** Zrušeno před spuštěním, nebo zastaveno uprostřed (zbytek se neodešle) — dávka 3.2. */
    public const STATUS_CANCELLED = 'cancelled';

    /** Po „Zařadit a odeslat" se rozesílka spustí až po této době — do té doby ji jde zrušit (spec §5.1, 5.3 h). */
    public const int ODKLAD_SEKUND = 30;

    #[Id]
    #[GeneratedValue]
    #[Column(type: 'integer')]
    protected ?int $id = null;

    #[Column(type: 'string', length: 255)]
    protected string $subject;

    #[Column(type: 'text')]
    protected string $bodyHtml;

    /**
     * Optional renderable template name (a `core_twig_template` slug resolved by the DatabaseLoader, or
     * a file path) of a stored campaign/snippet to send instead of the free body. NULL = free body.
     */
    #[Column(type: 'string', length: 255, nullable: true)]
    protected ?string $templateSlug = null;

    #[Column(type: 'string', length: 255, nullable: true)]
    protected ?string $adminName = null;

    #[Column(type: 'datetime')]
    protected DateTime $createdAt;

    #[Column(type: 'string', length: 16)]
    protected string $status = self::STATUS_QUEUED;

    /** @var list<int> Snapshot of recipient participant IDs (resolved from the list filter/selection at compose time). */
    #[Column(type: 'json')]
    protected array $participantIds = [];

    /** Cursor into participantIds — how many have been processed (sent or failed). Resumable. */
    #[Column(type: 'integer')]
    protected int $processedCount = 0;

    #[Column(type: 'integer')]
    protected int $sentCount = 0;

    #[Column(type: 'integer')]
    protected int $failedCount = 0;

    #[Column(type: 'text', nullable: true)]
    protected ?string $failedNote = null;

    /**
     * Neodesílat dřív než v tuto chvíli (dávka 3.2): 30 s na zrušení po zařazení, nebo zvolený čas
     * (naplánované odeslání). NULL = hned (zprávy zařazené před zavedením). Web i cron (`bin/console`)
     * zapisují i čtou v pražském čase, takže se porovnává bez převodu.
     */
    #[Column(name: 'send_after', type: 'datetime_immutable', nullable: true)]
    protected ?\DateTimeImmutable $sendAfter = null;

    /** Kdo rozesílku zrušil / zastavil (jméno správce jako text — bez vazby, viz popis třídy). */
    #[Column(name: 'cancelled_by', type: 'string', length: 255, nullable: true)]
    protected ?string $cancelledBy = null;

    /**
     * Revize konceptu — ochrana proti souběhu (dvě okna, automatické ukládání): uložení projde jen s revizí, ze které
     * autor vycházel ({@see ParticipantMailBulkRepository::zamknoutKoncept()}). Úmyslně NE `#[Version]` Doctrine:
     * ta by zvedala verzi i při každém posunu kurzoru rozesílky a „Zastavit" z jiného spojení by rozesílku shodilo
     * výjimkou místo čistého zastavení.
     */
    #[Column(type: 'integer', options: ['default' => 0])]
    protected int $revision = 0;

    /** Poslední uložení konceptu (u zařazených zpráv NULL). */
    #[Column(name: 'updated_at', type: 'datetime_immutable', nullable: true)]
    protected ?\DateTimeImmutable $updatedAt = null;

    /**
     * Záznam zkoušek sobě (spec §5.1, 5.3 a): kdy, kdo, na které adresy a pro koho vykresleno. Nejnovější poslední,
     * nejvýš {@see ZKOUSEK_MAX}. Samotné zkušební maily jsou systémové e-maily (bez historie přihlášky).
     *
     * @var list<array{at: string, by: ?string, to: list<string>, participantId: int, sent: int, errors: list<string>}>|null
     */
    #[Column(name: 'tests', type: 'json', nullable: true)]
    protected ?array $tests = null;

    public const int ZKOUSEK_MAX = 20;

    /**
     * Přílohy zprávy (dávka 3.5): ID souborů z úložiště příloh a způsob — `priloha` / `odkaz`
     * ({@see \OswisOrg\OswisCalendarBundle\Service\Participant\ParticipantManualMail}). NULL = žádné.
     *
     * @var list<array{id: int, mode: string}>|null
     */
    #[Column(type: 'json', nullable: true)]
    protected ?array $attachments = null;

    /** @return list<array{id: int, mode: string}> */
    public function getAttachments(): array
    {
        return $this->attachments ?? [];
    }

    /** @param list<array{id: int, mode: string}> $attachments */
    public function setAttachments(array $attachments): void
    {
        $this->attachments = [] === $attachments ? null : $attachments;
    }

    /**
     * @param array<int> $participantIds normalized to a 0-indexed list (callers may pass filtered/keyed arrays)
     */
    public function __construct(
        string $subject,
        string $bodyHtml,
        array $participantIds,
        ?string $adminName = null,
        ?string $templateSlug = null,
    ) {
        $this->subject = $subject;
        $this->bodyHtml = $bodyHtml;
        $this->participantIds = array_values($participantIds);
        $this->adminName = $adminName;
        $this->templateSlug = (null !== $templateSlug && '' !== trim($templateSlug)) ? trim($templateSlug) : null;
        $this->createdAt = new DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function getBodyHtml(): string
    {
        return $this->bodyHtml;
    }

    public function getTemplateSlug(): ?string
    {
        return $this->templateSlug;
    }

    public function hasTemplate(): bool
    {
        return null !== $this->templateSlug && '' !== $this->templateSlug;
    }

    public function getAdminName(): ?string
    {
        return $this->adminName;
    }

    public function getCreatedAt(): DateTime
    {
        return $this->createdAt;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
    }

    /**
     * Nový koncept — i neúplný (bez předmětu či textu); kontrola přijde až před odesláním.
     *
     * @param array<int> $participantIds
     */
    public static function koncept(string $subject, string $bodyHtml, array $participantIds, ?string $adminName, ?string $templateSlug, ?\DateTimeImmutable $sendAfter, \DateTimeImmutable $now): self
    {
        $bulk = new self($subject, $bodyHtml, $participantIds, $adminName, $templateSlug);
        $bulk->status = self::STATUS_DRAFT;
        $bulk->sendAfter = $sendAfter;
        $bulk->updatedAt = $now;

        return $bulk;
    }

    /**
     * Uložit změny konceptu. Revizi zvedá {@see ParticipantMailBulkRepository::zamknoutKoncept()} (atomicky v DB),
     * tady se jen srovná stav entity.
     *
     * @param array<int> $participantIds
     */
    public function upravitKoncept(string $subject, string $bodyHtml, array $participantIds, ?string $adminName, ?string $templateSlug, ?\DateTimeImmutable $sendAfter, \DateTimeImmutable $now, int $revision): void
    {
        if (!$this->isDraft()) {
            throw new \LogicException(sprintf('Hromadný e-mail #%d už není koncept.', $this->id ?? 0));
        }
        $this->subject = $subject;
        $this->bodyHtml = $bodyHtml;
        $this->participantIds = array_values($participantIds);
        $this->adminName = $adminName;
        $this->templateSlug = (null !== $templateSlug && '' !== trim($templateSlug)) ? trim($templateSlug) : null;
        $this->sendAfter = $sendAfter;
        $this->updatedAt = $now;
        $this->revision = $revision;
    }

    /** Koncept → ve frontě (po kontrole a „Odeslat"). Čas vytvoření = čas zařazení, aby fronta držela pořadí. */
    public function zaradit(\DateTimeImmutable $sendAfter, \DateTimeImmutable $now): void
    {
        if (!$this->isDraft()) {
            throw new \LogicException(sprintf('Hromadný e-mail #%d už není koncept.', $this->id ?? 0));
        }
        $this->status = self::STATUS_QUEUED;
        $this->sendAfter = $sendAfter;
        $this->createdAt = DateTime::createFromImmutable($now);
        $this->updatedAt = null;
    }

    /**
     * @param list<string> $to
     * @param list<string> $errors
     */
    public function recordTest(\DateTimeImmutable $at, ?string $by, array $to, int $participantId, int $sent, array $errors): void
    {
        $tests = $this->tests ?? [];
        $tests[] = ['at' => $at->format(\DATE_ATOM), 'by' => $by, 'to' => $to, 'participantId' => $participantId, 'sent' => $sent, 'errors' => $errors];
        $this->tests = array_slice($tests, -self::ZKOUSEK_MAX);
    }

    /** @return list<array{at: string, by: ?string, to: list<string>, participantId: int, sent: int, errors: list<string>}> */
    public function getTests(): array
    {
        return $this->tests ?? [];
    }

    /** @return array{at: string, by: ?string, to: list<string>, participantId: int, sent: int, errors: list<string>}|null */
    public function getLastTest(): ?array
    {
        $tests = $this->getTests();

        return [] === $tests ? null : $tests[count($tests) - 1];
    }

    public function isDraft(): bool
    {
        return self::STATUS_DRAFT === $this->status;
    }

    public function getRevision(): int
    {
        return $this->revision;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function isDone(): bool
    {
        return self::STATUS_DONE === $this->status;
    }

    public function isCancelled(): bool
    {
        return self::STATUS_CANCELLED === $this->status;
    }

    /** Už se nic dalšího neodešle — hotovo, nebo zrušeno. */
    public function isFinished(): bool
    {
        return $this->isDone() || $this->isCancelled();
    }

    public function getSendAfter(): ?\DateTimeImmutable
    {
        return $this->sendAfter;
    }

    public function setSendAfter(?\DateTimeImmutable $sendAfter): void
    {
        $this->sendAfter = $sendAfter;
    }

    /** Smí se už odesílat? (čas „odeslat po" uplynul, nebo žádný není) */
    public function isDue(\DateTimeInterface $now): bool
    {
        return null === $this->sendAfter || $this->sendAfter <= $now;
    }

    /** Ještě nic neodešlo a čeká se na čas odeslání — naplánováno (i běžících 30 s na zrušení). */
    public function isWaiting(\DateTimeInterface $now): bool
    {
        return !$this->isDraft() && !$this->isFinished() && 0 === $this->processedCount && !$this->isDue($now);
    }

    public function cancel(?string $who): void
    {
        if (!$this->isFinished()) {
            $this->status = self::STATUS_CANCELLED;
            $this->cancelledBy = $who;
        }
    }

    public function getCancelledBy(): ?string
    {
        return $this->cancelledBy;
    }

    /** @return list<int> */
    public function getParticipantIds(): array
    {
        return $this->participantIds;
    }

    public function getTotalCount(): int
    {
        return count($this->participantIds);
    }

    public function getProcessedCount(): int
    {
        return $this->processedCount;
    }

    public function setProcessedCount(int $processedCount): void
    {
        $this->processedCount = max(0, $processedCount);
    }

    public function getRemainingCount(): int
    {
        return max(0, $this->getTotalCount() - $this->processedCount);
    }

    public function getSentCount(): int
    {
        return $this->sentCount;
    }

    public function recordSent(): void
    {
        ++$this->sentCount;
    }

    public function getFailedCount(): int
    {
        return $this->failedCount;
    }

    public function recordFailed(?string $note = null): void
    {
        ++$this->failedCount;
        if (null !== $note && '' !== trim($note)) {
            $this->failedNote = mb_substr(trim(($this->failedNote ?? '')."\n".trim($note)), 0, 60000);
        }
    }

    /** Příjemce přeskočený (např. přihláška mezitím zrušená) — jen poznámka, nepočítá se mezi chyby. */
    public function recordSkipped(string $note): void
    {
        $this->failedNote = mb_substr(trim(($this->failedNote ?? '')."\n".trim($note)), 0, 60000);
    }

    public function getFailedNote(): ?string
    {
        return $this->failedNote;
    }
}
