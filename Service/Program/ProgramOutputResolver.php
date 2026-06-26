<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Service\Program;

use OswisOrg\OswisCalendarBundle\Entity\Event\Event;
use OswisOrg\OswisCalendarBundle\Export\SubEventAttendanceExportDefinition;
use OswisOrg\OswisCalendarBundle\Repository\Event\EventRepository;
use OswisOrg\OswisCalendarBundle\Repository\Event\ProgramDayRepository;
use OswisOrg\OswisCalendarBundle\Repository\Participant\ParticipantRepository;
use OswisOrg\OswisCalendarBundle\Repository\Participant\SubEventAttendanceRepository;
use OswisOrg\OswisCoreBundle\Enum\ExportFormat;
use OswisOrg\OswisCoreBundle\Export\ExportManager;
use OswisOrg\OswisCoreBundle\Export\ExportRequest;
use OswisOrg\OswisCoreBundle\Export\ExportResult;

/**
 * Maps a program-output "type" (+ the relevant entity id) to a rendered {@see ExportResult}
 * (filename + MIME + binary). The single dispatch point shared by both exposure channels —
 * the web admin controller and the JWT API endpoint (STOPA 1.3 Fáze 7) — so neither duplicates
 * the type→output wiring. Returns null when the requested entity is not found (caller → 404).
 */
final class ProgramOutputResolver
{
    public const string TYPE_INSTRUCTOR = 'instruktorsky';
    public const string TYPE_ROSTER     = 'sluzby';
    public const string TYPE_DAY        = 'den';
    public const string TYPE_ITINERARY  = 'itinerar';
    public const string TYPE_TEAM       = 'itinerar-tym';
    public const string TYPE_SIGNUP     = 'arch';
    public const string TYPE_ATTENDEES  = 'prihlaseni';
    public const string TYPE_FULL       = 'cely-program';

    public function __construct(
        private readonly ProgramOutputService $output,
        private readonly ExportManager $exportManager,
        private readonly SubEventAttendanceExportDefinition $attendanceDefinition,
        private readonly SubEventAttendanceRepository $attendanceRepository,
        private readonly EventRepository $eventRepository,
        private readonly ProgramDayRepository $programDayRepository,
        private readonly ParticipantRepository $participantRepository,
    ) {
    }

    /** Resolve the turnus by numeric id (preferred — Ionic admin) or slug (web admin / API). */
    public function resolveTurnus(?string $slug, ?int $id): ?Event
    {
        if (null !== $id && $id > 0) {
            return $this->eventRepository->getEvent([EventRepository::CRITERIA_ID => $id]);
        }

        return null !== $slug && '' !== $slug
            ? $this->eventRepository->getEvent([EventRepository::CRITERIA_SLUG => $slug])
            : null;
    }

    /**
     * @param string $stamp 'Y-m-d_His' timestamp for the download filename (passed in so the
     *                       resolver itself stays time-pure)
     */
    public function render(string $type, Event $turnus, ?int $entityId, ExportFormat $format, string $stamp): ?ExportResult
    {
        return match ($type) {
            self::TYPE_INSTRUCTOR => $this->pdf($this->output->instructorProgramPdf($turnus), "instruktorsky-program_$stamp.pdf"),
            self::TYPE_ROSTER     => $this->pdf($this->output->serviceRosterPdf($turnus), "rozpis-sluzeb_$stamp.pdf"),
            self::TYPE_TEAM       => $this->pdf($this->output->teamOverviewPdf($turnus), "prehled-tymu_$stamp.pdf"),
            self::TYPE_FULL       => $this->pdf($this->output->participantFullProgramPdf($turnus), "cely-program_$stamp.pdf"),
            self::TYPE_DAY        => $this->renderDay($turnus, $entityId, $stamp),
            self::TYPE_ITINERARY  => $this->renderItinerary($turnus, $entityId, $stamp),
            self::TYPE_SIGNUP     => $this->renderSignup($entityId, $stamp),
            self::TYPE_ATTENDEES  => $this->renderAttendees($entityId, $format),
            default               => null,
        };
    }

    private function renderDay(Event $turnus, ?int $dayId, string $stamp): ?ExportResult
    {
        $day = null !== $dayId ? $this->programDayRepository->find($dayId) : null;
        $date = $day?->getDate()?->format('Y-m-d');
        if (null === $date) {
            return null;
        }

        return $this->pdf($this->output->participantDayPdf($turnus, $date), "denni-program_{$date}_$stamp.pdf");
    }

    private function renderItinerary(Event $turnus, ?int $participantId, string $stamp): ?ExportResult
    {
        $participant = null !== $participantId ? $this->participantRepository->find($participantId) : null;
        if (null === $participant) {
            return null;
        }

        return $this->pdf($this->output->instructorItineraryPdf($turnus, $participant), "itinerar-{$participantId}_$stamp.pdf");
    }

    private function renderSignup(?int $activityId, string $stamp): ?ExportResult
    {
        $activity = null !== $activityId ? $this->eventRepository->find($activityId) : null;
        if (null === $activity) {
            return null;
        }

        return $this->pdf($this->output->signupSheetPdf($activity), "zapisovy-arch-{$activityId}_$stamp.pdf");
    }

    private function renderAttendees(?int $activityId, ExportFormat $format): ?ExportResult
    {
        $activity = null !== $activityId ? $this->eventRepository->find($activityId) : null;
        if (null === $activity) {
            return null;
        }
        $attendees = $this->attendanceRepository->getActiveByEvent($activity);

        return $this->exportManager->render(
            $this->attendanceDefinition,
            $attendees,
            new ExportRequest($format, null, $activity->getName()),
        );
    }

    private function pdf(string $content, string $filename): ExportResult
    {
        return new ExportResult($filename, 'application/pdf', $content);
    }
}
