<?php
/**
 * @noinspection PhpComposerExtensionStubsInspection
 * @noinspection MissingParameterTypeDeclarationInspection
 * @noinspection ForeachInvariantsInspection
 */

namespace OswisOrg\OswisCalendarBundle\Filter;

use ApiPlatform\Core\Bridge\Doctrine\Orm\Filter\AbstractContextAwareFilter;
use ApiPlatform\Core\Bridge\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use Doctrine\ORM\QueryBuilder;
use OswisOrg\OswisCalendarBundle\Entity\Event\Event;

final class ParentEventFilter extends AbstractContextAwareFilter
{
    public function getDescription(string $resourceClass): array
    {
        $description['recursive_event'] = [
            'property' => 'recursiveEvent',
            'type'     => Event::class,
            'required' => false,
            'swagger'  => [
                'description' => "Event filter with recursion on superEvent property.",
            ],
        ];

        return $description;
    }

    public function filterProperty(
        string $property,
        mixed $value,
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        string $operationName = null
    ): void {
        if ('recursiveEvent' !== $property || !$value instanceof Event) {
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
        $queryBuilder->andWhere($eventQuery)->setParameter('event_id', $value->getId());
    }
}
