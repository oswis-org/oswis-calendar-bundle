<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Service\Accommodation;

use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use OswisOrg\OswisCalendarBundle\Entity\Accommodation\AccommodationUnit;
use OswisOrg\OswisCalendarBundle\Entity\Accommodation\Bed;
use OswisOrg\OswisCalendarBundle\Entity\Accommodation\Reservation;
use OswisOrg\OswisCalendarBundle\Entity\Event\Event;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Entity\Registration\RegistrationFlag;
use OswisOrg\OswisCalendarBundle\Entity\Registration\RegistrationFlagCategory;
use OswisOrg\OswisCalendarBundle\Repository\Accommodation\ReservationRepository;
use OswisOrg\OswisCalendarBundle\Repository\Accommodation\RoommateGroupRepository;

/**
 * Doménová logika ubytování — assign-during-check-in, constraint engine (MĚKKÝ — warn, ne blok),
 * check-in a obousměrné dohledání (kdo kde ↔ kdo v jednotce).
 *
 * KAPACITA JE MĚKKÁ (user 2026-07-14): overbooking je záměr (tým předchystá nad vyčerpanou kapacitu),
 * takže `checkAssignment` vrací UPOZORNĚNÍ, `assign` NIKDY neblokuje — jen varování předá dál (UI je ukáže).
 * Spec: docs/superpowers/specs/2026-07-14-checkin-accommodation-module-design.md (§5).
 */
