<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Service\Program;

use OswisOrg\OswisCalendarBundle\Entity\Event\Event;
use OswisOrg\OswisCalendarBundle\Entity\Event\EventCategory;
use OswisOrg\OswisCalendarBundle\Entity\Staff\StaffAssignment;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Repository\Event\EventSectionRepository;
use OswisOrg\OswisCalendarBundle\Repository\Staff\StaffAssignmentRepository;
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

    public function __construct(
        private readonly ProgramDayRepository $programDayRepository,
        private readonly StaffAssignmentRepository $assignmentRepository,
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
            if (null !== $sub->getDeletedAt()) {
                continue; // smazané (soft-delete) aktivity/bloky se v programu ani výstupech nezobrazují
            }
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
        foreach ($this->assignmentRepository->getServicesByTurnus($turnus) as $assignment) {
            $date = $assignment->getEffectiveStart()?->format('Y-m-d') ?? '—';
            $role = $assignment->getRole()?->getName() ?? '—';
            $matrix[$date][$role][] = $this->assignmentArray($assignment);
        }
        ksort($matrix);

        return $matrix;
    }

    /**
     * Rošt služeb pro editor/API — filtrováno na SERVICE kategorie, ČASOVĚ (ne půldny — user 2026-07-19).
     * Buňka (den × typ služby) nese časově seřazené SMĚNY; čas směny = časový rozsah service-Eventu.
     * Směna = 1 service-Event + jeho přiřazení (dvojice = víc přiřazení na 1 směně).
     *
     * @return array{
     *     days: list<string>,
     *     serviceTypes: list<array{type: string, name: string, color: string|null}>,
     *     cells: array<string, array<string, list<array<string, mixed>>>>
     * }
     */
    public function getServiceRoster(Event $turnus): array
    {
        $days = [];
        $types = [];
        $cells = [];
        // Nový model: služby = StaffAssignment bez konkrétní aktivity (activity NULL). Čas z getEffectiveSpan.
        foreach ($this->assignmentRepository->getServicesByTurnus($turnus) as $assignment) {
            $role = $assignment->getRole();
            $type = $role?->getType() ?? '—';
            $start = $assignment->getEffectiveStart();
            $date = $start?->format('Y-m-d') ?? '—';
            $days[$date] = true;
            $types[$type] = [
                'type' => $type,
                'name' => $role?->getName() ?? $type,
                'color' => $role?->getColor(),
            ];
            // Buňka (den × typ) = seznam řádků-závazků; dvojice (Gabča+Pája) = dva řádky se stejným časem.
            $cells[$date][$type][] = [
                'assignmentId' => $assignment->getId(),
                'start' => $start?->format('Y-m-d H:i'),
                'end' => $assignment->getEffectiveEnd()?->format('Y-m-d H:i'),
                'staffName' => $assignment->getStaffName(),
                'participantId' => $assignment->getParticipant()?->getId(),
                'team' => $assignment->getTeam()?->getName(),
                'external' => null === $assignment->getParticipant(),
            ];
        }
        foreach ($cells as $date => $byType) {
            foreach ($byType as $type => $rows) {
                usort(
                    $rows,
                    static fn (array $a, array $b): int => (is_string($a['start'] ?? null) ? $a['start'] : '')
                        <=> (is_string($b['start'] ?? null) ? $b['start'] : ''),
                );
                $cells[$date][$type] = $rows;
            }
        }
        ksort($days);
        ksort($types);

        return [
            'days' => array_keys($days),
            'serviceTypes' => array_values($types),
            'cells' => $cells,
        ];
    }

    /**
     * Activities where the instructor serves — directly (StaffAssignment.participant) or
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
    private function itineraryRow(StaffAssignment $assignment): array
    {
        $start = $assignment->getEffectiveStart();
        $activity = $assignment->getActivity();
        $role = $assignment->getRole();

        return [
            'start' => $start?->format('Y-m-d H:i'),
            'date' => $start?->format('Y-m-d'),
            'time' => $start?->format('H:i'),
            // U služby (bez aktivity) je „aktivitou" v itineráři samotná funkce (Řízení…).
            'activity' => $activity?->getName() ?? $role?->getName(),
            'placeText' => $activity?->getPlaceText(),
            'roleLabel' => $role?->getName(),
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
     * @return list<StaffAssignment>
     */
    private function collectAssignments(Event $turnus): array
    {
        // Nový model: závazky jsou vlastní entita scopnutá na turnus (ne procházení service-Eventů).
        return $this->assignmentRepository->getByTurnus($turnus);
    }

    /**
     * @return array<string, mixed>
     */
    private function activityArray(Event $event): array
    {
        $subActivities = [];
        foreach ($event->getSubEvents() as $sub) {
            if (null !== $sub->getDeletedAt()) {
                continue; // smazaný (soft-delete) slot rotace se nezobrazuje
            }
            // Sloty nesou i pole, která potřebuje účastnický výstup, až se blok „zploští" po časech
            // (viz ProgramOutputService::flattenBlocks). Přehled web adminu čte jen podmnožinu (↳ řádek).
            $subActivities[] = [
                'id' => $sub->getId(),
                'name' => $sub->getName(),
                'start' => $sub->getStartDateTimeRecursive()?->format('H:i'),
                'end' => $sub->getEndDateTimeRecursive()?->format('H:i'),
                'placeText' => $sub->getPlaceText(),
                'highlight' => $sub->isHighlight(),
                'publicInApp' => $sub->isPublicInApp(),
                'price' => $sub->getPrice(),
                'seriesRoman' => null,
                'targetGroup' => $this->groupArray($sub),
                'subActivities' => [],
            ];
        }
        $staff = [];
        foreach ($this->assignmentRepository->getByActivity($event) as $assignment) {
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
            // Blok = má podakce (strukturálně nadakce) NEBO je kategorie „program-block" — aby i PRÁZDNÝ
            // blok nabídl „+ podakce" (jinak by do něj nešlo přidat první podakci).
            'isBlock' => [] !== $subActivities || EventCategory::PROGRAM_BLOCK === $event->getCategory()?->getType(),
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
    private function assignmentArray(StaffAssignment $assignment): array
    {
        return [
            'id' => $assignment->getId(),
            'name' => $assignment->getStaffName() ?? '',
            'participantId' => $assignment->getParticipant()?->getId(),
            'roleLabel' => $assignment->getRole()?->getName(),
            'team' => $assignment->getTeam()?->getName(),
            'external' => null === $assignment->getParticipant(),
        ];
    }
}
