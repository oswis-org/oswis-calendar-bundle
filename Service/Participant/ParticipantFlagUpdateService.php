<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Service\Participant;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityManagerInterface;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantFlag;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantFlagGroup;
use OswisOrg\OswisCalendarBundle\Entity\Registration\RegistrationFlagGroupOffer;
use OswisOrg\OswisCalendarBundle\Entity\Registration\RegistrationFlagOffer;
use OswisOrg\OswisCalendarBundle\Entity\Registration\RegistrationOffer;
use OswisOrg\OswisCalendarBundle\Exception\FlagCapacityExceededException;
use OswisOrg\OswisCalendarBundle\Exception\FlagOutOfRangeException;
use OswisOrg\OswisCalendarBundle\Service\Registration\RegistrationFlagOfferService;
use OswisOrg\OswisCoreBundle\Exceptions\NotImplementedException;
use Psr\Log\LoggerInterface;

/**
 * Admin-side editing of a participant's registration flags (příznaky) — the piece the public
 * registration form never offered.
 *
 * Two capabilities the existing flows lack:
 *  1. Editing a flag category EVEN WHEN the participant has no {@see ParticipantFlagGroup} for it
 *     yet. At registration time only PUBLIC group offers get a group ({@see Participant::setFlagGroupsByOffer()}
 *     filters onlyPublic=true), so admin-only categories (Sleva, Zkrácený pobyt, Poznámky k platbě)
 *     never materialise. Here we create the group on demand via the idempotent
 *     {@see Participant::addFlagGroupOffer()}.
 *  2. A single trusted entry point shared by the web admin controller and the API state processor,
 *     so the capacity/min-max/soft-delete logic in {@see ParticipantFlagGroup::setParticipantFlags()}
 *     cannot drift between the two clients.
 *
 * What it deliberately does NOT do: send any e-mail (admin micro-edits stay silent), and it never
 * lets an admin attach a group offer that is not reachable from the participant's own registration
 * offer (the recursive availability set is the security boundary).
 */
