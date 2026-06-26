<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\ApiPlatform;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use Doctrine\ORM\QueryBuilder;
use OswisOrg\OswisCalendarBundle\Entity\Event\EventSection;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Scope EventSection queries: ROLE_CUSTOMER sees only app-public sections
 * (publicInApp = true); ROLE_MANAGER + ROLE_ADMIN bypass to see everything
 * (incl. internal/draft sections) for the program editor.
 *
 * Spec: docs/superpowers/plans/2026-06-13-program-module-api.md (Fáze 3.2).
 */
final class EventSectionVisibleExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
{
    public function __construct(private readonly Security $security)
    {
    }

    public function applyToCollection(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        ?Operation $operation = null,
        ?array $context = [],
    ): void {
        $this->addWhere($queryBuilder, $resourceClass);
    }

    public function applyToItem(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        array $identifiers,
        ?Operation $operation = null,
        array $context = [],
    ): void {
        $this->addWhere($queryBuilder, $resourceClass);
    }

    private function addWhere(QueryBuilder $queryBuilder, string $resourceClass): void
    {
        if ($resourceClass !== EventSection::class
            || $this->security->isGranted('ROLE_MANAGER')
            || $this->security->isGranted('ROLE_ADMIN')) {
            return;
        }
        $rootAlias = $queryBuilder->getRootAliases()[0];
        $queryBuilder->andWhere("$rootAlias.publicInApp = true");
    }
}
