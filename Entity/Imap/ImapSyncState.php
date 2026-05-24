<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Entity\Imap;

use DateTime;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Table;
use OswisOrg\OswisCalendarBundle\Repository\Imap\ImapSyncStateRepository;
use OswisOrg\OswisCoreBundle\Traits\Common\BasicTrait;

/**
 * Tracks the last UID seen per IMAP folder so re-running oswis:imap:fetch
 * is idempotent and incremental.
 */
#[Entity(repositoryClass: ImapSyncStateRepository::class)]
#[Table(name: 'calendar_imap_sync_state')]
class ImapSyncState
{
    use BasicTrait;

    #[Column(type: 'string', length: 100, unique: true)]
    protected string $folder;

    #[Column(type: 'integer', options: ['default' => 0])]
    protected int $lastSeenUid = 0;

    #[Column(type: 'datetime', nullable: true)]
    protected ?DateTime $lastFetchAt = null;

    public function __construct(string $folder)
    {
        $this->folder = $folder;
    }

    public function getFolder(): string
    {
        return $this->folder;
    }

    public function getLastSeenUid(): int
    {
        return $this->lastSeenUid;
    }

    public function setLastSeenUid(int $lastSeenUid): void
    {
        $this->lastSeenUid = $lastSeenUid;
    }

    public function getLastFetchAt(): ?DateTime
    {
        return $this->lastFetchAt;
    }

    public function setLastFetchAt(?DateTime $lastFetchAt): void
    {
        $this->lastFetchAt = $lastFetchAt;
    }
}
