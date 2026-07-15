<?php

namespace OswisOrg\OswisCalendarBundle\Controller\WebAdmin;

use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use OswisOrg\OswisCalendarBundle\Entity\Event\Event;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantCategory;
use OswisOrg\OswisCalendarBundle\Repository\Event\EventRepository;
use OswisOrg\OswisCalendarBundle\Repository\Participant\ParticipantRepository;
use OswisOrg\OswisCalendarBundle\Service\CheckIn\CheckInService;
use OswisOrg\OswisCalendarBundle\Service\Document\OperationalDocumentService;
use OswisOrg\OswisCoreBundle\Service\ExportService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Check-in obrazovka per turnus (modul D2, Fáze A — {@see docs/OSWIS_1_CHECKIN_MODULE_ANALYSIS_2026-07-13.md}).
 * Seznam účastníků turnusu s rychlým označením příjezdu, present-count/no-show, řazením (dietáři-first dle
 * `ParticipantGroup.mealOrder` / pásek dle barvy / abecedně) a viditelným partial-stay + platbou.
 * Odjezd se per-osoba NEřeší (hromadný) — jen výjimky (dřívější odjezd) přes departedAt.
 */
#[IsGranted('ROLE_MANAGER')]
final class WebAdminCheckInController extends AbstractController
{
    /** Strop počtu vypsaných jmen no-show na přehledu (zbytek jen číslem) — lehký render. */
    private const int PROGRESS_NOSHOW_CAP = 80;

    public function __construct(
        private readonly ParticipantRepository $participantRepository,
        private readonly EventRepository $eventRepository,
        private readonly EntityManagerInterface $em,
        private readonly ExportService $exportService,
        private readonly OperationalDocumentService $documentService,
        private readonly CheckInService $checkInService,
    ) {
    }

    /**
     * Přehled průběhu příjezdu pro organizátorku (vzdálené sledování): kolik přijelo/nedorazilo + kolik
     * prošlo kterou stanicí (reálný SQL COUNT přes {@see CheckInService::getPipeline}) + seznam no-show
     * (capnutý, read-only jména). Read-only obrazovka, auto-refresh 30 s.
     */
    public function progress(string $eventSlug): Response
    {
        $event = $this->resolveEvent($eventSlug);
        $participants = $this->loadSortedParticipants($event, 'alpha');
        $total = count($participants);
        $arrived = 0;
        $noShowNames = [];
        foreach ($participants as $p) {
            if ($p->isArrived()) {
                ++$arrived;
            } elseif (count($noShowNames) < self::PROGRESS_NOSHOW_CAP) {
                // Read-only jméno (getContactForRead — bez mutace/L2, viz feedback_getname_mutates_l2cache_oom).
                $noShowNames[] = $p->getContactForRead()?->getName() ?? ('#'.$p->getId());
            }
        }
        $noShow = $total - $arrived;
        $title = 'Průběh příjezdu — '.($event->getName() ?? $eventSlug);

        return $this->render('@OswisOrgOswisCalendar/web_admin/check-in-progress.html.twig', [
            'event'        => $event,
            'eventSlug'    => $eventSlug,
            'total'        => $total,
            'arrived'      => $arrived,
            'noShow'       => $noShow,
            'noShowNames'  => $noShowNames,
            'noShowMore'   => max(0, $noShow - count($noShowNames)),
            'pipeline'     => $this->checkInService->getPipeline($event),
            'page_title'   => $title.' :: ADMIN',
            'pageTitle'    => $title,
        ]);
    }

    public function list(Request $request, string $eventSlug): Response
    {
        $event = $this->resolveEvent($eventSlug);
        $sort = (string) $request->query->get('sort', 'diet');
        $participants = $this->loadSortedParticipants($event, $sort);

        $arrived = 0;
        foreach ($participants as $p) {
            if ($p->isArrived()) {
                ++$arrived;
            }
        }
        $total = count($participants);
        $title = 'Check-in — '.($event->getName() ?? $eventSlug);

        return $this->render('@OswisOrgOswisCalendar/web_admin/check-in.html.twig', [
            'event'        => $event,
            'eventSlug'    => $eventSlug,
            'participants' => $participants,
            'total'        => $total,
            'arrived'      => $arrived,
            'noShow'       => $total - $arrived,
            'sort'         => $sort,
            'page_title'   => $title,
            'pageTitle'    => $title,
        ]);
    }

