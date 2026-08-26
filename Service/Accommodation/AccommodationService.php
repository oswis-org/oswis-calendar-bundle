<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Service\Accommodation;

use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use OswisOrg\OswisCalendarBundle\Entity\Accommodation\AccommodationUnit;
use OswisOrg\OswisCalendarBundle\Entity\Accommodation\Bed;
use OswisOrg\OswisCalendarBundle\Entity\Accommodation\Reservation;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Entity\Registration\RegistrationFlag;
use OswisOrg\OswisCalendarBundle\Entity\Registration\RegistrationFlagCategory;
use OswisOrg\OswisCalendarBundle\Repository\Accommodation\ReservationRepository;
use OswisOrg\OswisCalendarBundle\Repository\Accommodation\RoommateGroupRepository;

/**
 * Doménová logika ubytování — assign-during-check-in, constraint engine (MĚKKÝ — warn, ne blok),
 * check-in a obousměrné dohledání (kdo kde ↔ kdo v jednotce).
 *
 * Constrainty pokrývají: dostupnost jednotky, kapacitu, shodu lůžka, typ ubytování z přihlášky,
 * skupinové spolubydlení ({@see RoommateGroup}) i párové ({@see RoommatePreference}).
 *
 * KAPACITA JE MĚKKÁ (user 2026-07-14): overbooking je záměr (tým předchystá nad vyčerpanou kapacitu),
 * takže `checkAssignment` vrací UPOZORNĚNÍ, `assign` NIKDY neblokuje — jen varování předá dál (UI je ukáže).
 * Spec: docs/superpowers/specs/2026-07-14-checkin-accommodation-module-design.md (§5).
 */
class AccommodationService
{
    /** Sekce kempu, která je „hotelového typu" (Motel). Ostatní sekce = běžné ubytování. */
    private const string SECTION_MOTEL = 'motel';

    /** Slugy příznaků typu ubytování (kategorie `accommodation-type`). */
    private const string ACCOMMODATION_SLUG_HOTEL = 'hotel';
    private const string ACCOMMODATION_SLUG_CAMP = 'kemp';
    private const string ACCOMMODATION_SLUG_TENT = 'stan';
    private const string ACCOMMODATION_SLUG_NONE = 'bez-ubytovani';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ReservationRepository $reservationRepository,
        private readonly RoommateGroupRepository $roommateGroupRepository,
        private readonly RoommatePreferenceService $roommatePreferenceService,
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

        // Konkrétní lůžko už někdo drží. Appka sice obsazená lůžka označuje, jenže ten seznam
        // si spočítala při načtení — soused u vedlejšího stolu mezitím přiřadil svoje. Přeplnit
        // chatku je záměr (přistýlky), dát dvěma lidem týž matrac ne.
        if (null !== $bed) {
            $drzitel = $this->reservationRepository->findActiveByBed($bed, $participant);
            if (null !== $drzitel) {
                $warnings[] = new AccommodationWarning(
                    AccommodationWarning::CODE_BED_TAKEN,
                    sprintf(
                        'Lůžko už drží „%s".',
                        (string) ($drzitel->getParticipant()?->getContact()?->getName() ?? 'jiný účastník'),
                    ),
                );
            }
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

        // Párové spolubydlení: domluvená dvojice by se přiřazením rozdělila. Kontroluje se
        // OBOUSMĚRNĚ — požadavek zadá tým typicky jen u jednoho z dvojice (přišel e-mailem od
        // jednoho z nich), takže hledat jen „co si přál tenhle člověk" by druhou stranu minulo.
        foreach ($this->roommatePreferenceService->getMatchedRoommates($participant) as $roommate) {
            $roommateRes = $this->reservationRepository->findActiveByParticipant($roommate);
            if (null !== $roommateRes && $roommateRes->getUnit()?->getId() !== $unit->getId()) {
                $warnings[] = new AccommodationWarning(
                    AccommodationWarning::CODE_ROOMMATE_SPLIT,
                    sprintf(
                        'Domluvené spolubydlení: „%s" je v jiné jednotce „%s".',
                        (string) $roommate->getContact()?->getName(),
                        (string) $roommateRes->getUnit()?->getName(),
                    ),
                );
            }
        }

        // Vazba na zvolený typ ubytování z přihlášky (dle SLUGU příznaku, ne jeho názvu).
        $flag = $this->getParticipantAccommodationFlag($participant);
        if (null !== $flag && !$this->unitMatchesType($unit, $flag)) {
            $warnings[] = new AccommodationWarning(
                AccommodationWarning::CODE_FLAG_TYPE_MISMATCH,
                sprintf(
                    'Účastník zvolil typ ubytování „%s", jednotka je typu „%s".',
                    (string) $flag->getName(),
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
        return $this->getParticipantAccommodationFlag($participant)?->getName();
    }

    /** Příznak typu ubytování z přihlášky (hotel / kemp / stan / bez-ubytovani). */
    public function getParticipantAccommodationFlag(Participant $participant): ?RegistrationFlag
    {
        foreach ($participant->getFlags(null, RegistrationFlagCategory::TYPE_ACCOMMODATION_TYPE) as $flag) {
            if ($flag instanceof RegistrationFlag) {
                return $flag;
            }
        }

        return null;
    }

    /**
     * Sedí jednotka ke zvolenému typu ubytování? Klíčem je SLUG příznaku, ne jeho název.
     *
     * Předchozí implementace hledala slova z NÁZVU příznaku v názvu jednotky
     * („ubytování", „hotelového", „typu" v „motel camp 101") → neshodla se NIKDY, takže varování
     * „špatný typ ubytování" padalo u KAŽDÉHO přiřazení (letos 242 lidí). Varování, které svítí
     * vždycky, se obsluha naučí ignorovat — a tím přestane fungovat i to, které svítit má.
     *
     * Pravidla dle reality (user 2026-07-16): hotelové → Motel · běžné → ostatní pokoje a chatky ·
     * vlastní stan / bez ubytování → nepřiřazuje se jednotka vůbec.
     * Neznámý slug = nekontrolujeme (raději ticho než falešný poplach).
     */
    private function unitMatchesType(AccommodationUnit $unit, RegistrationFlag $flag): bool
    {
        $unitType = (string) $unit->getUnitType();

        return match ($flag->getSlug()) {
            self::ACCOMMODATION_SLUG_HOTEL => self::SECTION_MOTEL === $unitType,
            self::ACCOMMODATION_SLUG_CAMP => self::SECTION_MOTEL !== $unitType,
            self::ACCOMMODATION_SLUG_TENT, self::ACCOMMODATION_SLUG_NONE => false,
            default => true,
        };
    }
}
