<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Repository\ParticipantNote;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Entity\ParticipantNote\ParticipantManualNote;

/**
 * @extends ServiceEntityRepository<ParticipantManualNote>
 */
class ParticipantManualNoteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ParticipantManualNote::class);
    }

    /**
     * @return list<ParticipantManualNote>
     */
    public function findByParticipant(Participant $participant): array
    {
        $result = $this->createQueryBuilder('note')
            ->andWhere('note.participant = :participant')
            ->setParameter('participant', $participant)
            ->orderBy('note.occurredAt', 'DESC')
            ->addOrderBy('note.id', 'DESC')
            ->getQuery()
            ->getResult();

        if (!is_array($result)) {
            return [];
        }
        $rows = [];
        foreach ($result as $row) {
            if ($row instanceof ParticipantManualNote) {
                $rows[] = $row;
            }
        }

        return $rows;
    }
}
