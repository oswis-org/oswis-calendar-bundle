<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\ApiPlatform;

use ApiPlatform\Doctrine\Orm\Filter\AbstractFilter;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use DateTimeImmutable;
use Doctrine\ORM\QueryBuilder;

/**
 * `?range=upcoming|past|today|thisweek|thismonth` convenience filter on Event.
 *
 * Spec: docs/superpowers/specs/2026-05-22-S2-S3-S4-calendar-ux-2.0-design.md S2 step 4.1.4
 */
final class EventRangeFilter extends AbstractFilter
{
    protected function filterProperty(
        string $property,
        mixed $value,
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        ?Operation $operation = null,
        array $context = [],
    ): void {
        if ('range' !== $property || !is_string($value)) {
            return;
        }

        $rootAlias = $queryBuilder->getRootAliases()[0];
        $now = new DateTimeImmutable();

        switch ($value) {
            case 'upcoming':
                $queryBuilder->andWhere("$rootAlias.endDateTime >= :rangeNow")->setParameter('rangeNow', $now);
                break;
            case 'past':
                $queryBuilder->andWhere("$rootAlias.endDateTime < :rangeNow")->setParameter('rangeNow', $now);
                break;
            case 'today':
                $today = $now->setTime(0, 0);
                $endToday = $today->modify('+1 day');
                $queryBuilder
                    ->andWhere("$rootAlias.startDateTime < :rangeEndToday")
                    ->andWhere("$rootAlias.endDateTime >= :rangeToday")
                    ->setParameter('rangeToday', $today)
                    ->setParameter('rangeEndToday', $endToday);
                break;
            case 'thisweek':
                $weekStart = $now->modify('Monday this week')->setTime(0, 0);
                $weekEnd = $weekStart->modify('+7 days');
                $queryBuilder
                    ->andWhere("$rootAlias.startDateTime < :rangeWeekEnd")
                    ->andWhere("$rootAlias.endDateTime >= :rangeWeekStart")
                    ->setParameter('rangeWeekStart', $weekStart)
                    ->setParameter('rangeWeekEnd', $weekEnd);
                break;
            case 'thismonth':
                $monthStart = $now->modify('first day of this month')->setTime(0, 0);
                $monthEnd = $monthStart->modify('first day of next month');
                $queryBuilder
                    ->andWhere("$rootAlias.startDateTime < :rangeMonthEnd")
                    ->andWhere("$rootAlias.endDateTime >= :rangeMonthStart")
                    ->setParameter('rangeMonthStart', $monthStart)
                    ->setParameter('rangeMonthEnd', $monthEnd);
                break;
        }
    }

    public function getDescription(string $resourceClass): array
    {
        return [
            'range' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Convenience filter: upcoming | past | today | thisweek | thismonth',
            ],
        ];
    }
}
