<?php

namespace OswisOrg\OswisCalendarBundle\Service\Aggregations;

use Doctrine\DBAL\Exception;
use Doctrine\ORM\EntityManagerInterface;
use OswisOrg\OswisCalendarBundle\Entity\Event\Event;
use OswisOrg\OswisCalendarBundle\Repository\Event\EventRepository;

/**
 * @phpstan-type AggregationDay array{
 *     year?: string|null,
 *     date?: string|null,
 *     date_human?: string|null,
 *     month_day?: string|null,
 *     fake_date?: string|null,
 *     created_attendees?: int|string,
 *     created_attendees_sum?: int|null,
 *     deleted_attendees?: int|string,
 *     deleted_attendees_sum?: int|null,
 *     active_attendees?: int|null,
 *     active_attendees_sum?: int|null
 * }
 * @phpstan-type AggregationYear array{
 *     event: Event,
 *     days: list<AggregationDay>,
 *     created_attendees_chart?: string,
 *     created_attendees_sum_chart?: string,
 *     deleted_attendees_chart?: string,
 *     deleted_attendees_sum_chart?: string,
 *     active_attendees_chart?: string,
 *     active_attendees_sum_chart?: string,
 *     created_attendees_sum?: int,
 *     deleted_attendees_sum?: int,
 *     active_attendees_sum?: int
 * }
 */
readonly class AggregationsService
{
    public function __construct(
        private EventRepository        $eventRepository,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Returns aggregations indexed by year.
     *
     * Each year holds the {@see Event} and a list of per-day registration counts
     * (one row per calendar day) produced by the SQL UNION below. Mutated by the
     * controller to add chart strings and running sums.
     *
     * @return array<int, AggregationYear>
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

            // Per-day counts of created and deleted attendees (category id=1).
            // We then UNION two perspectives (created LEFT JOIN deleted, created RIGHT JOIN deleted)
            // so the result row set covers both creation-only and deletion-only days.
            // Active count is derived in PHP as `created - deleted` (per day) and accumulated.
            $sql = <<<SQL

            WITH
            created AS (
                SELECT
                    DATE_FORMAT(created_at, '%Y') AS created_year,
                    DATE_FORMAT(created_at, '%Y-%m-%d') AS created_date,
                    COUNT(*) AS participants_count
                FROM `calendar_participant`
                WHERE event_id IN {$eventIdsString} AND participant_category_id=1 AND created_at IS NOT NULL
                GROUP BY DATE_FORMAT(created_at, '%Y-%m-%d')
            ),
            deleted AS (
                SELECT
                    DATE_FORMAT(created_at, '%Y') AS created_year,
                    DATE_FORMAT(deleted_at, '%Y-%m-%d') AS deleted_date,
                    COUNT(*) AS participants_count
                FROM `calendar_participant`
                WHERE event_id IN {$eventIdsString} AND participant_category_id=1 AND deleted_at IS NOT NULL
                GROUP BY DATE_FORMAT(deleted_at, '%Y-%m-%d')
            ),
            results1 AS (
                SELECT
                    COALESCE(created.created_year, deleted.created_year) AS year,
                    COALESCE(created.created_date, deleted.deleted_date) AS date,
                    DATE_FORMAT(COALESCE(created.created_date, deleted.deleted_date), '%e. %c. %Y') AS date_human,
                    DATE_FORMAT(COALESCE(created.created_date, deleted.deleted_date), '%e. %c.') AS month_day,
                    DATE_FORMAT(COALESCE(created.created_date, deleted.deleted_date), '1970-%m-%dT00:00:00') AS fake_date,
                    COALESCE(created.participants_count, 0) AS created_attendees,
                    NULL AS created_attendees_sum,
                    COALESCE(deleted.participants_count, 0) AS deleted_attendees,
                    NULL AS deleted_attendees_sum
                FROM created
                LEFT OUTER JOIN deleted ON created.created_date = deleted.deleted_date
            ),
            results2 AS (
                SELECT
                    COALESCE(created.created_year, deleted.created_year) AS year,
                    COALESCE(created.created_date, deleted.deleted_date) AS date,
                    DATE_FORMAT(COALESCE(created.created_date, deleted.deleted_date), '%e. %c. %Y') AS date_human,
                    DATE_FORMAT(COALESCE(created.created_date, deleted.deleted_date), '%e. %c.') AS month_day,
                    DATE_FORMAT(COALESCE(created.created_date, deleted.deleted_date), '1970-%m-%dT00:00:00') AS fake_date,
                    COALESCE(created.participants_count, 0) AS created_attendees,
                    NULL AS created_attendees_sum,
                    COALESCE(deleted.participants_count, 0) AS deleted_attendees,
                    NULL AS deleted_attendees_sum
                FROM created
                RIGHT OUTER JOIN deleted ON created.created_date = deleted.deleted_date
            )
            SELECT * FROM results1
            UNION
            SELECT * FROM results2
            ORDER BY date
SQL;

            if (null === $year) {
                continue;
            }
            /** @var list<AggregationDay> $days */
            $days = $this->entityManager->getConnection()->executeQuery($sql)->fetchAllAssociative();
            $aggregations[$year] = [
                'event' => $event,
                'days' => $days,
            ];
        }
        return $aggregations;
    }
}
