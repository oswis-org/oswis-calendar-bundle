<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Repository\Imap;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use OswisOrg\OswisCalendarBundle\Entity\Imap\ParticipantIncomingMail;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;

/**
 * @extends ServiceEntityRepository<ParticipantIncomingMail>
 */
class ParticipantIncomingMailRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ParticipantIncomingMail::class);
    }

    /**
     * Returns ANY ParticipantIncomingMail with the given Message-ID,
     * regardless of which participant it links to. Used as a quick
     * existence probe — for participant-specific dedup use
     * `findOneByMessageIdAndParticipant`.
     */
    public function findOneByMessageId(string $messageId): ?ParticipantIncomingMail
    {
        return $this->findOneBy(['messageId' => $messageId]);
    }

    public function findOneByMessageIdAndParticipant(string $messageId, Participant $participant): ?ParticipantIncomingMail
    {
        return $this->findOneBy(['messageId' => $messageId, 'participant' => $participant]);
    }

    /**
     * @return list<ParticipantIncomingMail>
     */
    public function findByParticipant(Participant $participant): array
    {
        $result = $this->createQueryBuilder('inc')
            ->andWhere('inc.participant = :participant')
            ->setParameter('participant', $participant)
            ->orderBy('inc.occurredAt', 'DESC')
            ->getQuery()
            ->getResult();

        $rows = [];
        if (is_array($result)) {
            foreach ($result as $row) {
                if ($row instanceof ParticipantIncomingMail) {
                    $rows[] = $row;
                }
            }
        }

        return $rows;
    }
}
