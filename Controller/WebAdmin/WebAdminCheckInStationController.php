<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Controller\WebAdmin;

use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use OswisOrg\OswisCalendarBundle\Entity\CheckIn\CheckInStation;
use OswisOrg\OswisCalendarBundle\Entity\Event\Event;
use OswisOrg\OswisCalendarBundle\Form\WebAdmin\CheckInStationEditType;
use OswisOrg\OswisCalendarBundle\Repository\CheckIn\CheckInStationRepository;
use OswisOrg\OswisCalendarBundle\Repository\Event\EventRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Web-admin KONFIGURACE check-in stanic per turnus (modul D2).
 *
 * Naplňuje rozhodnutí usera 2026-07-13: „kolik stanic = rozhodne se na místě → konfigurovatelné stanice".
 * Tým si tady z 7 kindů ({@see CheckInStation}) složí/upraví/přeuspořádá sadu stolů pro daný turnus.
 * Data-driven: tyhle řádky čte Ionic hub i stůl ({@see WebAdminCheckInController} je pole-based Fáze A seznam).
 */
#[IsGranted('ROLE_MANAGER')]
final class WebAdminCheckInStationController extends AbstractController
{
    public function __construct(
        private readonly EventRepository $eventRepository,
        private readonly EntityManagerInterface $em,
        private readonly CheckInStationRepository $stationRepository,
    ) {
    }

    /** Přehled stanic turnusu + přidání/úprava/smazání + klonování z jiného turnusu. */
    public function config(string $eventSlug): Response
    {
        $event = $this->resolveEvent($eventSlug);
        $title = 'Konfigurace check-in stanic — '.($event->getName() ?? $eventSlug);

        // Zdroje pro klonování = jiné turnusy, které už stanice mají.
        $sourceEvents = array_values(array_filter(
            $this->stationRepository->findEventsWithStations(),
            static fn(Event $e): bool => $e->getId() !== $event->getId(),
        ));

        return $this->render('@OswisOrgOswisCalendar/web_admin/check-in-stations.html.twig', [
            'event'        => $event,
            'eventSlug'    => $eventSlug,
            'stations'     => $this->stationRepository->getByEvent($event),
            'sourceEvents' => $sourceEvents,
            'page_title'   => $title.' :: ADMIN',
            'pageTitle'    => $title,
        ]);
    }

