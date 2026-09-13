<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Service\Payment;

use DateTimeImmutable;

/**
 * Termíny plateb jedné přihlášky spočítané k okamžiku odeslání mailu ({@see PaymentDeadlineCalculator}).
 *
 * Texty jsou hotové české fráze pro šablony a katalog proměnných. Mail nikdy neuvede datum v minulosti:
 * termín, který v okamžiku odeslání uplynul, a pozdní přihláška se vypíší jako „do N dnů"
 * (spec 2026-09-13 §6.5). Mezery jsou nezlomitelné (U+00A0), aby se „21. srpna" nerozdělilo.
 */
final readonly class PaymentDeadlines
{
    private const string NBSP = "\u{00A0}";

    /** @var array<int, string> */
    private const array MONTHS_GENITIVE = [
        1 => 'ledna', 2 => 'února', 3 => 'března', 4 => 'dubna', 5 => 'května', 6 => 'června',
        7 => 'července', 8 => 'srpna', 9 => 'září', 10 => 'října', 11 => 'listopadu', 12 => 'prosince',
    ];

    /** @var array<int, string> */
    private const array DAYS_GENITIVE = [
        1 => 'jednoho dne', 2 => 'dvou dnů', 3 => 'tří dnů', 4 => 'čtyř dnů', 5 => 'pěti dnů',
        6 => 'šesti dnů', 7 => 'sedmi dnů', 8 => 'osmi dnů', 9 => 'devíti dnů', 10 => 'deseti dnů',
    ];

    public function __construct(
        public ?DateTimeImmutable $depositDue,
        public ?DateTimeImmutable $restDue,
        public bool $late,
        public int $lateDays,
        public DateTimeImmutable $at,
    ) {
    }

    /** „do 17. července" / „do tří dnů" / null, když termín zálohy není nastavený. */
    public function depositText(): ?string
    {
        return $this->textFor($this->depositDue);
    }

    /** „do 21. srpna" / „do tří dnů" / null, když termín doplatku není nastavený. */
    public function restText(): ?string
    {
        return $this->textFor($this->restDue);
    }

    /** Záloha i doplatek mají stejný termín → šablona je může spojit do jedné věty („celou částku …"). */
    public function combined(): bool
    {
        if (null === $this->depositDue || null === $this->restDue) {
            return false;
        }

        return $this->depositText() === $this->restText();
    }

    private function textFor(?DateTimeImmutable $due): ?string
    {
        if (null === $due) {
            return null;
        }
        if ($this->late || $due < $this->at->setTime(0, 0)) {
            return 'do'.self::NBSP.(self::DAYS_GENITIVE[$this->lateDays] ?? $this->lateDays.self::NBSP.'dnů');
        }

        return 'do'.self::NBSP.$due->format('j').'.'.self::NBSP.self::MONTHS_GENITIVE[(int) $due->format('n')];
    }
}
