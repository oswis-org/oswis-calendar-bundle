<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Repository\Event;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use OswisOrg\OswisCalendarBundle\Entity\Event\Event;
use OswisOrg\OswisCalendarBundle\Entity\Event\EventSection;

/**
 * @extends ServiceEntityRepository<EventSection>
 */
class EventSectionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EventSection::class);
    }

    /**
     * Informační sekce daného turnusu, seřazené dle priority (sestupně), pak dle názvu.
     *
     * @return list<EventSection>
     */
    public function getByEvent(Event $event): array
    {
        $result = $this->createQueryBuilder('s')
            ->where('s.event = :event')
            ->setParameter('event', $event)
            ->addOrderBy('s.priority', 'DESC')
            ->addOrderBy('s.name', 'ASC')
            ->getQuery()
            ->getResult();

        return is_array($result) ? array_values(array_filter(
            $result,
            static fn (mixed $row): bool => $row instanceof EventSection,
        )) : [];
    }
}
