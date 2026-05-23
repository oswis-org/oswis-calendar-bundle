<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Service\Participant;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantPayment;
use OswisOrg\OswisCalendarBundle\Repository\Participant\ParticipantRepository;

/**
 * Produces a ranked candidate list for assigning a payment to a participant.
 *
 * Score breakdown (additive):
 *   VS exact match                                    : +100
 *   VS appears in note / internalNote (substring)     : +60
 *   VS = last-9-digits of participant phone (project_vs_is_always_phone) : +40
 *   amount matches remainingPrice or remainingDeposit : +30
 *   payment dateTime is within 30 days of event start : +20
 *   participant not soft-deleted                      : +10
 *
 * Ambiguity rule: if the top two candidates are within AMBIGUITY_WINDOW
 * points, the caller (CSV import or admin UI) MUST treat it as ambiguous
 * and not auto-apply. Memory: project_payment_matching_improvements.
 */
final readonly class PaymentMatchingService
{
    private const int VS_EXACT         = 100;
    private const int VS_IN_NOTE       = 60;
    private const int VS_FROM_PHONE    = 40;
    private const int AMOUNT_MATCH     = 30;
    private const int DATE_PROXIMITY   = 20;
    private const int NOT_SOFT_DELETED = 10;
    private const int AMBIGUITY_WINDOW = 20;

    public function __construct(
        private ParticipantService $participantService,
    ) {
    }

    /**
     * @return list<PaymentCandidate> sorted by score DESC, capped at $limit
     */
    public function suggest(ParticipantPayment $payment, int $limit = 10): array
    {
        /** @var array<int, PaymentCandidate> $candidates keyed by participant id */
        $candidates = [];

        $vs = trim((string) $payment->getVariableSymbol());
        if ('' !== $vs) {
            foreach ($this->fetchByVs($vs) as $participant) {
                $candidates[$this->key($participant)] = new PaymentCandidate(
                    $participant,
                    self::VS_EXACT,
                    ["VS přesně odpovídá ($vs)"],
                );
            }
        }

        foreach ($this->extractVsFromNotes($payment) as $extractedVs) {
            if ($extractedVs === $vs) {
                continue;
            }
            foreach ($this->fetchByVs($extractedVs) as $participant) {
                $key = $this->key($participant);
                $reason = "VS '$extractedVs' nalezen v poznámce platby";
                $candidates[$key] = isset($candidates[$key])
                    ? $candidates[$key]->withAddedReason($reason, self::VS_IN_NOTE)
                    : new PaymentCandidate($participant, self::VS_IN_NOTE, [$reason]);
            }
        }

        if ('' !== $vs) {
            foreach ($this->fetchByPhoneLast9($vs) as $participant) {
                $key = $this->key($participant);
                if (isset($candidates[$key])) {
                    continue;
                }
                $reason = 'VS odpovídá posledním 9 cifrám telefonu účastníka';
                $candidates[$key] = new PaymentCandidate($participant, self::VS_FROM_PHONE, [$reason]);
            }
        }

        foreach ($candidates as $key => $candidate) {
            $candidates[$key] = $this->applyAmountAndDateBonus($candidate, $payment);
        }

        $list = array_values($candidates);
        usort($list, static fn (PaymentCandidate $a, PaymentCandidate $b) => $b->score <=> $a->score);

        return array_slice($list, 0, $limit);
    }

    /**
     * Returns the single best candidate ONLY if it's unambiguous (gap to #2
     * is >= AMBIGUITY_WINDOW). Used by the CSV import auto-apply path.
     */
    public function pickUnambiguous(ParticipantPayment $payment): ?PaymentCandidate
    {
        $candidates = $this->suggest($payment, 2);
        if ([] === $candidates) {
            return null;
        }
        if (1 === count($candidates)) {
            return $candidates[0];
        }
        $gap = $candidates[0]->score - $candidates[1]->score;

        return $gap >= self::AMBIGUITY_WINDOW ? $candidates[0] : null;
    }

    private function applyAmountAndDateBonus(PaymentCandidate $candidate, ParticipantPayment $payment): PaymentCandidate
    {
        $result = $candidate;
        $value = $payment->getNumericValue();
        if (null !== $value) {
            $remPrice = $candidate->participant->getRemainingPrice();
            $remDeposit = $candidate->participant->getRemainingDeposit();
            if ($value === $remPrice || $value === $remDeposit) {
                $result = $result->withAddedReason('Částka odpovídá zbývající ceně/záloze', self::AMOUNT_MATCH);
            }
        }
        $eventStart = $candidate->participant->getEvent()?->getStartDateTime();
        $paymentAt = $payment->getDateTime();
        if (null !== $eventStart && null !== $paymentAt) {
            $diffDays = abs($eventStart->getTimestamp() - $paymentAt->getTimestamp()) / 86400;
            if ($diffDays <= 30) {
                $result = $result->withAddedReason('Platba ≤ 30 dní od začátku akce', self::DATE_PROXIMITY);
            }
        }
        if (null === $candidate->participant->getDeletedAt()) {
            $result = $result->withAddedReason('Účastník není smazaný', self::NOT_SOFT_DELETED);
        }

        return $result;
    }

    /**
     * @return Collection<int, Participant>
     */
    private function fetchByVs(string $vs): Collection
    {
        return $this->participantService->getParticipants([
            ParticipantRepository::CRITERIA_VARIABLE_SYMBOL => $vs,
            ParticipantRepository::CRITERIA_INCLUDE_DELETED => true,
        ]);
    }

    /**
     * Per memory project_vs_is_always_phone: VS = last 9 digits of participant
     * phone. We normalize the candidate to its digits-only last-9 and reuse the
     * VS criteria, which is the canonical key. If a participant has a non-phone
     * VS (the participant-ID fallback), we miss them here — acceptable; the
     * VS_EXACT path covers that case.
     *
     * @return Collection<int, Participant>
     */
    private function fetchByPhoneLast9(string $candidate): Collection
    {
        $digits = preg_replace('/\D+/', '', $candidate) ?? '';
        if (strlen($digits) < 9) {
            return new ArrayCollection();
        }
        $last9 = substr($digits, -9);

        return $this->participantService->getParticipants([
            ParticipantRepository::CRITERIA_VARIABLE_SYMBOL => $last9,
            ParticipantRepository::CRITERIA_INCLUDE_DELETED => true,
        ]);
    }

    /**
     * @return list<string> distinct 9-digit sequences found in note/internalNote
     */
    private function extractVsFromNotes(ParticipantPayment $payment): array
    {
        $haystack = trim(($payment->getNote() ?? '').' '.($payment->getInternalNote() ?? ''));
        if ('' === $haystack) {
            return [];
        }
        if (false === preg_match_all('/\b\d{9}\b/', $haystack, $matches)) {
            return [];
        }

        return array_values(array_unique($matches[0]));
    }

    private function key(Participant $p): int
    {
        return (int) $p->getId();
    }
}