class AccommodationService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ReservationRepository $reservationRepository,
        private readonly RoommateGroupRepository $roommateGroupRepository,
    ) {
    }

    /**
     * MĚKKÉ constrainty pro navrhované přiřazení — vrací upozornění (NEblokuje).
     *
     * @return list<AccommodationWarning>
     */
    public function checkAssignment(Participant $participant, AccommodationUnit $unit, ?Bed $bed = null): array
    {
        $warnings = [];

        if ($unit->isTemporarilyUnavailable()) {
            $warnings[] = new AccommodationWarning(
                AccommodationWarning::CODE_UNAVAILABLE,
                'Jednotka je dočasně nedostupná (závada).',
            );
        }

        // Kapacita: nepočítat účastníkovu vlastní stávající rezervaci v této jednotce (re-assign).
        $occupied = $this->reservationRepository->countActiveByUnit($unit);
        $existing = $this->reservationRepository->findActiveByParticipant($participant);
        if (null !== $existing && $existing->getUnit()?->getId() === $unit->getId()) {
            --$occupied;
        }
        if ($occupied >= $unit->getCapacity()) {
            $warnings[] = new AccommodationWarning(
                AccommodationWarning::CODE_OVER_CAPACITY,
                sprintf('Jednotka je plná (obsazeno %d z %d) — overbooking.', $occupied, $unit->getCapacity()),
            );
        }

        if (null !== $bed && $bed->getUnit()?->getId() !== $unit->getId()) {
            $warnings[] = new AccommodationWarning(
                AccommodationWarning::CODE_BED_MISMATCH,
                'Vybrané lůžko patří k jiné jednotce.',
            );
        }

        // Skupinové spolubydlení: člen skupiny přiřazený do JINÉ jednotky.
        foreach ($this->roommateGroupRepository->findByMember($participant) as $group) {
            foreach ($group->getMembers() as $member) {
                if ($member->getId() === $participant->getId()) {
                    continue;
                }
                $memberRes = $this->reservationRepository->findActiveByParticipant($member);
                if (null !== $memberRes && $memberRes->getUnit()?->getId() !== $unit->getId()) {
                    $warnings[] = new AccommodationWarning(
                        AccommodationWarning::CODE_GROUP_SPLIT,
                        sprintf(
                            'Spolubydlící „%s" (skupina „%s") je v jiné jednotce „%s".',
                            (string) $member->getContact()?->getName(),
                            (string) $group->getName(),
                            (string) $memberRes->getUnit()?->getName(),
                        ),
                    );
                }
            }
        }

        // Vazba na zvolený typ ubytování (flag) — heuristická shoda s jednotkou.
        $chosenType = $this->getParticipantAccommodationType($participant);
        if (null !== $chosenType && !$this->unitMatchesType($unit, $chosenType)) {
            $warnings[] = new AccommodationWarning(
                AccommodationWarning::CODE_FLAG_TYPE_MISMATCH,
                sprintf(
                    'Účastník zvolil typ ubytování „%s", jednotka je typu „%s".',
                    $chosenType,
                    (string) ($unit->getUnitType() ?? $unit->getFacility()?->getFacilityType()),
                ),
            );
        }

        return $warnings;
    }

    /**
     * Přiřadí účastníka do jednotky (idempotentně — 1 aktivní rezervace na účastníka; re-assign updatuje).
     * NIKDY neblokuje na varováních; vrací je pro UI.
     *
     * @return array{reservation: Reservation, warnings: list<AccommodationWarning>}
     */
    public function assign(
        Participant $participant,
        AccommodationUnit $unit,
        ?Bed $bed = null,
        ?DateTime $fromDate = null,
        ?DateTime $toDate = null,
    ): array {
        $warnings = $this->checkAssignment($participant, $unit, $bed);

        $reservation = $this->reservationRepository->findActiveByParticipant($participant);
        if (!$reservation instanceof Reservation) {
            $reservation = new Reservation($fromDate, $toDate);
            $reservation->setParticipant($participant);
            $this->em->persist($reservation);
        } else {
            if (null !== $fromDate) {
                $reservation->setFromDate($fromDate);
            }
            if (null !== $toDate) {
                $reservation->setToDate($toDate);
            }
        }
        $reservation->setUnit($unit);
        $reservation->setBed($bed);
        $this->em->flush();

        return ['reservation' => $reservation, 'warnings' => $warnings];
    }

    /** Ubytovací stanice check-inu — výdej klíče (nastaví checkedInAt + status checked_in). */
    public function checkIn(Reservation $reservation, ?DateTime $at = null): void
    {
        $reservation->setCheckedInAt($at ?? new DateTime());
        $this->em->flush();
    }

    /** Kde účastník bydlí. */
    public function getReservationForParticipant(Participant $participant): ?Reservation
    {
        return $this->reservationRepository->findActiveByParticipant($participant);
    }

    /**
     * Kdo bydlí v jednotce (obousměrné dohledání).
     *
     * @return list<Participant>
     */
    public function getOccupantsOfUnit(AccommodationUnit $unit): array
    {
        $occupants = [];
        foreach ($this->reservationRepository->getByUnit($unit) as $reservation) {
            $participant = $reservation->getParticipant();
            if ($participant instanceof Participant) {
                $occupants[] = $participant;
            }
        }

        return $occupants;
    }

    /**
     * Zvolený typ ubytování účastníka z flagu (TYPE_ACCOMMODATION_TYPE) — název, nebo null.
     */
    public function getParticipantAccommodationType(Participant $participant): ?string
    {
        foreach ($participant->getFlags(null, RegistrationFlagCategory::TYPE_ACCOMMODATION_TYPE) as $flag) {
            if (!$flag instanceof RegistrationFlag) {
                continue;
            }
            $name = $flag->getName();
            if (is_string($name) && '' !== $name) {
                return $name;
            }
        }

        return null;
    }

    /** Heuristická shoda jednotky se zvoleným typem (klíčové slovo v typu/facility/názvu). */
    private function unitMatchesType(AccommodationUnit $unit, string $chosenType): bool
    {
        $haystack = mb_strtolower(implode(' ', array_filter([
            $unit->getUnitType(),
            $unit->getFacility()?->getFacilityType(),
            $unit->getName(),
        ])));
        foreach (preg_split('/\s+/', mb_strtolower($chosenType)) ?: [] as $word) {
            if (mb_strlen($word) >= 3 && str_contains($haystack, $word)) {
                return true;
            }
        }

        return false;
    }
}
