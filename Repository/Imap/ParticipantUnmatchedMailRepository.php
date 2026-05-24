<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Repository\Imap;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use OswisOrg\OswisCalendarBundle\Entity\Imap\ParticipantUnmatchedMail;

/**
 * @extends ServiceEntityRepository<ParticipantUnmatchedMail>
 */
class ParticipantUnmatchedMailRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ParticipantUnmatchedMail::class);
    }

    public function findOneByMessageId(string $messageId): ?ParticipantUnmatchedMail
    {
        return $this->findOneBy(['messageId' => $messageId]);
    }
}
