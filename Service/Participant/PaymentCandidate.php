<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Service\Participant;

use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;

/**
 * Ranked candidate for matching a ParticipantPayment to a Participant.
 * Higher score = higher confidence. Reasons accumulate as the matcher
 * finds evidence (VS match, amount match, date proximity, phone-derived VS).
 */
final readonly class PaymentCandidate
{
    /**
     * @param list<string> $reasons human-readable Czech labels for the admin UI
     */
    public function __construct(
        public Participant $participant,
        public int $score,
        public array $reasons,
    ) {
    }

    public function withAddedReason(string $reason, int $bonusScore): self
    {
        return new self(
            $this->participant,
            $this->score + $bonusScore,
            [...$this->reasons, $reason],
        );
    }
}