    /**
     * Naklonuje stanice z jiného turnusu (nastav jednou, každý rok naklonuj — entita je „klonovatelná
     * přes Klonovat ročník"). Přeskočí stanice, jejichž název už na cíli existuje (žádné duplicity).
     */
    public function cloneStations(Request $request, string $eventSlug): Response
    {
        if (!$this->isCsrfTokenValid('checkin_stations_clone_'.$eventSlug, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Neplatný CSRF token.');
        }
        $target = $this->resolveEvent($eventSlug);
        $redirect = new RedirectResponse($this->generateUrl(
            'oswis_org_oswis_calendar_web_admin_checkin_stations',
            ['eventSlug' => $eventSlug],
        ));

        $sourceId = $request->request->get('source');
        $source = is_string($sourceId) && ctype_digit($sourceId) ? $this->em->find(Event::class, (int) $sourceId) : null;
        if (!$source instanceof Event || $source->getId() === $target->getId()) {
            $this->addFlash('warning', 'Vyber platný zdrojový turnus.');

            return $redirect;
        }

        $existingNames = [];
        foreach ($this->stationRepository->getByEvent($target) as $s) {
            $existingNames[mb_strtolower((string) $s->getName())] = true;
        }

        $cloned = 0;
        foreach ($this->stationRepository->getByEvent($source) as $src) {
            if (isset($existingNames[mb_strtolower((string) $src->getName())])) {
                continue;
            }
            $copy = new CheckInStation(null, $src->getStationKind(), $src->getOrderNumber());
            $copy->setEvent($target);
            $copy->setName($src->getName());
            $copy->setShortName($src->getShortName());
            $copy->setIcon($src->getIcon());
            $copy->setCapturesValue($src->isCapturesValue());
            $copy->setValueLabel($src->getValueLabel());
            $copy->setValueOptions($src->getValueOptions());
            $copy->setRequiresOnline($src->isRequiresOnline());
            $copy->setFunctionalRole($src->getFunctionalRole());
            $this->em->persist($copy);
            ++$cloned;
        }
        $this->em->flush();
        $this->addFlash('success', sprintf('Naklonováno %d stanic z „%s".', $cloned, (string) $source->getName()));

        return $redirect;
    }

    /** Přidání (stationId null) i úprava (stationId) jedním formulářem — vzor WebAdminEventController::edit. */
    public function stationForm(Request $request, string $eventSlug, ?int $stationId = null): Response
    {
        $event = $this->resolveEvent($eventSlug);
        if (null !== $stationId) {
            $station = $this->em->find(CheckInStation::class, $stationId);
            if (!$station instanceof CheckInStation || $station->isDeleted()
                || $station->getEvent()?->getId() !== $event->getId()) {
                throw $this->createNotFoundException('Stanice nenalezena.');
            }
        } else {
            $station = new CheckInStation();
            $station->setEvent($event); // NotNull na event → nastavit PŘED validací
        }

        $form = $this->createForm(CheckInStationEditType::class, $station);
        // Předvyplnit NEmapovaný textarea z JSON pole (před handleRequest, ať POST přepíše).
        $form->get('valueOptionsText')->setData(implode("\n", $station->getValueOptions() ?? []));
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $rawData = $form->get('valueOptionsText')->getData();
            $raw = is_string($rawData) ? $rawData : '';
            $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
            $opts = array_values(array_filter(array_map('trim', $lines), static fn(string $x): bool => '' !== $x));
            $station->setValueOptions([] === $opts ? null : $opts);

            $this->em->persist($station);
            $this->em->flush();
            $this->addFlash('success', sprintf('Stanice „%s" uložena.', $station->getName() ?? ''));

            return new RedirectResponse($this->generateUrl(
                'oswis_org_oswis_calendar_web_admin_checkin_stations',
                ['eventSlug' => $eventSlug],
            ));
        }

        $title = (null === $stationId ? 'Nová stanice' : 'Upravit stanici').' — '.($event->getName() ?? $eventSlug);

        return $this->render('@OswisOrgOswisCalendar/web_admin/check-in-station-edit.html.twig', [
            'event'      => $event,
            'eventSlug'  => $eventSlug,
            'station'    => $station,
            'form'       => $form,
            'isNew'      => null === $stationId,
            'page_title' => $title.' :: ADMIN',
            'pageTitle'  => $title,
        ]);
    }

    /** Soft-delete stanice (CSRF, vzor WebAdminCheckInController toggly). */
    public function stationDelete(Request $request, string $eventSlug, int $stationId): Response
    {
        if (!$this->isCsrfTokenValid("checkin_station_delete_{$stationId}", (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Neplatný CSRF token.');
        }
        $station = $this->em->find(CheckInStation::class, $stationId);
        if ($station instanceof CheckInStation && !$station->isDeleted()) {
            $station->setDeletedAt(new DateTime());
            $this->em->flush();
            $this->addFlash('success', sprintf('Stanice „%s" smazána.', $station->getName() ?? ''));
        }

        return new RedirectResponse($this->generateUrl(
            'oswis_org_oswis_calendar_web_admin_checkin_stations',
            ['eventSlug' => $eventSlug],
        ));
    }

    private function resolveEvent(string $eventSlug): Event
    {
        return $this->eventRepository->getEvent([EventRepository::CRITERIA_SLUG => $eventSlug])
            ?? throw $this->createNotFoundException("Turnus '$eventSlug' nenalezen.");
    }
}
