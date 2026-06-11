<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Service\Participant;

use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantFlag;

/**
 * Reconstructs what changed in a Participant's registration, purely from the versioned junction
 * entities — ParticipantFlag(Group) and ParticipantRegistration carry BasicTrait (createdAt) +
 * DeletedTrait (deletedAt), so an entry is "added" at createdAt and "removed" at deletedAt. No
 * snapshot is stored.
 *
 * Two views over the same data:
 *  - {@see computeChanges()} — the diff *since* a moment (used by the on-update change e-mail);
 *  - {@see buildHistory()}   — the full chronology (used by the admin registration-history timeline).
 *
 * In the since-view, a flag/registration created AND deleted within the window is transient
 * (net no change) → skipped.
 */
final class ParticipantChangeService
{
    /**
     * @return array{
     *     hasChanges: bool,
     *     flags: array<string, array{added: list<string>, removed: list<string>}>,
     *     registrationsAdded: list<string>,
     *     registrationsRemoved: list<string>,
     *     contactUpdated: bool
     * } flags keyed by flag-category name
     */
    public function computeChanges(Participant $participant, \DateTimeInterface $since): array
    {
        $flags = [];
        foreach ($participant->getFlagGroups(null, null, false) as $group) {
            foreach ($group->getParticipantFlags(false) as $flag) {
                $label = $this->flagLabel($flag);
                if (null === $label) {
                    continue;
                }
                $verb = $this->changeVerb($flag->getCreatedAt(), $flag->getDeletedAt(), $since);
                if (null === $verb) {
                    continue;
                }
                $category = $flag->getFlagCategory()?->getName() ?? $group->getFlagCategory()?->getName() ?? 'Ostatní';
                $flags[$category] ??= ['added' => [], 'removed' => []];
                $flags[$category][$verb][] = $label;
            }
        }

        $registrationsAdded = [];
        $registrationsRemoved = [];
        foreach ($participant->getParticipantRegistrations(false, false) as $reg) {
            $label = $reg->getEventName();
            if (null === $label) {
                continue;
            }
            $verb = $this->changeVerb($reg->getCreatedAt(), $reg->getDeletedAt(), $since);
            if ('added' === $verb) {
                $registrationsAdded[] = $label;
            } elseif ('removed' === $verb) {
                $registrationsRemoved[] = $label;
            }
        }

        $contactUpdatedAt = $participant->getContactForRead()?->getUpdatedAt();
        $contactUpdated = null !== $contactUpdatedAt && $contactUpdatedAt > $since;

        $hasChanges = [] !== $flags || [] !== $registrationsAdded || [] !== $registrationsRemoved || $contactUpdated;

        return [
            'hasChanges'           => $hasChanges,
            'flags'                => $flags,
            'registrationsAdded'   => $registrationsAdded,
            'registrationsRemoved' => $registrationsRemoved,
            'contactUpdated'       => $contactUpdated,
        ];
    }

    /**
     * Full chronological history of a participant's registration: every versioned junction entity
     * contributes an "added" event at its createdAt and (when soft-deleted) a "removed" event at its
     * deletedAt, plus the participant's own "created"/"removed" lifecycle. Sorted newest-first.
     *
     * Read-only — for the admin "Historie přihlášky" timeline. Scalar contact edits are in-place
     * (not versioned), so they cannot appear as discrete events here (the change e-mail surfaces them
     * via {@see computeChanges()} as a single "contact updated" hint instead).
     *
     * @return list<array{
     *     at: \DateTimeInterface,
     *     verb: 'created'|'added'|'removed',
     *     kind: 'participant'|'registration'|'flag',
     *     category: string|null,
     *     label: string
     * }>
     */
    public function buildHistory(Participant $participant): array
    {
        $events = [];

        $createdAt = $participant->getCreatedAt();
        if (null !== $createdAt) {
            $events[] = $this->event($createdAt, 'created', 'participant', null, 'Přihláška vytvořena');
        }
        $participantDeletedAt = $participant->getDeletedAt();
        if (null !== $participantDeletedAt) {
            $events[] = $this->event($participantDeletedAt, 'removed', 'participant', null, 'Přihláška smazána');
        }

        foreach ($participant->getFlagGroups(null, null, false) as $group) {
            foreach ($group->getParticipantFlags(false) as $flag) {
                $label = $this->flagLabel($flag);
                if (null === $label) {
                    continue;
                }
                $category = $flag->getFlagCategory()?->getName() ?? $group->getFlagCategory()?->getName() ?? 'Ostatní';
                $flagCreatedAt = $flag->getCreatedAt();
                if (null !== $flagCreatedAt) {
                    $events[] = $this->event($flagCreatedAt, 'added', 'flag', $category, $label);
                }
                $flagDeletedAt = $flag->getDeletedAt();
                if (null !== $flagDeletedAt) {
                    $events[] = $this->event($flagDeletedAt, 'removed', 'flag', $category, $label);
                }
            }
        }

        foreach ($participant->getParticipantRegistrations(false, false) as $reg) {
            $label = $reg->getEventName();
            if (null === $label) {
                continue;
            }
            $regCreatedAt = $reg->getCreatedAt();
            if (null !== $regCreatedAt) {
                $events[] = $this->event($regCreatedAt, 'added', 'registration', null, $label);
            }
            $regDeletedAt = $reg->getDeletedAt();
            if (null !== $regDeletedAt) {
                $events[] = $this->event($regDeletedAt, 'removed', 'registration', null, $label);
            }
        }

        usort($events, static fn (array $a, array $b): int => $b['at']->getTimestamp() <=> $a['at']->getTimestamp());

        return $events;
    }

    /**
     * @param 'created'|'added'|'removed'           $verb
     * @param 'participant'|'registration'|'flag'   $kind
     *
     * @return array{at: \DateTimeInterface, verb: 'created'|'added'|'removed', kind: 'participant'|'registration'|'flag', category: string|null, label: string}
     */
    private function event(\DateTimeInterface $at, string $verb, string $kind, ?string $category, string $label): array
    {
        return ['at' => $at, 'verb' => $verb, 'kind' => $kind, 'category' => $category, 'label' => $label];
    }

    /** Human-readable flag name (the flag's own name, falling back to the offer's). */
    private function flagLabel(ParticipantFlag $flag): ?string
    {
        return $flag->getFlag()?->getName() ?? $flag->getFlagOffer()?->getName();
    }

    /**
     * 'added' when created after $since and still active; 'removed' when deleted after $since but
     * created at-or-before it; null otherwise (unchanged, or created-and-deleted within the window).
     *
     * @return 'added'|'removed'|null
     */
    private function changeVerb(?\DateTimeInterface $created, ?\DateTimeInterface $deleted, \DateTimeInterface $since): ?string
    {
        if (null !== $created && $created > $since && null === $deleted) {
            return 'added';
        }
        if (null !== $deleted && $deleted > $since && (null === $created || $created <= $since)) {
            return 'removed';
        }

        return null;
    }
}
