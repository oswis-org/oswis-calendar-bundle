<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Service\Program;

use OswisOrg\OswisCalendarBundle\Entity\Event\Event;
use OswisOrg\OswisCalendarBundle\Entity\Event\EventStaffAssignment;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Repository\Event\EventSectionRepository;
use OswisOrg\OswisCalendarBundle\Repository\Event\EventStaffAssignmentRepository;
use OswisOrg\OswisCalendarBundle\Repository\Event\ProgramDayRepository;
use OswisOrg\OswisCalendarBundle\Repository\Participant\StaffTeamRepository;
use OswisOrg\OswisCalendarBundle\Repository\Participant\SubEventAttendanceRepository;
use OswisOrg\OswisCalendarBundle\Twig\Extension\ProgramExtension;

/**
 * Assembles a turnus' program data for the STOPA 1.3 outputs as read-only arrays (NOT
 * entities into templates, where lazy-load N+1 or getName() L2-cache mutation could bite —
 * scope is per turnus, so the graph stays small). Output services + Twig templates render
 * these arrays.
 *
 * Spec: docs/superpowers/specs/2026-06-12-program-module-design.md sekce 2.4.
 */
final class ProgramDataService
{
    private const int RECURSION_DEPTH = 5;

    public function __construct(
        private readonly ProgramDayRepository $programDayRepository,
        private readonly EventStaffAssignmentRepository $assignmentRepository,
        private readonly SubEventAttendanceRepository $attendanceRepository,
        private readonly StaffTeamRepository $teamRepository,
        private readonly EventSectionRepository $sectionRepository,
        private readonly ProgramExtension $names,
    ) {
    }

    /**
     * Days (ProgramDay by date) × the activities scheduled on that date. Each activity carries
     * its direct sub-activities (a block → rotations). Activities not matching any ProgramDay's
     * date land under an `null` day key bucket so nothing is silently dropped.
     *
     * @return array<int, array{day: array<string, mixed>|null, activities: list<array<string, mixed>>}>
     */
    public function getProgramTree(Event $turnus): array
    {
        $activities = [];
        foreach ($turnus->getSubEvents() as $sub) {
            $activities[] = $this->activityArray($sub);
        }
        $sortKey = static fn (array $a): string => (is_string($a['date'] ?? null) ? $a['date'] : '')
            . ' ' . (is_string($a['start'] ?? null) ? $a['start'] : '');
        usort($activities, static fn (array $a, array $b): int => $sortKey($a) <=> $sortKey($b));

        // Roman numbering for same-activity series ("Lukostřelba I./II./…"), by chronological order.
        $bySeries = [];
        foreach ($activities as $index => $activity) {
            $seriesId = $activity['seriesId'] ?? null;
            if (is_int($seriesId)) {
                $bySeries[$seriesId][] = $index;
            }
        }
        foreach ($bySeries as $indexes) {
            if (count($indexes) < 2) {
                continue;
            }
            $number = 1;
            foreach ($indexes as $index) {
                $activities[$index]['seriesRoman'] = $this->names->roman($number++);
            }
        }

        $tree = [];
        $usedIndexes = [];
        foreach ($this->programDayRepository->getByEvent($turnus) as $day) {
            $dateObj = $day->getDate();
            if (null === $dateObj) {
                continue;
            }
            $date = $dateObj->format('Y-m-d');
            $dayActivities = [];
            foreach ($activities as $index => $activity) {
                if ($activity['date'] === $date) {
                    $dayActivities[] = $activity;
                    $usedIndexes[$index] = true;
                }
            }
            $tree[] = [
                'day' => ['id' => $day->getId(), 'date' => $date, 'name' => $day->getName()],
                'activities' => $dayActivities,
            ];
        }
        $orphans = [];
        foreach ($activities as $index => $activity) {
            if (!isset($usedIndexes[$index])) {
                $orphans[] = $activity;
            }
        }
        if ([] !== $orphans) {
            $tree[] = ['day' => null, 'activities' => $orphans];
        }

        return $tree;
    }

    /**
     * Matrix date → service category (Event category name) → list of assignee display names.
     *
     * @return array<string, array<string, list<array<string, mixed>>>>
     */
    public function getStaffMatrix(Event $turnus): array
    {
        $matrix = [];
        foreach ($this->collectAssignments($turnus) as $assignment) {
            $event = $assignment->getEvent();
            if (null === $event) {
                continue;
            }
            $date = $event->getStartDateTimeRecursive()?->format('Y-m-d') ?? '—';
            $category = $event->getCategory()?->getName() ?? $event->getName() ?? '—';
            $matrix[$date][$category][] = $this->assignmentArray($assignment);
        }
        ksort($matrix);

        return $matrix;
    }

