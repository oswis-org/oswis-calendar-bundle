<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Twig\Extension;

use OswisOrg\OswisCalendarBundle\Entity\Event\Event;
use OswisOrg\OswisCalendarBundle\Repository\Participant\ParticipantRepository;
use OswisOrg\OswisCalendarBundle\Service\Event\EventService;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Admin úvod — LEHKÉ dashboard staty (STOPA 2.2, v1). Vědomě jen LEVNÉ COUNT dotazy (žádné načítání
 * a iterace účastníků na každém načtení úvodu — plné agregace/peníze má on-demand stránka „Agregace",
 * {@see EventAggregationsService}, která načítá celý graf). v1 = počet přihlášek default akce + per-turnus.
 */
final class AdminDashboardExtension extends AbstractExtension
{
    public function __construct(
        private readonly EventService $eventService,
        private readonly ParticipantRepository $participantRepository,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('oswis_admin_dashboard', $this->dashboard(...)),
        ];
    }

    /**
     * POZOR na counts (user 2026-07-13): používáme VÝHRADNĚ `countAttendeesGroupedBySubEvent` — REÁLNÝ
     * SQL `COUNT(p.id)` group-by s ověřenými filtry (`pc.type = attendee`, `p.deletedAt IS NULL`,
     * `e.superEvent = default`). NIKDY `countParticipants()` — ta dělá `getParticipants(...)->count()`,
     * tj. NAČTE všechny účastníky a počítá v PHP (+ filterCollection) → drahé i nekonzistentní.
     * Total = součet per-turnus. Čísla ověřena proti raw SQL i proti autoritativnímu seznamu přihlášek.
     *
     * @return array{
     *     event: Event|null, total: int, turnuses: list<array{event: Event, count: int}>,
     *     duplicates: list<array{contactId: int, name: string, count: int}>,
     *     missingSummary: list<array{id: int, name: string, registered: ?\DateTimeInterface}>
     * }
     */
    public function dashboard(): array
    {
        $event = $this->eventService->getDefaultEvent();
        if (!$event instanceof Event) {
            return ['event' => null, 'total' => 0, 'turnuses' => [], 'duplicates' => [], 'missingSummary' => []];
        }

        // JEDEN group-by SQL COUNT přes přímé sub-eventy (turnusy) default akce.
        $counts = $this->participantRepository->countAttendeesGroupedBySubEvent($event);
        $turnuses = [];
        $total = 0;
        foreach ($event->getSubEvents() as $sub) {
            if (null === $sub->getId()) {
                continue;
            }
            $count = $counts[(int) $sub->getId()] ?? 0;
            $turnuses[] = ['event' => $sub, 'count' => $count];
            $total += $count;
        }

        return [
            'event'      => $event,
            'total'      => $total,
            'turnuses'   => $turnuses,
            // Jeden group-by COUNT navíc — stejně levné jako staty výš, žádná hydratace.
            'duplicates' => $this->participantRepository->findDuplicateRegistrations($event),
            // Lidé BEZ pokynů k platbě. Shrnutí nemá opakování (na rozdíl od potvrzení plateb),
            // takže jediné, co je odhalí, je tenhle výpis. Skalární SELECT s NOT EXISTS,
            // strop 20 řádků — žádná hydratace, stejná cena jako staty výš.
            'missingSummary' => $this->participantRepository->findMissingSummary($event),
        ];
    }
}
