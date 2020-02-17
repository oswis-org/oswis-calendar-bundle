<?php
/**
 * @noinspection MethodShouldBeFinalInspection
 * @noinspection PhpUnused
 */

namespace Zakjakub\OswisCalendarBundle\Repository;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use Exception;
use Zakjakub\OswisAddressBookBundle\Entity\AbstractClass\AbstractContact;
use Zakjakub\OswisCalendarBundle\Entity\Event\Event;
use Zakjakub\OswisCalendarBundle\Entity\EventParticipant\EventParticipant;
use Zakjakub\OswisCalendarBundle\Entity\EventParticipant\EventParticipantType;
use Zakjakub\OswisCoreBundle\Entity\AppUser;

class EventParticipantRepository extends EntityRepository
{
    public const CRITERIA_ID = 'id';
    public const CRITERIA_EVENT = 'event';
    public const CRITERIA_EVENT_RECURSIVE_DEPTH = 'eventRecursiveDepth';
    public const CRITERIA_PARTICIPANT_TYPE_OF_TYPE = 'participantTypeOfType';
    public const CRITERIA_PARTICIPANT_TYPE = 'participantType';
    public const CRITERIA_INCLUDE_DELETED = 'includeDeleted';
    public const CRITERIA_CONTACT = 'contact';
    public const CRITERIA_APP_USER = 'appUser';

    final public function findOneBy(array $criteria, array $orderBy = null): ?EventParticipant
    {
        $result = parent::findOneBy($criteria, $orderBy);

        return $result instanceof EventParticipant ? $result : null;
    }

    public function getEventParticipants(
        array $opts = [],
        ?bool $includeNotActivated = true,
        ?int $limit = null,
        ?int $offset = null
    ): Collection {
        $queryBuilder = $this->getEventParticipantsQueryBuilder($opts, $limit, $offset);

        return EventParticipant::filterEventParticipants(
            new ArrayCollection($queryBuilder->getQuery()->getResult()),
            $includeNotActivated
        );
    }

    public function getEventParticipantsQueryBuilder(array $opts = [], ?int $limit = null, ?int $offset = null): QueryBuilder
    {
        $queryBuilder = $this->createQueryBuilder('ep');
        $this->addSuperEventQuery($queryBuilder, $opts);
        $this->addIdQuery($queryBuilder, $opts);
        $this->addParticipantTypeQuery($queryBuilder, $opts);
        $this->addParticipantTypeOfTypeQuery($queryBuilder, $opts);
        $this->addIncludeDeletedQuery($queryBuilder, $opts);
        $this->addContactQuery($queryBuilder, $opts);
        $this->addAppUserQuery($queryBuilder, $opts);
        $this->addLimit($queryBuilder, $limit, $offset);
        $this->addOrderBy($queryBuilder, true, true);

        return $queryBuilder;
    }

    private function addSuperEventQuery(QueryBuilder $queryBuilder, array $opts = []): void
    {
        if (!empty($opts[self::CRITERIA_EVENT]) && $opts[self::CRITERIA_EVENT] instanceof Event) {
            $eventQuery = ' ep.event = :event_id ';
            $queryBuilder->leftJoin('ep.event', 'e0');
            $recursiveDepth = !empty($opts[self::CRITERIA_EVENT_RECURSIVE_DEPTH]) ? $opts[self::CRITERIA_EVENT_RECURSIVE_DEPTH] : 0;
            for ($i = 0; $i < $recursiveDepth; $i++) {
                $j = $i + 1;
                $queryBuilder->leftJoin("e$i.superEvent", "e$j");
                $eventQuery .= " OR e$j = :event_id ";
            }
            $queryBuilder->andWhere($eventQuery)->setParameter('event_id', $opts[self::CRITERIA_EVENT]->getId());
        }
    }

    private function addIdQuery(QueryBuilder $queryBuilder, array $opts = []): void
    {
        if (!empty($opts[self::CRITERIA_ID])) {
            $queryBuilder->andWhere(' ep.id = :id ')->setParameter('id', $opts[self::CRITERIA_ID]);
        }
    }

    private function addParticipantTypeQuery(QueryBuilder $queryBuilder, array $opts = []): void
    {
        if (!empty($opts[self::CRITERIA_PARTICIPANT_TYPE]) && $opts[self::CRITERIA_PARTICIPANT_TYPE] instanceof EventParticipantType) {
            $queryBuilder->andWhere('ep.eventParticipantType = :type_id');
            $queryBuilder->setParameter('type_id', $opts[self::CRITERIA_PARTICIPANT_TYPE]->getId());
        }
    }

    private function addParticipantTypeOfTypeQuery(QueryBuilder $queryBuilder, array $opts = []): void
    {
        if (!empty($opts[self::CRITERIA_PARTICIPANT_TYPE_OF_TYPE]) && is_string($opts[self::CRITERIA_PARTICIPANT_TYPE_OF_TYPE])) {
            $queryBuilder->leftJoin('ep.eventParticipantType', 'type');
            $queryBuilder->andWhere('type.type = :type_type');
            $queryBuilder->setParameter('type_type', $opts[self::CRITERIA_PARTICIPANT_TYPE_OF_TYPE]);
        }
    }

    private function addIncludeDeletedQuery(QueryBuilder $queryBuilder, array $opts = []): void
    {
        if (empty($opts[self::CRITERIA_INCLUDE_DELETED])) {
            $queryBuilder->andWhere('ep.deleted IS NULL');
        }
    }

    private function addContactQuery(QueryBuilder $queryBuilder, array $opts = []): void
    {
        if (!empty($opts[self::CRITERIA_CONTACT]) && $opts[self::CRITERIA_CONTACT] instanceof AbstractContact) {
            $queryBuilder->andWhere('ep.contact = :contact_id')->setParameter('contact_id', $opts[self::CRITERIA_CONTACT]->getId());
        }
    }

    private function addAppUserQuery(QueryBuilder $queryBuilder, array $opts = []): void
    {
        if (!empty($opts[self::CRITERIA_APP_USER]) && $opts[self::CRITERIA_APP_USER] instanceof AppUser) {
            $queryBuilder->leftJoin('ep.contact', 'contact');
            $queryBuilder->andWhere('contact.appUser = :app_user_id');
            $queryBuilder->setParameter('app_user_id', $opts[self::CRITERIA_APP_USER]->getId());
        }
    }

    private function addLimit(QueryBuilder $queryBuilder, ?int $limit = null, ?int $offset = null): void
    {
        if (null !== $limit) {
            $queryBuilder->setMaxResults($limit);
        }
        if (null !== $offset) {
            $queryBuilder->setFirstResult($offset);
        }
    }

    private function addOrderBy(QueryBuilder $queryBuilder, bool $priority = true, bool $name = true): void
    {
        if ($priority) {
            $queryBuilder->addOrderBy('ep.priority', 'DESC');
        }
        if ($name) {
            $queryBuilder->leftJoin('ep.contact', 'contact_0');
            $queryBuilder->addOrderBy('contact_0.sortableName', 'ASC');
        }
        $queryBuilder->addOrderBy('ep.id', 'ASC');
    }

    public function getEventParticipant(?array $opts = []): ?EventParticipant
    {
        try {
            $eventParticipant = $this->getEventParticipantsQueryBuilder($opts)->getQuery()->getOneOrNullResult();
        } catch (Exception $e) {
            return null;
        }

        return $eventParticipant instanceof EventParticipant ? $eventParticipant : null;
    }
}



