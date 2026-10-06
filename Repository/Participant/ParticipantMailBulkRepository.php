<?php

namespace OswisOrg\OswisCalendarBundle\Repository\Participant;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use OswisOrg\OswisCalendarBundle\Entity\ParticipantMail\ParticipantMailBulk;

/** @extends ServiceEntityRepository<ParticipantMailBulk> */
class ParticipantMailBulkRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ParticipantMailBulk::class);
    }

    /**
     * Bulks that still need draining (queued or mid-flight) and are DUE (čas „odeslat po" uplynul), oldest first.
     * Zrušené a naplánované do budoucna se nevrací (dávka 3.2). Výčtem POVOLENÝCH stavů, ne zakázaných:
     * koncept (dávka 3.3) i jakýkoli budoucí stav se tak k odeslání nedostane omylem.
     *
     * @return list<ParticipantMailBulk>
     */
    public function findPending(int $limit = 20, ?\DateTimeImmutable $now = null): array
    {
        $rows = $this->createQueryBuilder('b')
            ->where('b.status IN (:active)')
            ->andWhere('b.sendAfter IS NULL OR b.sendAfter <= :now')
            ->setParameter('active', [ParticipantMailBulk::STATUS_QUEUED, ParticipantMailBulk::STATUS_SENDING])
            ->setParameter('now', $now ?? new \DateTimeImmutable())
            ->orderBy('b.createdAt', 'ASC')
            ->setMaxResults(max(1, $limit))
            ->getQuery()
            ->getResult();

        return array_values(
            array_filter(
                is_array($rows) ? $rows : [],
                static fn (mixed $row): bool => $row instanceof ParticipantMailBulk,
            ),
        );
    }

    /**
     * Most recent bulks (any status except drafts), newest first — for the status page.
     *
     * @return list<ParticipantMailBulk>
     */
    public function findRecent(int $limit = 30): array
    {
        $rows = $this->createQueryBuilder('b')
            ->where('b.status <> :draft')
            ->setParameter('draft', ParticipantMailBulk::STATUS_DRAFT)
            ->orderBy('b.createdAt', 'DESC')
            ->setMaxResults(max(1, $limit))
            ->getQuery()
            ->getResult();

        return array_values(
            array_filter(
                is_array($rows) ? $rows : [],
                static fn (mixed $row): bool => $row instanceof ParticipantMailBulk,
            ),
        );
    }

    /**
     * Rozepsané koncepty, naposledy upravené první (dávka 3.3).
     *
     * @return list<ParticipantMailBulk>
     */
    public function findDrafts(int $limit = 50): array
    {
        $rows = $this->createQueryBuilder('b')
            ->where('b.status = :draft')
            ->setParameter('draft', ParticipantMailBulk::STATUS_DRAFT)
            ->orderBy('b.updatedAt', 'DESC')
            ->setMaxResults(max(1, $limit))
            ->getQuery()
            ->getResult();

        return array_values(
            array_filter(
                is_array($rows) ? $rows : [],
                static fn (mixed $row): bool => $row instanceof ParticipantMailBulk,
            ),
        );
    }

    /**
     * Zamkne koncept pro uložení nebo odeslání: v JEDNOM příkazu ověří, že je to pořád koncept s revizí, ze které autor
     * vycházel, a revizi zvedne. Dva souběžné požadavky (dvě okna, automatické uložení + „Odeslat") tak projde jen
     * jeden; druhý dostane null a autor uvidí, že se koncept mezitím změnil.
     *
     * @return int|null nová revize, nebo null (koncept neexistuje, už není koncept, nebo ho mezitím změnil někdo jiný)
     */
    public function zamknoutKoncept(int $id, int $revision): ?int
    {
        $changed = $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE calendar_participant_mail_bulk SET revision = revision + 1 WHERE id = ? AND status = ? AND revision = ?',
            [$id, ParticipantMailBulk::STATUS_DRAFT, $revision],
        );

        return 1 === $changed ? $revision + 1 : null;
    }

    final public function findOneBy(array $criteria, ?array $orderBy = null): ?ParticipantMailBulk
    {
        $result = parent::findOneBy($criteria, $orderBy);

        return $result instanceof ParticipantMailBulk ? $result : null;
    }
}
