<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\ApiPlatform;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use Doctrine\ORM\QueryBuilder;
use OswisOrg\OswisCalendarBundle\Entity\Participant\SubEventAttendance;
use OswisOrg\OswisCalendarBundle\Repository\Participant\ParticipantRepository;
use OswisOrg\OswisCalendarBundle\Service\Participant\ParticipantService;
use OswisOrg\OswisCoreBundle\Entity\AppUser\AppUser;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Scope SubEventAttendance queries to current user's own rows.
 * ROLE_MANAGER + ROLE_ADMIN bypass to see everything.
 *
 * Spec: docs/superpowers/specs/2026-05-22-S2-S3-S4-calendar-ux-2.0-design.md S3 step 4.1.2
 */
final class OwnSubEventAttendanceExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly ParticipantService $participantService,
    ) {
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
        if ($resourceClass !== SubEventAttendance::class) {
            return;
        }
        // ⚠️ Zrušené přihlášení na aktivitu ven. `SubEventAttendance::cancel()` nastavuje
        // `status = canceled` **i** `deletedAt`, ale globální filtr `softdeleteable` zapnutý není
        // ([[reference_softdeleteable_filter_not_enabled]]) — takže se zrušené záznamy vracely dál.
        // Neprojevilo se to jen proto, že si aplikace vždy přidává `?status=registered`; to je ale
        // druhá pravda na klientovi, kterou stačí jednou zapomenout. Filtr platí i pro tým.
        $rootAlias = $queryBuilder->getRootAliases()[0];
        $queryBuilder->andWhere("$rootAlias.deletedAt IS NULL");
        if ($this->security->isGranted('ROLE_MANAGER')
            || $this->security->isGranted('ROLE_ADMIN')) {
            return;
        }
        $user = $this->security->getUser();
        if (!$user instanceof AppUser) {
            $queryBuilder->andWhere('1 = 0');

            return;
        }
        $participants = $this->participantService->getParticipants(
            [ParticipantRepository::CRITERIA_APP_USER => $user]
        );
        $ids = [];
        foreach ($participants as $p) {
            $id = $p->getId();
            if (null !== $id) {
                $ids[] = $id;
            }
        }
        if ([] === $ids) {
            $queryBuilder->andWhere('1 = 0');

            return;
        }
        $queryBuilder->andWhere("$rootAlias.participant IN (:ownParticipantIds)")
                     ->setParameter('ownParticipantIds', $ids);
    }
}
