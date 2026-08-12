<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Repository\Meal;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use OswisOrg\OswisCalendarBundle\Entity\Meal\ParticipantMealChoice;

/**
 * @extends ServiceEntityRepository<ParticipantMealChoice>
 */
final class ParticipantMealChoiceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ParticipantMealChoice::class);
    }

    /**
     * Dvojice (jídlo, varianta) pro dané přihlášky — podklad pro kuchyňský součet.
     *
     * ⚠️ Okruh lidí se ZÁMĚRNĚ předává zvenčí (`$participantIds`), místo aby si ho dotaz vybíral
     * sám podle turnusu: kdo se počítá jako účastník (typ, aktivní, nesmazaný) je pravidlo, které
     * už žije v `ParticipantRepository`, a druhá kopie v tomhle dotazu by se s ním časem rozešla —
     * kuchyň by pak dostala jiná čísla než seznam dietářů na téže stránce.
     *
     * Nevrací počty, ale řádky: sečíst je v PHP je triviální a `GROUP BY` by tu jen skryl,
     * že jedna přihláška má na jedno jídlo právě jednu volbu (drží to unikátní klíč).
     *
     * @param list<int> $participantIds
     *
     * @return list<array{meal: int, variant: int}>
     */
    public function findChoiceKeys(array $participantIds): array
    {
        if ([] === $participantIds) {
            return [];
        }
        /** @var list<array{meal: int|string, variant: int|string}> $rows */
        $rows = $this->createQueryBuilder('choice')
            ->select('IDENTITY(choice.meal) AS meal', 'IDENTITY(choice.variant) AS variant')
            ->andWhere('choice.participant IN (:ids)')
            ->setParameter('ids', $participantIds)
            ->getQuery()
            ->getArrayResult();

        return array_map(
            static fn (array $row): array => ['meal' => (int) $row['meal'], 'variant' => (int) $row['variant']],
            $rows,
        );
    }
}
