<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Service\Payment;

use DateTimeImmutable;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use Psr\Clock\ClockInterface;

/**
 * Jediné místo, kde se počítají termíny plateb — používají ho všechny maily (shrnutí, potvrzení
 * platby, kampaně). Pravidla (spec 2026-09-13 §6.5):
 * 1. záloha = potvrzení přihlášky + N dní, nejpozději v termínu doplatku;
 * 2. doplatek = datum nastavené u turnusu (nebo zděděné z ročníku);
 * 3. potvrzení méně než `lateDays` dní před termínem doplatku nebo po něm → pozdní režim („do N dnů");
 * 4. termín, který v okamžiku odeslání uplynul → „do N dnů" ({@see PaymentDeadlines}).
 */
final class PaymentDeadlineCalculator
{
    public const int DEFAULT_LATE_PAYMENT_DAYS = 3;

    public function __construct(private readonly ClockInterface $clock)
    {
    }

    public function calculate(
        DateTimeImmutable $confirmedAt,
        ?int $depositDays,
        ?DateTimeImmutable $restDueDate,
        ?int $lateDays,
        DateTimeImmutable $at,
    ): PaymentDeadlines {
        $lateDays = max(1, $lateDays ?? self::DEFAULT_LATE_PAYMENT_DAYS);
        $confirmedDay = $confirmedAt->setTime(0, 0);
        $restDue = $restDueDate?->setTime(0, 0);
        $late = null !== $restDue && $confirmedDay->modify(sprintf('+%d days', $lateDays)) > $restDue;
        $depositDue = null === $depositDays ? null : $confirmedDay->modify(sprintf('+%d days', max(0, $depositDays)));
        if (null !== $depositDue && null !== $restDue && $depositDue > $restDue) {
            $depositDue = $restDue;
        }

        return new PaymentDeadlines($depositDue, $restDue, $late, $lateDays, $at);
    }

    /**
     * Termíny pro přihlášku k okamžiku odeslání. Potvrzení = `userConfirmedAt` (ověření e-mailem);
     * `activated` je u přihlášek prázdné (klon 13. 9.: potvrzeno 237/245, activated 0/245).
     */
    public function forParticipant(Participant $participant, ?DateTimeImmutable $at = null): PaymentDeadlines
    {
        $now = $this->clock->now();
        $event = $participant->getEvent();
        $confirmed = $participant->getUserConfirmedAt() ?? $participant->getCreatedAt();
        $restDue = $event?->getRestDueDate(true);

        return $this->calculate(
            null === $confirmed ? $now : DateTimeImmutable::createFromInterface($confirmed),
            $event?->getDepositDueDays(true),
            null === $restDue ? null : DateTimeImmutable::createFromInterface($restDue),
            $event?->getLatePaymentDays(true),
            $at ?? $now,
        );
    }
}