    /**
     * Activities where the instructor serves — directly (EventStaffAssignment.participant) or
     * through a StaffTeam they belong to — excluding `excluded` assignments. Chronological.
     *
     * @return list<array<string, mixed>>
     */
    public function getInstructorItinerary(Event $turnus, Participant $instructor): array
    {
        $teamIds = [];
        foreach ($this->teamRepository->getByEvent($turnus) as $team) {
            $id = $team->getId();
            if (null !== $id && $team->getMembers()->contains($instructor)) {
                $teamIds[$id] = true;
            }
        }
        $rows = [];
        foreach ($this->collectAssignments($turnus) as $assignment) {
            if ($assignment->isExcluded()) {
                continue;
            }
            $teamId = $assignment->getTeam()?->getId();
            $byParticipant = $assignment->getParticipant() === $instructor;
            $byTeam = null !== $teamId && isset($teamIds[$teamId]);
            if (!$byParticipant && !$byTeam) {
                continue;
            }
            $rows[] = $this->itineraryRow($assignment);
        }
        usort($rows, static fn (array $a, array $b): int => (is_string($a['start'] ?? null) ? $a['start'] : '') <=> (is_string($b['start'] ?? null) ? $b['start'] : ''));

        return $rows;
    }

    /**
     * One itinerary line for a staff assignment (date/time split out for clean day-grouped rendering).
     *
     * @return array<string, mixed>
     */
    private function itineraryRow(EventStaffAssignment $assignment): array
    {
        $event = $assignment->getEvent();
        $dateTime = $event?->getStartDateTimeRecursive();

        return [
            'start' => $dateTime?->format('Y-m-d H:i'),
            'date' => $dateTime?->format('Y-m-d'),
            'time' => $dateTime?->format('H:i'),
            'activity' => $event?->getName(),
            'placeText' => $event?->getPlaceText(),
            'roleLabel' => $assignment->getRoleLabel(),
        ];
    }

    /** Display name for a staff person (nickname / "Given F." / composed) — see ProgramExtension. */
    public function staffName(Participant $participant): string
    {
        return $this->names->staffName($participant);
    }

    /**
     * Team overview: every staff person (sub-teams expanded to individuals, externals included,
     * excluded assignments skipped) with their chronological slots. Each person appears once with
     * the activities they serve. Ordered by display name.
     *
     * @return list<array{name: string, rows: list<array<string, mixed>>}>
     */
    public function getTeamOverview(Event $turnus): array
    {
        $people = [];
        foreach ($this->collectAssignments($turnus) as $assignment) {
            if ($assignment->isExcluded()) {
                continue;
            }
            $row = $this->itineraryRow($assignment);
            $participant = $assignment->getParticipant();
            $team = $assignment->getTeam();
            $targets = [];
            if (null !== $participant) {
                $targets['p' . ($participant->getId() ?? spl_object_id($participant))] = $this->names->staffName($participant);
            } elseif (null !== $team) {
                foreach ($team->getMembers() as $member) {
                    $targets['p' . ($member->getId() ?? spl_object_id($member))] = $this->names->staffName($member);
                }
            } else {
                $external = (string) $assignment->getExternalName();
                if ('' !== $external) {
                    $targets['x' . $external] = $external;
                }
            }
            foreach ($targets as $key => $name) {
                $people[$key] ??= ['name' => $name, 'rows' => []];
                $people[$key]['rows'][] = $row;
            }
        }
        foreach ($people as &$person) {
            usort($person['rows'], static fn (array $a, array $b): int => (is_string($a['start'] ?? null) ? $a['start'] : '') <=> (is_string($b['start'] ?? null) ? $b['start'] : ''));
        }
        unset($person);
        $people = array_values($people);
        usort($people, static fn (array $a, array $b): int => $a['name'] <=> $b['name']);

        return $people;
    }

    /**
     * Active attendees of an activity: display name + paid flag.
     *
     * @return list<array<string, mixed>>
     */
    public function getActivityAttendees(Event $activity): array
    {
        $rows = [];
        foreach ($this->attendanceRepository->getActiveByEvent($activity) as $attendance) {
            $participant = $attendance->getParticipant();
            $rows[] = [
                'name' => null !== $participant ? $this->names->staffName($participant) : '',
                'phone' => $participant?->getContactForRead()?->getPhone(),
                'paid' => $attendance->isPaid(),
                'registeredAt' => $attendance->getRegisteredAt()->format('Y-m-d H:i'),
            ];
        }

        return $rows;
    }

