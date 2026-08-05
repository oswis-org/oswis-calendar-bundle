<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\ApiPlatform;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use Doctrine\ORM\QueryBuilder;
use OswisOrg\OswisCalendarBundle\Entity\Announcement\Announcement;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Repository\Participant\ParticipantRepository;
use OswisOrg\OswisCalendarBundle\Service\Participant\ParticipantService;
use OswisOrg\OswisCoreBundle\Entity\AppUser\AppUser;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Kdo uvidí který vzkaz z nástěnky.
 *
 * Účastník vidí příspěvek, když je **zveřejněný** (`publishedAt` nastaveno a už nastalo) a míří
 * na něj: na jeho turnus (bez zúžení) · na jeho skupinu/pásku · přímo na něj.
 *
 * ⚠️ Rozepsaný příspěvek (`publishedAt = NULL`) účastník NEVIDÍ — tým si tak může vzkaz připravit
 * dopředu. Tým (ROLE_MANAGER+) vidí všechno, jinak by si rozepsané příspěvky nemohl spravovat.
 *
 * Psáno podle `EventVisibleToUserExtension`, ale poučeně: **každá větev musí mít podmínku
 * zveřejnění**. U akcí měla jedna větev vypadlou kontrolu `publicInApp` a účastníci díky tomu
 * viděli interní body týmu (opraveno 2026-08-03).
 */
final class AnnouncementVisibleToUserExtension implements
    QueryCollectionExtensionInterface,
    QueryItemExtensionInterface
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
        ?array $context = [],
    ): void {
        $this->addWhere($queryBuilder, $resourceClass);
    }

    private function addWhere(QueryBuilder $queryBuilder, string $resourceClass): void
    {
        if (Announcement::class !== $resourceClass
            || $this->security->isGranted('ROLE_MANAGER')
            || $this->security->isGranted('ROLE_ADMIN')
            || $this->security->isGranted('ROLE_ROOT')) {
            return;
        }
        $user = $this->security->getUser();
        if (!$user instanceof AppUser) {
            $queryBuilder->andWhere('1 = 0');

            return;
        }

        $participants = $this->participantService->getParticipants(
            [ParticipantRepository::CRITERIA_APP_USER => $user],
        )->toArray();
        $eventIds = array_values(array_filter(array_map(
            static fn (Participant $p) => $p->getEvent()?->getId(),
            $participants,
        )));
        $groupIds = array_values(array_filter(array_map(
            static fn (Participant $p) => $p->getGroup()?->getId(),
            $participants,
        )));
        $participantIds = array_values(array_filter(array_map(
            static fn (Participant $p) => $p->getId(),
            $participants,
        )));
        if ([] === $eventIds) {
            $queryBuilder->andWhere('1 = 0');

            return;
        }

        $alias = $queryBuilder->getRootAliases()[0];
        // Zveřejnění platí pro VŠECHNY větve — proto je mimo závorku s cílením.
        $queryBuilder
            ->andWhere("$alias.publishedAt IS NOT NULL")
            ->andWhere("$alias.publishedAt <= :ted")
            ->andWhere("$alias.deletedAt IS NULL")
            ->andWhere(sprintf(
                '(%s.event IN (:moje_akce) AND (%s.targetGroup IS NULL OR %s.targetGroup IN (:moje_skupiny))'
                .' AND (%s.participant IS NULL OR %s.participant IN (:ja)))',
                $alias,
                $alias,
                $alias,
                $alias,
                $alias,
            ))
            ->setParameter('ted', new \DateTime())
            ->setParameter('moje_akce', $eventIds)
            // Prázdné pole by v IN () neodpovídalo ničemu, což je u „nemám pásku" správně:
            // vzkaz cílený na skupinu se mi neukáže, dokud žádnou nemám.
            ->setParameter('moje_skupiny', $groupIds)
            ->setParameter('ja', $participantIds);
    }
}
