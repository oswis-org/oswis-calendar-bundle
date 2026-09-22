<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Controller\WebAdmin;

use OswisOrg\OswisCalendarBundle\Entity\Accommodation\AccommodationUnit;
use OswisOrg\OswisCalendarBundle\Entity\Accommodation\Reservation;
use OswisOrg\OswisCalendarBundle\Entity\Event\Event;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantCategory;
use OswisOrg\OswisCalendarBundle\Repository\Accommodation\ReservationRepository;
use OswisOrg\OswisCalendarBundle\Repository\Event\EventRepository;
use OswisOrg\OswisCalendarBundle\Repository\Participant\ParticipantRepository;
use OswisOrg\OswisCoreBundle\Utils\StringUtils;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Ubytování turnusu — kdo kde spí.
 *
 * PROČ vznikla (19. 9. 2026): model ubytování (zařízení → jednotka → lůžko → rezervace) je hotový
 * od 14. 7. 2026 a na produkci v něm leželo **461 rezervací**, ale **v administraci nebyla jediná
 * obrazovka**, takže se dal zkontrolovat jen přes databázi. Přesně ten vzorec, kvůli kterému
 * modul shnije: model → ⛔ obrazovka → data → uživatel
 * (viz `docs/OSWIS_INVENTURA_NEDOTAZENEHO_2026-09-19.md`).
 *
 * **Záměrně jen ke čtení.** Zapisuje se přes check-in a přes `AccommodationService::assign()`;
 * přidávat sem druhou cestu k zápisu by znamenalo druhou sadu pravidel o kapacitě a lůžkách.
 * Tahle obrazovka zavírá díru „data, na která se nedá podívat", ne víc.
 */
#[IsGranted('ROLE_MANAGER')]
final class WebAdminAccommodationController extends AbstractController
{
    public function __construct(
        private readonly EventRepository $eventRepository,
        private readonly ReservationRepository $reservationRepository,
        private readonly ParticipantRepository $participantRepository,
    ) {
    }

    public function index(string $eventSlug): Response
    {
        $event = $this->eventRepository->getEvent([EventRepository::CRITERIA_SLUG => $eventSlug])
            ?? throw $this->createNotFoundException("Turnus '{$eventSlug}' nenalezen.");

        $rezervace = $this->reservationRepository->getByEvent($event);
        $jednotky = $this->seskupitPodleJednotek($rezervace);
        $ubytovani = $this->idUbytovanych($rezervace);
        $bezUbytovani = array_values(array_filter(
            $this->ucastnici($event),
            // Přihláška bez id (nepersistovaná) se do „bez ubytování" počítat nemá.
            static fn (Participant $p): bool => null !== $p->getId() && !isset($ubytovani[$p->getId()]),
        ));

        return $this->render('@OswisOrgOswisCalendar/web_admin/accommodation/index.html.twig', [
            'event'         => $event,
            'eventSlug'     => $eventSlug,
            'jednotky'      => $jednotky,
            'bezUbytovani'  => $bezUbytovani,
            'pocetRezervaci' => count($rezervace),
            'pageTitle'     => 'Ubytování — '.($event->getShortName() ?? $event->getName() ?? $eventSlug),
            'page_title'    => 'Ubytování :: ADMIN',
        ]);
    }

    /**
     * Rezervace seskupené po jednotkách, seřazené podle zařízení a názvu jednotky.
     *
     * @param list<Reservation> $rezervace
     *
     * @return list<array{unit: AccommodationUnit, facility: string, obsazeno: int, kapacita: int, rezervace: list<Reservation>}>
     */
    private function seskupitPodleJednotek(array $rezervace): array
    {
        /** @var array<int, array{unit: AccommodationUnit, facility: string, obsazeno: int, kapacita: int, rezervace: list<Reservation>}> $podle */
        $podle = [];
        foreach ($rezervace as $r) {
            $unit = $r->getUnit();
            $id = $unit?->getId();
            if (!$unit instanceof AccommodationUnit || null === $id) {
                continue;
            }
            $podle[$id] ??= [
                'unit'      => $unit,
                'facility'  => $unit->getFacility()?->getName() ?? '(bez zařízení)',
                'obsazeno'  => 0,
                'kapacita'  => $unit->getCapacity(),
                'rezervace' => [],
            ];
            ++$podle[$id]['obsazeno'];
            $podle[$id]['rezervace'][] = $r;
        }
        foreach (array_keys($podle) as $id) {
            usort(
                $podle[$id]['rezervace'],
                static fn (Reservation $a, Reservation $b): int => StringUtils::compareCzech(
                    $a->getParticipant()?->getSortableName() ?? '',
                    $b->getParticipant()?->getSortableName() ?? '',
                ),
            );
        }
        $radky = array_values($podle);
        usort($radky, static fn (array $a, array $b): int => [$a['facility'], $a['unit']->getName() ?? '']
            <=> [$b['facility'], $b['unit']->getName() ?? '']);

        return $radky;
    }

    /**
     * @param list<Reservation> $rezervace
     *
     * @return array<int, true> id přihlášek, které mají aktivní rezervaci
     */
    private function idUbytovanych(array $rezervace): array
    {
        $ids = [];
        foreach ($rezervace as $r) {
            $id = $r->getParticipant()?->getId();
            if (null !== $id) {
                $ids[$id] = true;
            }
        }

        return $ids;
    }

    /**
     * Účastníci turnusu — **stejné vymezení jako check-in a pásky** (jen `TYPE_ATTENDEE`,
     * hloubka 0), aby počty na všech třech obrazovkách seděly.
     *
     * @return list<Participant>
     */
    private function ucastnici(Event $event): array
    {
        /** @var list<Participant> $ucastnici */
        $ucastnici = $this->participantRepository->getParticipants([
            ParticipantRepository::CRITERIA_EVENT                 => $event,
            ParticipantRepository::CRITERIA_EVENT_RECURSIVE_DEPTH => 0,
            ParticipantRepository::CRITERIA_PARTICIPANT_TYPE      => ParticipantCategory::TYPE_ATTENDEE,
        ], true)->getValues();
        usort(
            $ucastnici,
            static fn (Participant $a, Participant $b): int => StringUtils::compareCzech(
                $a->getSortableName(),
                $b->getSortableName(),
            ),
        );

        return $ucastnici;
    }
}