    /**
     * Information sections of a turnus, ordered by priority. $publicOnly = true keeps only
     * `publicInApp` sections (participant outputs); false returns all (internal team outputs).
     *
     * @return list<array<string, mixed>>
     */
    public function getSections(Event $turnus, bool $publicOnly = false): array
    {
        $rows = [];
        foreach ($this->sectionRepository->getByEvent($turnus) as $section) {
            if ($publicOnly && true !== $section->isPublicInApp()) {
                continue;
            }
            $rows[] = [
                'id' => $section->getId(),
                'name' => $section->getName(),
                'textValue' => $section->getTextValue(),
                'icon' => $section->getIcon(),
                'priority' => $section->getPriority(),
                'publicInApp' => $section->isPublicInApp(),
            ];
        }
        usort($rows, static function (array $a, array $b): int {
            $pa = is_int($a['priority'] ?? null) ? $a['priority'] : 0;
            $pb = is_int($b['priority'] ?? null) ? $b['priority'] : 0;

            return $pb <=> $pa;
        });

        return $rows;
    }

    /**
     * @return list<EventStaffAssignment>
     */
    private function collectAssignments(Event $turnus): array
    {
        $events = [];
        $this->collectEvents($turnus, $events, 0);
        $assignments = [];
        foreach ($events as $event) {
            foreach ($this->assignmentRepository->getByEvent($event) as $assignment) {
                $assignments[] = $assignment;
            }
        }

        return $assignments;
    }

    /**
     * @param list<Event> $out
     */
    private function collectEvents(Event $event, array &$out, int $depth): void
    {
        if ($depth > self::RECURSION_DEPTH) {
            return;
        }
        foreach ($event->getSubEvents() as $sub) {
            $out[] = $sub;
            $this->collectEvents($sub, $out, $depth + 1);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function activityArray(Event $event): array
    {
        $subActivities = [];
        foreach ($event->getSubEvents() as $sub) {
            $subActivities[] = [
                'id' => $sub->getId(),
                'name' => $sub->getName(),
                'start' => $sub->getStartDateTimeRecursive()?->format('H:i'),
                'targetGroup' => $this->groupArray($sub),
            ];
        }
        $staff = [];
        foreach ($this->assignmentRepository->getByEvent($event) as $assignment) {
            if (!$assignment->isExcluded()) {
                $staff[] = $this->assignmentArray($assignment);
            }
        }
        $targetGroup = $event->getTargetGroup();
        $series = $event->getGroup();
        $seriesId = null !== $series && $series->isSameActivity() ? $series->getId() : null;

        return [
            'id' => $event->getId(),
            'name' => $event->getName(),
            'staff' => $staff,
            'seriesId' => $seriesId,
            'seriesRoman' => null,
            'date' => $event->getStartDateTimeRecursive()?->format('Y-m-d'),
            'start' => $event->getStartDateTimeRecursive()?->format('H:i'),
            'end' => $event->getEndDateTimeRecursive()?->format('H:i'),
            'placeText' => $event->getPlaceText(),
            'category' => $event->getCategory()?->getName(),
            'price' => $event->getPrice(),
            'baseCapacity' => $event->getBaseCapacity(),
            'fullCapacity' => $event->getFullCapacity(),
            'highlight' => $event->isHighlight(),
            'publicInApp' => $event->isPublicInApp(),
            'internalNote' => $event->getInternalNote(),
            'targetGroup' => null !== $targetGroup ? [
                'name' => $targetGroup->getName(),
                'color' => $targetGroup->getColor(),
            ] : null,
            'isBlock' => [] !== $subActivities,
            'subActivities' => $subActivities,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function groupArray(Event $event): ?array
    {
        $group = $event->getTargetGroup();

        return null !== $group ? ['name' => $group->getName(), 'color' => $group->getColor()] : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function assignmentArray(EventStaffAssignment $assignment): array
    {
        $participant = $assignment->getParticipant();

        return [
            'name' => null !== $participant ? $this->names->staffName($participant) : (string) $assignment->getExternalName(),
            'roleLabel' => $assignment->getRoleLabel(),
            'team' => $assignment->getTeam()?->getName(),
            'external' => null === $participant,
        ];
    }
}
