<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\ApiPlatform;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use Doctrine\ORM\QueryBuilder;
use OswisOrg\OswisCalendarBundle\Entity\Meal\Meal;
use OswisOrg\OswisCalendarBundle\Entity\Meal\MealVariant;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Repository\Participant\ParticipantRepository;
use OswisOrg\OswisCalendarBundle\Service\Participant\ParticipantService;
use OswisOrg\OswisCoreBundle\Entity\AppUser\AppUser;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Kdo uvidí který jídelníček.
 *
 * Účastník vidí jídla **svého turnusu** a jejich varianty; tým (ROLE_MANAGER+) vidí všechno.
 * Jídelníček není tajný, ale cizí turnus účastníkovi k ničemu není a jen by mátl.
 *
 * ⚠️ **Smazaná jídla ven pro VŠECHNY role, hned na začátku.** Globální filtr `softdeleteable`
 * v OSWIS zapnutý NENÍ ([[reference_softdeleteable_filter_not_enabled]]), takže si to musí ohlídat
 * každý zdroj sám — a když se to zapomene, API vrací zrušené záznamy jako platné. Přesně tak se
 * 2026-08-05 zjistilo, že se z produkce vracelo 555 zrušených přihlášek. Únik ven je
 * `?includeDeleted=1`, stejná konvence jako u přihlášek a akcí.
 *
 * U variant se navíc kontroluje i **jídlo**: smazané jídlo nesmí protáhnout své varianty dál.
 */
final class MealVisibleToUserExtension implements
    QueryCollectionExtensionInterface,
    QueryItemExtensionInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly ParticipantService $participantService,
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
        $jeVarianta = MealVariant::class === $resourceClass;
        if (Meal::class !== $resourceClass && !$jeVarianta) {
            return;
        }
        $alias = $queryBuilder->getRootAliases()[0];
        // U varianty potřebujeme jídlo pro obě věci níž (smazanost i turnus) — join jednou.
        $aliasJidla = $alias;
        if ($jeVarianta) {
            $aliasJidla = 'meal_of_variant';
            $queryBuilder->leftJoin("$alias.meal", $aliasJidla);
        }

        if (!$this->zobrazitSmazane()) {
            $queryBuilder->andWhere("$aliasJidla.deletedAt IS NULL");
            if ($jeVarianta) {
                $queryBuilder->andWhere("$alias.deletedAt IS NULL");
            }
        }

        // Tým vidí i cizí turnusy — potřebuje je při sestavování jídelníčku.
        if ($this->security->isGranted('ROLE_MANAGER')
            || $this->security->isGranted('ROLE_ADMIN')
            || $this->security->isGranted('ROLE_ROOT')) {
            return;
        }
        $user = $this->security->getUser();
        if (!$user instanceof AppUser) {
            $queryBuilder->andWhere('1 = 0');

            return;
        }
        $eventIds = array_values(array_filter(array_map(
            static fn (Participant $p) => $p->getEvent()?->getId(),
            $this->participantService->getParticipants(
                [ParticipantRepository::CRITERIA_APP_USER => $user],
            )->toArray(),
        )));
        if ([] === $eventIds) {
            $queryBuilder->andWhere('1 = 0');

            return;
        }
        $queryBuilder
            ->andWhere("$aliasJidla.event IN (:moje_akce)")
            ->setParameter('moje_akce', $eventIds);
    }

    /**
     * `?includeDeleted=1` — vědomé vyžádání smazaných (obnova v adminu).
     * Stejná konvence jako u přihlášek/akcí, aby se to nemuselo u každého zdroje hádat.
     */
    private function zobrazitSmazane(): bool
    {
        $request = $this->requestStack->getCurrentRequest();

        return null !== $request && '' !== (string) $request->query->get('includeDeleted', '');
    }
}
