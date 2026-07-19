<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\ApiPlatform;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\HttpOperation;
use ApiPlatform\Metadata\Operation;
use Doctrine\ORM\QueryBuilder;
use OswisOrg\OswisCalendarBundle\Entity\Event\EventCategory;
use OswisOrg\OswisCalendarBundle\Entity\Event\EventStaffAssignment;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Scopuje endpoint `/program_service_roster` (tabulka služeb) na service-eventy JEDNOHO turnusu.
 *
 * Endpoint sdílí entitu {@see EventStaffAssignment} (zvolená varianta B — žádný nový API resource,
 * jen lean serializační grupa `calendar_service_roster`), takže dotaz je potřeba zúžit:
 *   1. jen service-kategorie ({@see EventCategory::SERVICE_TYPES}) — ostatní přiřazení (obsazení
 *      programových aktivit) do rozpisu služeb nepatří;
 *   2. jen směny daného turnusu — service-eventy zakládá zápisová cesta jako PŘÍMÉ podakce turnusu
 *      (`event.superEvent = turnus`), takže scoping je jedno-úrovňový přes IDENTITY (bez joinu navíc);
 *   3. jen nesmazané service-eventy (Event NENÍ Gedmo SoftDeleteable → ruční `deletedAt IS NULL`).
 *
 * Turnus přichází jako `?turnus=<id>`. Bez platného turnusu vrací PRÁZDNO (`1 = 0`) — endpoint je
 * vždy scopnutý na jeden turnus a nikdy nesmí omylem vrátit služby napříč všemi turnusy. Gate-uje se
 * na `uriTemplate`, aby toto zúžení neovlivnilo běžnou kolekci přiřazení
 * (`/api/event_staff_assignments`). Registrace tagem `query_extension.collection` v services.yaml,
 * vzor: ostatní query extensions v tomto adresáři (např. {@see OnlyMineEventsExtension}).
 */
final class ServiceRosterScopeExtension implements QueryCollectionExtensionInterface
{
    public function __construct(
        private readonly RequestStack $requestStack,
    ) {
    }

    public function applyToCollection(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        ?Operation $operation = null,
        ?array $context = [],
    ): void {
        if (EventStaffAssignment::class !== $resourceClass
            || !$operation instanceof HttpOperation
            || !str_contains((string) $operation->getUriTemplate(), 'program_service_roster')) {
            return;
        }
        $rootAlias = $queryBuilder->getRootAliases()[0];
        $turnusId = $this->requestStack->getCurrentRequest()?->query->get('turnus');
        if (!is_string($turnusId) || !ctype_digit($turnusId)) {
            // Bez platného turnusu neriskujeme rozpis přes všechny turnusy — vrátíme prázdno.
            $queryBuilder->andWhere('1 = 0');

            return;
        }
        $eventAlias = $queryNameGenerator->generateJoinAlias('svcEvent');
        $catAlias = $queryNameGenerator->generateJoinAlias('svcCat');
        $queryBuilder
            ->join("$rootAlias.event", $eventAlias)
            ->join("$eventAlias.category", $catAlias)
            ->andWhere("IDENTITY($eventAlias.superEvent) = :rosterTurnus")
            ->andWhere("$catAlias.type IN (:rosterServiceTypes)")
            ->andWhere("$eventAlias.deletedAt IS NULL")
            ->setParameter('rosterTurnus', (int) $turnusId)
            ->setParameter('rosterServiceTypes', EventCategory::SERVICE_TYPES);
    }
}
