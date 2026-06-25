<?php

namespace OswisOrg\OswisCalendarBundle\Filter;

use ApiPlatform\Doctrine\Orm\Filter\AbstractFilter;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use Doctrine\ORM\QueryBuilder;
use ErrorException;

/**
 * ?superEventId=<T> → all events that are DESCENDANTS of event T via the superEvent chain,
 * up to {@see self::RECURSIVE_DEPTH} levels deep (turnus → blok → podakce → …). The whole
 * program tree of a turnus, for the program editor and the participant app.
 *
 * Mirror image of {@see ParentEventFilter} (which walks UP from a Participant); this walks
 * DOWN the event tree. Does NOT include T itself, only its sub-events.
 */
final class SubEventFilter extends AbstractFilter
{
    private const int RECURSIVE_DEPTH = 5;

    public function getDescription(string $resourceClass): array
    {
        return [
            'superEventId' => [
                'property' => 'superEventId',
                'type' => 'string',
                'required' => false,
                'swagger' => [
                    'description' => 'Vrátí všechny pod-události (descendant) zadané události (turnusu) — celý program stromu.',
                ],
            ],
        ];
    }

    /**
     * @throws ErrorException
     */
    public function filterProperty(
        string $property,
        mixed $value,
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        ?Operation $operation = null,
        ?array $context = null,
    ): void {
        if ('superEventId' !== $property || null === $value) {
            return;
        }
        $alias = $queryBuilder->getRootAliases()[0] ?? throw new ErrorException("Can't find root alias for DB query.");
        $conditions = [];
        $prev = $alias;
        for ($i = 1; $i <= self::RECURSIVE_DEPTH; $i++) {
            $join = "subEvt$i";
            $queryBuilder->leftJoin("$prev.superEvent", $join);
            $conditions[] = "$join = :super_event_id";
            $prev = $join;
        }
        $queryBuilder->andWhere('('.implode(' OR ', $conditions).')')->setParameter('super_event_id', $value);
    }
}
