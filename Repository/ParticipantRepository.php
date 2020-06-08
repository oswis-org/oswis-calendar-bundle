<?php
/**
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Repository;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\QueryBuilder;
use Excparticipanttion;
use OswisOrg\OswisAddressBookBundle\Entity\AbstractClass\AbstractContact;
use OswisOrg\OswisCalendarBundle\Entity\Event\Event;
use OswisOrg\OswisCalendarBundle\Entity\Event\RegistrationRange;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantCategory;
use OswisOrg\OswisCoreBundle\Entity\AppUser\AppUser;

class ParticipantRepository extends EntityRepository
{
    public const CRITERIA_ID = 'id';
    public const CRITERIA_EVENT = 'event';
    public const CRITERIA_EVENT_RECURSIVE_DEPTH = 'eventRecursiveDepth';
    public const CRITERIA_PARTICIPANT_TYPE_STRING = 'participantTypeString';
    public const CRITERIA_PARTICIPANT_TYPE = 'participantType';
    public const CRITERIA_RANGE = 'range';
    public const CRITERIA_INCLUDE_DELETED = 'includeDeleted';
    public const CRITERIA_CONTACT = 'contact';
    public const CRITERIA_APP_USER = 'appUser';

    final public function findOneBy(array $criteria, array $orderBy = null): ?Participant
    {
        $result = parent::findOneBy($criteria, $orderBy);

        return $result instanceof Participant ? $result : null;
    }

    /**
     * @param array     $opts
     * @param bool|null $includeNotActivated
     * @param int|null  $limit
     * @param int|null  $offset
     *
     * @return Collection
     * @noinspection PhpUnused
     */
    public function getParticipants(array $opts = [], ?bool $includeNotActivated = true, ?int $limit = null, ?int $offset = null): Collection
    {
        $queryBuilder = $this->getParticipantsQueryBuilder($opts, $limit, $offset);

        return Participant::filterCollection(new ArrayCollection($queryBuilder->getQuery()->getResult()), $includeNotActivated);
    }

    public function getParticipantsQueryBuilder(array $opts = [], ?int $limit = null, ?int $offset = null): QueryBuilder
    {
        $queryBuilder = $this->createQueryBuilder('participant');
        $this->setSuperEventQuery($queryBuilder, $opts);
        $this->setIdQuery($queryBuilder, $opts);
        $this->setRangeQuery($queryBuilder, $opts);
        $this->setParticipantTypeQuery($queryBuilder, $opts);
        $this->setParticipantTypeStringQuery($queryBuilder, $opts);
        $this->setIncludeDeletedQuery($queryBuilder, $opts);
        $this->setContactQuery($queryBuilder, $opts);
        $this->setAppUserQuery($queryBuilder, $opts);
        $this->setLimit($queryBuilder, $limit, $offset);
        $this->setOrderBy($queryBuilder, true, true);

        return $queryBuilder;
    }

    private function setSuperEventQuery(QueryBuilder $queryBuilder, array $opts = []): void
    {
        if (!empty($opts[self::CRITERIA_EVENT]) && $opts[self::CRITERIA_EVENT] instanceof Event) {
            $eventQuery = ' participant.event = :event_id ';
            $queryBuilder->leftJoin('participant.event', 'e0');
            $recursiveDepth = !empty($opts[self::CRITERIA_EVENT_RECURSIVE_DEPTH]) ? $opts[self::CRITERIA_EVENT_RECURSIVE_DEPTH] : 0;
            for ($i = 0; $i < $recursiveDepth; $i++) {
                $j = $i + 1;
                $queryBuilder->leftJoin("e$i.superEvent", "e$j");
                $eventQuery .= " OR e$j = :event_id ";
            }
            $queryBuilder->andWhere($eventQuery)->setParameter('event_id', $opts[self::CRITERIA_EVENT]->getId());
        }
    }

    private function setIdQuery(QueryBuilder $queryBuilder, array $opts = []): void
    {
        if (!empty($opts[self::CRITERIA_ID])) {
            $queryBuilder->andWhere(' participant.id = :id ')->setParameter('id', $opts[self::CRITERIA_ID]);
        }
    }

    private function setRangeQuery(QueryBuilder $queryBuilder, array $opts = []): void
    {
        if (!empty($opts[self::CRITERIA_RANGE]) && $opts[self::CRITERIA_RANGE] instanceof RegistrationRange) {
            $queryBuilder->andWhere('participant.range = :range_id');
            $queryBuilder->setParameter('range_id', $opts[self::CRITERIA_RANGE]->getId());
        }
    }

    private function setParticipantTypeQuery(QueryBuilder $queryBuilder, array $opts = []): void
    {
        if (!empty($opts[self::CRITERIA_PARTICIPANT_TYPE]) && $opts[self::CRITERIA_PARTICIPANT_TYPE] instanceof ParticipantCategory) {
            $queryBuilder->andWhere('participant.participantType = :type_id');
            $queryBuilder->setParameter('type_id', $opts[self::CRITERIA_PARTICIPANT_TYPE]->getId());
        }
    }

    private function setParticipantTypeStringQuery(QueryBuilder $queryBuilder, array $opts = []): void
    {
        if (!empty($opts[self::CRITERIA_PARTICIPANT_TYPE_STRING]) && is_string($opts[self::CRITERIA_PARTICIPANT_TYPE_STRING])) {
            $queryBuilder->leftJoin('participant.participantType', 'participant_category_for_string');
            $queryBuilder->andWhere('participant_category_for_string.type = :type_string');
            $queryBuilder->setParameter('type_string', $opts[self::CRITERIA_PARTICIPANT_TYPE_STRING]);
        }
    }

    private function setIncludeDeletedQuery(QueryBuilder $queryBuilder, array $opts = []): void
    {
        if (empty($opts[self::CRITERIA_INCLUDE_DELETED])) {
            $queryBuilder->andWhere('participant.range IS NOT NULL');
        }
    }

    private function setContactQuery(QueryBuilder $queryBuilder, array $opts = []): void
    {
        if (!empty($opts[self::CRITERIA_CONTACT]) && $opts[self::CRITERIA_CONTACT] instanceof AbstractContact) {
            $queryBuilder->andWhere('participant.contact = :contact_id')->setParameter('contact_id', $opts[self::CRITERIA_CONTACT]->getId());
        }
    }

    private function setAppUserQuery(QueryBuilder $queryBuilder, array $opts = []): void
    {
        if (!empty($opts[self::CRITERIA_APP_USER]) && $opts[self::CRITERIA_APP_USER] instanceof AppUser) {
            $queryBuilder->leftJoin('participant.contact', 'contact');
            $queryBuilder->andWhere('contact.appUser = :app_user_id');
            $queryBuilder->setParameter('app_user_id', $opts[self::CRITERIA_APP_USER]->getId());
        }
    }

    private function setLimit(QueryBuilder $queryBuilder, ?int $limit = null, ?int $offset = null): void
    {
        if (null !== $limit) {
            $queryBuilder->setMaxResults($limit);
        }
        if (null !== $offset) {
            $queryBuilder->setFirstResult($offset);
        }
    }

    private function setOrderBy(QueryBuilder $queryBuilder, bool $priority = true, bool $name = true): void
    {
        if ($priority) {
            $queryBuilder->addOrderBy('participant.priority', 'DESC');
        }
        if ($name) {
            $queryBuilder->leftJoin('participant.contact', 'contact_0');
            $queryBuilder->addOrderBy('contact_0.sortableName', 'ASC');
        }
        $queryBuilder->addOrderBy('participant.id', 'ASC');
    }

    /**
     * @param array|null $opts
     *
     * @return Participant|null
     * @noinspection PhpUnused
     */
    public function getParticipant(?array $opts = []): ?Participant
    {
        try {
            $participant = $this->getParticipantsQueryBuilder($opts)->getQuery()->getOneOrNullResult();
        } catch (NonUniqueResultException $e) {
            return null;
        }

        return $participant instanceof Participant ? $participant : null;
    }
}

