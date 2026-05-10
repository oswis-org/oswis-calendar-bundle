<?php

namespace OswisOrg\OswisCalendarBundle\Service\Aggregations;

use Doctrine\DBAL\Exception;
use Doctrine\ORM\EntityManagerInterface;
use OswisOrg\OswisCalendarBundle\Entity\Event\Event;
use OswisOrg\OswisCalendarBundle\Repository\Event\EventRepository;

readonly class AggregationsService
{
    public function __construct(
        private EventRepository        $eventRepository,
        private EntityManagerInterface $entityManager,
    )
    {
    }

    /**
     * Returns aggregations indexed by year.
     *
     * Each year holds the {@see Event} and a list of per-day registration counts
     * (one row per calendar day) produced by the SQL UNION below. Mutated by the
     * controller to add chart strings and running sums.
     *
     * @return array<int|string, array{
     *     event: Event,
     *     days: list<array{
     *         year?: string|null,
     *         date?: string|null,
     *         date_human?: string|null,
     *         month_day?: string|null,
     *         fake_date?: string|null,
     *         created_attendees?: int|string,
     *         created_attendees_sum?: int|null,
     *         deleted_attendees?: int|string,
     *         deleted_attendees_sum?: int|null,
     *         not_deleted_attendees?: int|string,
     *         not_deleted_attendees_sum?: int|null,
     *         active_attendees_sum?: int|null
     *     }>,
     *     created_attendees_chart?: string,
     *     created_attendees_sum_chart?: string,
     *     deleted_attendees_chart?: string,
     *     deleted_attendees_sum_chart?: string,
     *     not_deleted_attendees_chart?: string,
     *     not_deleted_attendees_sum_chart?: string,
     *     active_attendees_chart?: string,
     *     active_attendees_sum_chart?: string,
     *     created_attendees_sum?: int,
     *     deleted_attendees_sum?: int,
     *     not_deleted_attendees_sum?: int,
     *     active_attendees_sum?: int
     * }>
     * @throws Exception
     */
    public function getAggregations(): array
    {
        $events = $this->eventRepository->getEvents(
            [EventRepository::CRITERIA_ONLY_ROOT => true,]
        );
        $aggregations = [];
        foreach ($events as $event) {
            assert($event instanceof Event);
            $year = $event->getStartYear();
            $eventIds = [$event->getId()];
            foreach ($event->getSubEvents() as $subEvent) {
                assert($subEvent instanceof Event);
                $eventIds[] = $subEvent->getId();
            }
            $eventIdsString = '(' . implode(', ', $eventIds) . ')';

            $sql = <<<SQL

            WITH
            created AS (
                SELECT
                    DATE_FORMAT(created_at, '%Y') as created_year,
                    DATE_FORMAT(created_at, '%Y-%m-%d') as created_date,
                    COUNT(*) as participants_count
                FROM `calendar_participant`
                WHERE event_id IN {$eventIdsString} AND participant_category_id=1 AND created_at IS NOT NULL
                GROUP BY DATE_FORMAT(created_at, '%Y-%m-%d')
                ORDER BY created_at
            ),

            deleted AS (
                SELECT
                    DATE_FORMAT(created_at, '%Y') as created_year,
                    DATE_FORMAT(deleted_at, '%Y-%m-%d') as deleted_date,
                    COUNT(*) as participants_count
                FROM `calendar_participant`
                WHERE event_id IN {$eventIdsString} AND participant_category_id=1 AND deleted_at IS NOT NULL
                GROUP BY DATE_FORMAT(deleted_at, '%Y-%m-%d')
                ORDER BY deleted_at
            ),

            not_deleted AS (
                SELECT
                    DATE_FORMAT(created_at, '%Y') as created_year,
                    DATE_FORMAT(created_at, '%Y-%m-%d') as created_date,
                    COUNT(*) as participants_count
                FROM `calendar_participant`
                WHERE event_id IN {$eventIdsString} AND participant_category_id=1 AND deleted_at IS NULL
                GROUP BY DATE_FORMAT(created_at, '%Y-%m-%d')
                ORDER BY created_at
            ),

            results1 AS (
                SELECT
                IF (created.created_year, created.created_year, IF (deleted.created_year, deleted.created_year, IF (not_deleted.created_year, not_deleted.created_year, NULL))) as year,
                IF (created.created_date, created.created_date, IF (deleted.deleted_date, deleted.deleted_date, IF (not_deleted.created_date, not_deleted.created_date, NULL))) as date,
                DATE_FORMAT(
                    IF (created.created_date, created.created_date, IF (deleted.deleted_date, deleted.deleted_date, IF (not_deleted.created_date, not_deleted.created_date, NULL))), '%e. %c. %Y'
                ) as date_human,
                DATE_FORMAT(
                    IF (created.created_date, created.created_date, IF (deleted.deleted_date, deleted.deleted_date, IF (not_deleted.created_date, not_deleted.created_date, NULL))), '%e. %c.'
                ) as month_day,
                DATE_FORMAT(
                    IF (created.created_date, created.created_date, IF (deleted.deleted_date, deleted.deleted_date, IF (not_deleted.created_date, not_deleted.created_date, NULL))), '1970-%m-%dT00:00:00'
                ) as fake_date,
                IF (created.participants_count, created.participants_count, 0) as created_attendees,
                NULL AS created_attendees_sum,
                IF (deleted.participants_count, deleted.participants_count, 0) as deleted_attendees,
                NULL AS deleted_attendees_sum,
                IF (not_deleted.participants_count, not_deleted.participants_count, 0) as not_deleted_attendees,
                NULL AS not_deleted_attendees_sum
                FROM created
                LEFT OUTER JOIN not_deleted ON created.created_date = not_deleted.created_date
                LEFT OUTER JOIN deleted ON created.created_date = deleted.deleted_date
                GROUP BY date
                ORDER BY date
            ),

            results2 AS (
                SELECT
                IF (created.created_year, created.created_year, IF (deleted.created_year, deleted.created_year, IF (not_deleted.created_year, not_deleted.created_year, NULL))) as year,
                IF (created.created_date, created.created_date, IF (deleted.deleted_date, deleted.deleted_date, IF (not_deleted.created_date, not_deleted.created_date, NULL))) as date,
                DATE_FORMAT(
                    IF (created.created_date, created.created_date, IF (deleted.deleted_date, deleted.deleted_date, IF (not_deleted.created_date, not_deleted.created_date, NULL))), '%e. %c. %Y'
                ) as date_human,
                DATE_FORMAT(
                    IF (created.created_date, created.created_date, IF (deleted.deleted_date, deleted.deleted_date, IF (not_deleted.created_date, not_deleted.created_date, NULL))), '%e. %c.'
                ) as month_day,
                DATE_FORMAT(
                    IF (created.created_date, created.created_date, IF (deleted.deleted_date, deleted.deleted_date, IF (not_deleted.created_date, not_deleted.created_date, NULL))), '1970-%m-%dT00:00:00'
                ) as fake_date,
                IF (created.participants_count, created.participants_count, 0) as created_attendees,
                NULL AS created_attendees_sum,
                IF (deleted.participants_count, deleted.participants_count, 0) as deleted_attendees,
                NULL AS deleted_attendees_sum,
                IF (not_deleted.participants_count, not_deleted.participants_count, 0) as not_deleted_attendees,
                NULL AS not_deleted_attendees_sum
                FROM created
                RIGHT OUTER JOIN deleted ON created.created_date = deleted.deleted_date
                LEFT OUTER JOIN not_deleted ON created.created_date = not_deleted.created_date
                GROUP BY date
                ORDER BY date
            )

            SELECT * FROM results1
            UNION
            SELECT * FROM results2
            ORDER BY date
SQL;

            if (null === $year) {
                continue;
            }
            /** @var list<array{
             *     year?: string|null,
             *     date?: string|null,
             *     date_human?: string|null,
             *     month_day?: string|null,
             *     fake_date?: string|null,
             *     created_attendees?: int|string,
             *     created_attendees_sum?: int|null,
             *     deleted_attendees?: int|string,
             *     deleted_attendees_sum?: int|null,
             *     not_deleted_attendees?: int|string,
             *     not_deleted_attendees_sum?: int|null
             * }> $days */
            $days = $this->entityManager->getConnection()->prepare($sql)->executeQuery()->fetchAllAssociative();
            $aggregations[$year] = [
                'event' => $event,
                'days' => $days,
            ];
        }
        return $aggregations;
    }

}