final class ParticipantFlagUpdateService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RegistrationFlagOfferService $flagOfferService,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * All flag-group offers reachable for this participant's registration offer — recursively
     * through requiredRegRange (a turnus offer pulls its parent registration's categories), and
     * including non-public (admin-only) offers when $includeNonPublic is true.
     *
     * Deduplicated by id (the recursive walk can surface the same offer twice) with a stable order.
     *
     * @return list<RegistrationFlagGroupOffer>
     */
    public function getAvailableGroupOffers(Participant $participant, bool $includeNonPublic = true): array
    {
        $offer = $participant->getOffer();
        if (!$offer instanceof RegistrationOffer) {
            return [];
        }
        $byId = [];
        foreach ($offer->getFlagGroupRanges(null, null, !$includeNonPublic, true) as $groupOffer) {
            $groupOfferId = $groupOffer->getId();
            if (null !== $groupOfferId) {
                $byId[$groupOfferId] = $groupOffer;
            }
        }

        return array_values($byId);
    }

    /**
     * Set the participant's flags for ONE category (group offer) to exactly the given selection,
     * materialising the participant flag group if it does not exist yet.
     *
     * Reuses {@see ParticipantFlagGroup::setParticipantFlags()} for validation: it enforces the
     * group's min/max, each flag offer's min/max and remaining capacity ($admin=true bypasses the
     * capacity ceiling), soft-deletes removed flags and activates added ones. Persisted in two
     * flushes — the second one recomputes the cached usage counts, which read committed rows and so
     * must run after the first flush.
     *
     * @param list<int> $selectedFlagOfferIds ids of the RegistrationFlagOffers that should remain/become active
     *
     * @throws FlagCapacityExceededException when adding would exceed a flag offer's capacity (only when $admin=false)
     * @throws FlagOutOfRangeException       when the resulting count breaks a min/max constraint
     * @throws NotImplementedException       propagated from the entity layer (should not happen here)
     * @throws \InvalidArgumentException     when the group offer is not reachable for this participant
     */
    public function setFlags(
        Participant $participant,
        RegistrationFlagGroupOffer $groupOffer,
        array $selectedFlagOfferIds,
        bool $admin = false,
    ): void {
        // Security boundary: the group offer must belong to this participant's (recursive) offer set.
        $availableIds = array_map(
            static fn (RegistrationFlagGroupOffer $go): ?int => $go->getId(),
            $this->getAvailableGroupOffers($participant, true),
        );
        if (null === $groupOffer->getId() || !in_array($groupOffer->getId(), $availableIds, true)) {
            throw new \InvalidArgumentException('Tato kategorie příznaků není pro přihlášku účastníka dostupná.');
        }

        $group = $this->findGroup($participant, $groupOffer);
        if (!$group instanceof ParticipantFlagGroup) {
            // Idempotent — creates an empty group bound to this offer (binding is immutable afterwards).
            $participant->addFlagGroupOffer($groupOffer);
            $group = $this->findGroup($participant, $groupOffer);
        }
        if (!$group instanceof ParticipantFlagGroup) {
            throw new \InvalidArgumentException('Skupinu příznaků se nepodařilo vytvořit.');
        }

        // Resolve the requested ids against the offers actually available in this group (incl. non-public).
        $selectedOffers = [];
        foreach ($group->getAvailableFlagOffers(false) as $flagOffer) {
            if ($flagOffer instanceof RegistrationFlagOffer
                && null !== $flagOffer->getId()
                && in_array($flagOffer->getId(), $selectedFlagOfferIds, true)) {
                $selectedOffers[$flagOffer->getId()] = $flagOffer;
            }
        }

        // Build the new collection: keep existing active flags whose offer stays selected (preserves
        // their identity so setParticipantFlags() treats them as unchanged), create new ones for the rest.
        $existingByOfferId = [];
        foreach ($group->getParticipantFlags(true) as $existingFlag) {
            $offerId = $existingFlag->getFlagOffer()?->getId();
            if (null !== $offerId) {
                $existingByOfferId[$offerId] = $existingFlag;
            }
        }
        /** @var Collection<int, ParticipantFlag> $newFlags */
        $newFlags = new ArrayCollection();
        foreach ($selectedOffers as $offerId => $flagOffer) {
            if (isset($existingByOfferId[$offerId])) {
                $newFlags->add($existingByOfferId[$offerId]);
                continue;
            }
            // Mirror the registration form (FlagGroupOfParticipantType): a freshly constructed
            // ParticipantFlag with its group set self-registers into the group's collection, so
            // setParticipantFlags() then sees it as already-present and skips its activate() loop.
            // Activate explicitly — exactly as the registration path does — so the flag is active.
            $newFlag = new ParticipantFlag($flagOffer, $group);
            $newFlag->activate();
            $newFlags->add($newFlag);
        }

        // Validates + applies (soft-deletes removed, activates added). Throws on capacity/min-max.
        $group->setParticipantFlags($newFlags, $admin);

        $this->em->persist($participant);
        $this->em->flush();

        // Recompute cached usage AFTER the flush — countParticipantFlags() counts committed rows, so a
        // pre-flush count would be stale. Mirrors the recompute endpoint (ParticipantController::updateParticipants).
        $participant->updateCachedColumns();
        $this->flagOfferService->updateUsages($participant);
        $this->em->flush();

        $this->logger->info(sprintf(
            'FLAG EDIT: participant #%d category #%d set to [%s] (admin=%s).',
            $participant->getId() ?? 0,
            $groupOffer->getId(),
            implode(',', array_keys($selectedOffers)),
            $admin ? 'true' : 'false',
        ));
    }

    /**
     * Presentation model for the admin flag editor: every flag category reachable for the
     * participant (recursive, incl. non-public), with each flag offer marked selected/full and the
     * group's min/max — so the template can render one edit form per category, including categories
     * the participant has no group for yet ('hasGroup' = false).
     *
     * @return list<array{
     *     groupOffer: RegistrationFlagGroupOffer,
     *     categoryName: string,
     *     min: int,
     *     max: int|null,
     *     hasGroup: bool,
     *     flagOffers: list<array{offer: RegistrationFlagOffer, selected: bool, remaining: int|null, full: bool}>
     * }>
     */
    public function getFlagSelectionModel(Participant $participant): array
    {
        $model = [];
        foreach ($this->getAvailableGroupOffers($participant, true) as $groupOffer) {
            $group = $this->findGroup($participant, $groupOffer);
            $activeOfferIds = [];
            if ($group instanceof ParticipantFlagGroup) {
                foreach ($group->getParticipantFlags(true) as $participantFlag) {
                    $activeOfferId = $participantFlag->getFlagOffer()?->getId();
                    if (null !== $activeOfferId) {
                        $activeOfferIds[$activeOfferId] = true;
                    }
                }
            }
            $flagOffers = [];
            foreach ($groupOffer->getFlagOffers(false) as $offer) {
                $selected = null !== $offer->getId() && isset($activeOfferIds[$offer->getId()]);
                $remaining = $offer->getRemainingCapacity();
                $flagOffers[] = [
                    'offer'     => $offer,
                    'selected'  => $selected,
                    'remaining' => $remaining,
                    // "full" only blocks NEW selections — an already-selected flag is never disabled.
                    'full'      => !$selected && null !== $remaining && $remaining < 1,
                ];
            }
            $model[] = [
                'groupOffer'   => $groupOffer,
                'categoryName' => $groupOffer->getFlagCategory()?->getName() ?? 'Ostatní',
                'min'          => $groupOffer->getMin(),
                'max'          => $groupOffer->getMax(),
                'hasGroup'     => $group instanceof ParticipantFlagGroup,
                'flagOffers'   => $flagOffers,
            ];
        }

        return $model;
    }

    private function findGroup(Participant $participant, RegistrationFlagGroupOffer $groupOffer): ?ParticipantFlagGroup
    {
        foreach ($participant->getFlagGroups(null, null, true) as $group) {
            if ($group->getFlagGroupOffer()?->getId() === $groupOffer->getId()) {
                return $group;
            }
        }

        return null;
    }
}
