<?php

namespace OswisOrg\OswisCalendarBundle\Filter;

use ApiPlatform\Doctrine\Orm\Filter\AbstractFilter;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use Doctrine\ORM\QueryBuilder;
use ErrorException;

final class ParentEventFilter extends AbstractFilter
{
    public function getDescription(string $resourceClass): array
    {
        return [
            'recursiveEventId' => [
                'property' => 'recursiveEventId',
                'type' => 'string',
                'required' => false,
                'swagger' => [
                    'description' => "Event filter with recursion on superEvent property.",
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
        if ('recursiveEventId' !== $property || null === $value) {
            return;
        }
        $alias = $queryBuilder->getRootAliases()[0] ?? throw new ErrorException("Can't find root alias for DB query.");
        $eventQuery = " $alias.event = :event_id ";
        $queryBuilder->leftJoin("$alias.event", 'e0');
        $recursiveDepth = 5;
        for ($i = 0; $i < $recursiveDepth; $i++) {
            $j = $i + 1;
            $queryBuilder->leftJoin("e$i.superEvent", "e$j");
            $eventQuery .= " OR e$j = :event_id ";
        }
        $queryBuilder->andWhere($eventQuery)->setParameter('event_id', $value);
    }
}
