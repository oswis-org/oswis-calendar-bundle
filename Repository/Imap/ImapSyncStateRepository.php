<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Repository\Imap;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use OswisOrg\OswisCalendarBundle\Entity\Imap\ImapSyncState;

/**
 * @extends ServiceEntityRepository<ImapSyncState>
 */
class ImapSyncStateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ImapSyncState::class);
    }

    public function getOrCreate(string $folder): ImapSyncState
    {
        $state = $this->findOneBy(['folder' => $folder]);
        if (!$state instanceof ImapSyncState) {
            $state = new ImapSyncState($folder);
            $this->getEntityManager()->persist($state);
        }

        return $state;
    }
}
