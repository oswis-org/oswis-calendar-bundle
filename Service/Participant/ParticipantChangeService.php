<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Service\Participant;

use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;

/**
 * Reconstructs what changed in a Participant's registration since a given moment, purely from the
 * versioned junction entities — ParticipantFlag(Group) and ParticipantRegistration carry BasicTrait
 * (createdAt) + DeletedTrait (deletedAt), so an entry is "added" when createdAt > since and "removed"
 * when deletedAt > since. No snapshot is stored. Used by the on-update change e-mail (diff since the
 * last confirmation) and, later, the admin registration-history timeline (same logic, earlier "since").
 *
 * A flag/registration created AND deleted within the window is transient (net no change) → skipped.
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
                $label = $flag->getFlag()?->getName() ?? $flag->getFlagOffer()?->getName();
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