    /**
     * Tisknutelný seznam pro fyzickou evidenci (papírový fallback je load-bearing — tým ho drží u stolu,
     * ne-app účastníci, wifi/baterie). Landscape A4.
     */
    public function listPdf(Request $request, string $eventSlug): Response
    {
        $event = $this->resolveEvent($eventSlug);
        $sort = (string) $request->query->get('sort', 'diet');
        $participants = $this->loadSortedParticipants($event, $sort);
        $eventName = $event->getName() ?? $eventSlug;
        $arrived = 0;
        foreach ($participants as $p) {
            if ($p->isArrived()) {
                ++$arrived;
            }
        }

        $html = $this->renderView('@OswisOrgOswisCalendar/web_admin/check-in-print.html.twig', [
            'eventName'    => $eventName,
            'participants' => $participants,
            'arrived'      => $arrived,
            'total'        => count($participants),
        ]);
        $pdf = $this->exportService->getPdfFromHtml($html, true, 'Check-in — '.$eventName);

        return new Response($pdf, Response::HTTP_OK, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="check-in-'.$eventSlug.'.pdf"',
        ]);
    }

    /**
     * Bezpečnostní listy k podpisu — 1 předvyplněné prohlášení na stranu (papírový fallback:
     * tisk → podpis → archiv). Bez parametru celý turnus (hromadný tisk); s `?participant=<id>`
     * jen jeden účastník (stanice bezpečnost / dotisk jednotlivce). #212 F3 / check-in §7.
     */
    public function safetyListPdf(Request $request, string $eventSlug): Response
    {
        $event = $this->resolveEvent($eventSlug);

        $only = null;
        $pid = $request->query->get('participant');
        if (is_string($pid) && ctype_digit($pid)) {
            $candidate = $this->em->find(Participant::class, (int) $pid);
            if ($candidate instanceof Participant && !$candidate->isDeleted()) {
                $only = $candidate;
            }
        }

        $pdf = $this->documentService->safetyListPdf($event, $only);
        $suffix = null !== $only ? 'ucastnik-'.$only->getId() : $eventSlug;

        return new Response($pdf, Response::HTTP_OK, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="bezpecnostni-list-'.$suffix.'.pdf"',
        ]);
    }

    /**
     * Seznam účastníků dle skupiny/pásku — sekce per skupina (pořadí výdeje jídla), jméno · telefon ·
     * dieta. Papírový fallback pro stanici pásky + výdej stravy (kuchyň). #212 F4.
     */
    public function bandListPdf(string $eventSlug): Response
    {
        $event = $this->resolveEvent($eventSlug);
        $pdf = $this->documentService->bandListPdf($event);

        return new Response($pdf, Response::HTTP_OK, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="seznam-pasky-'.$eventSlug.'.pdf"',
        ]);
    }

    private function resolveEvent(string $eventSlug): Event
    {
        return $this->eventRepository->getEvent([EventRepository::CRITERIA_SLUG => $eventSlug])
            ?? throw $this->createNotFoundException("Turnus '$eventSlug' nenalezen.");
    }

    /**
     * Načte účastníky turnusu, prime-uje LAZY kolekce (flagy/platby — jinak N+1) a seřadí.
     *
     * @return list<Participant>
     */
    private function loadSortedParticipants(Event $event, string $sort): array
    {
        // POZOR na konzistenci počtů (user 2026-07-13): filtrujeme na TYPE_ATTENDEE, aby headcount
        // check-inu (total/přítomní/no-show) byl BY-CONSTRUCTION shodný s dashboardem „přihlášky"
        // ({@see AdminDashboardExtension} → countAttendeesGroupedBySubEvent, také jen attendee).
        // Organizátoři/tým jsou účastníci ROČNÍKOVÉ (parent) akce, ne turnusu, takže dnes je to no-op —
        // ale drží obě obrazovky ve shodě, i kdyby někdo někdy zaregistroval ne-attendee přímo na turnus.
        // deletedAt IS NULL a dedup řeší getParticipants/filterCollection.
        /** @var list<Participant> $participants */
        $participants = $this->participantRepository->getParticipants([
            ParticipantRepository::CRITERIA_EVENT                 => $event,
            ParticipantRepository::CRITERIA_EVENT_RECURSIVE_DEPTH => 0,
            ParticipantRepository::CRITERIA_PARTICIPANT_TYPE      => ParticipantCategory::TYPE_ATTENDEE,
        ], true)->getValues();

        $ids = [];
        foreach ($participants as $p) {
            if (null !== $p->getId()) {
                $ids[] = $p->getId();
            }
        }
        if ([] !== $ids) {
            $this->participantRepository->primeAggregationCollections($ids);
        }

        return $this->sortParticipants($participants, $sort);
    }

    public function markArrived(Request $request, int $participantId): Response
    {
        return $this->toggleTimestamp($request, $participantId, 'arrived');
    }

    public function markDeparted(Request $request, int $participantId): Response
    {
        return $this->toggleTimestamp($request, $participantId, 'departed');
    }

    public function markTShirt(Request $request, int $participantId): Response
    {
        return $this->toggleTimestamp($request, $participantId, 'tshirt');
    }

    private function toggleTimestamp(Request $request, int $participantId, string $which): Response
    {
        if (!$this->isCsrfTokenValid("checkin_{$which}_{$participantId}", (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Neplatný CSRF token.');
        }
        $participant = $this->em->find(Participant::class, $participantId);
        if (!$participant instanceof Participant || $participant->isDeleted()) {
            throw $this->createNotFoundException('Účastník nenalezen.');
        }
        // Toggle: neoznačený → nyní; označený → zrušit (překlik omylem).
        match ($which) {
            'arrived' => $participant->setArrivedAt($participant->isArrived() ? null : new DateTime()),
            'tshirt'  => $participant->setTShirtHandedOverAt($participant->isTShirtHandedOver() ? null : new DateTime()),
            default   => $participant->setDepartedAt(null !== $participant->getDepartedAt() ? null : new DateTime()),
        };
        $this->em->flush();

        return new RedirectResponse($this->safeBackToList($request, (string) $participant->getEvent()?->getSlug()));
    }

    /**
     * @param list<Participant> $participants
     *
     * @return list<Participant>
     */
    private function sortParticipants(array $participants, string $sort): array
    {
        $byName = static fn(Participant $a, Participant $b): int => strcmp(
            (string) $a->getContact()?->getName(),
            (string) $b->getContact()?->getName(),
        );
        usort($participants, match ($sort) {
            'band' => static function (Participant $a, Participant $b) use ($byName): int {
                $ca = (string) $a->getGroup()?->getColor();
                $cb = (string) $b->getGroup()?->getColor();

                return $ca === $cb ? $byName($a, $b) : strcmp($ca, $cb);
            },
            'alpha' => $byName,
            // default 'diet': skupina s nižším mealOrder první (dietáři jdou na jídlo první),
            // NULL mealOrder (bez skupiny) na konec; v rámci skupiny abecedně.
            default => static function (Participant $a, Participant $b) use ($byName): int {
                $ma = $a->getGroup()?->getMealOrder() ?? PHP_INT_MAX;
                $mb = $b->getGroup()?->getMealOrder() ?? PHP_INT_MAX;

                return $ma === $mb ? $byName($a, $b) : $ma <=> $mb;
            },
        });

        return $participants;
    }

    private function safeBackToList(Request $request, string $eventSlug): string
    {
        $return = (string) $request->request->get('return', '');
        if (str_starts_with($return, '/web_admin/') && !str_contains($return, "\n") && !str_contains($return, "\r")) {
            return $return;
        }

        return $this->generateUrl('oswis_org_oswis_calendar_web_admin_checkin_list', ['eventSlug' => $eventSlug]);
    }
}
