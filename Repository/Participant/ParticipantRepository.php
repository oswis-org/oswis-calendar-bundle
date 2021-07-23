<?php
/**
 * @noinspection PhpSameParameterValueInspection
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Repository\Participant;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Excparticipanttion;
use LogicException;
use OswisOrg\OswisAddressBookBundle\Entity\AbstractClass\AbstractContact;
use OswisOrg\OswisCalendarBundle\Entity\Event\Event;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantCategory;
use OswisOrg\OswisCalendarBundle\Entity\Registration\RegistrationOffer;
use OswisOrg\OswisCoreBundle\Entity\AppUser\AppUser;

class ParticipantRepository extends ServiceEntityRepository
{
    public const CRITERIA_ID = 'id';
    public const CRITERIA_EVENT = 'event';
    public const CRITERIA_EVENT_RECURSIVE_DEPTH = 'eventRecursiveDepth';
    public const CRITERIA_PARTICIPANT_TYPE = 'participantType';
    public const CRITERIA_PARTICIPANT_CATEGORY = 'participantCategory';
    public const CRITERIA_OFFER = 'offer';
    public const CRITERIA_INCLUDE_DELETED = 'includeDeleted';
    public const CRITERIA_CONTACT = 'contact';
    public const CRITERIA_APP_USER = 'appUser';
    public const CRITERIA_VARIABLE_SYMBOL = 'variableSymbol';

    /**
     * @param  ManagerRegistry  $registry
     *
     * @throws LogicException
     */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Participant::class);
    }

    final public function findOneBy(array $criteria, array $orderBy = null): ?Participant
    {
        $result = parent::findOneBy($criteria, $orderBy);

        return $result instanceof Participant ? $result : null;
    }

    public function countParticipants(array $opts = []): ?int
    {
        $queryBuilder = $this->getParticipantsQueryBuilder($opts)->select(' COUNT(participant.id) ');
        try {
            return $queryBuilder->getQuery()->getSingleScalarResult();
        } catch (NoResultException | NonUniqueResultException) {
            return null;
        }
    }

    public function getParticipantsQueryBuilder(array $opts = [], ?int $limit = null, ?int $offset = null): QueryBuilder
    {
        $queryBuilder = $this->createQueryBuilder('participant');
        $this->setSuperEventQuery($queryBuilder, $opts);
        $this->setIdQuery($queryBuilder, $opts);
        $this->setRangeQuery($queryBuilder, $opts);
        $this->setParticipantCategoryQuery($queryBuilder, $opts);
        $this->setParticipantTypeQuery($queryBuilder, $opts);
        $this->setIncludeDeletedQuery($queryBuilder, $opts);
        $this->setContactQuery($queryBuilder, $opts);
        $this->setAppUserQuery($queryBuilder, $opts);
        $this->setVSQuery($queryBuilder, $opts);
        $this->setLimit($queryBuilder, $limit, $offset);
        $this->setOrderBy($queryBuilder);

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
        if (!empty($opts[self::CRITERIA_OFFER]) && $opts[self::CRITERIA_OFFER] instanceof RegistrationOffer) {
            $queryBuilder->andWhere('participant.offer = :offer_id');
            $queryBuilder->setParameter('offer_id', $opts[self::CRITERIA_OFFER]->getId());
        }
    }

    private function setParticipantCategoryQuery(QueryBuilder $queryBuilder, array $opts = []): void
    {
        if (!empty($opts[self::CRITERIA_PARTICIPANT_CATEGORY]) && $opts[self::CRITERIA_PARTICIPANT_CATEGORY] instanceof ParticipantCategory) {
            $queryBuilder->andWhere('participant.participantCategory = :type_id');
            $queryBuilder->setParameter('type_id', $opts[self::CRITERIA_PARTICIPANT_CATEGORY]->getId());
        }
    }

    private function setParticipantTypeQuery(QueryBuilder $queryBuilder, array $opts = []): void
    {
        if (!empty($opts[self::CRITERIA_PARTICIPANT_TYPE]) && is_string($opts[self::CRITERIA_PARTICIPANT_TYPE])) {
            $queryBuilder->leftJoin('participant.participantCategory', 'participant_category_for_string');
            $queryBuilder->andWhere('participant_category_for_string.type = :type_string');
            $queryBuilder->setParameter('type_string', $opts[self::CRITERIA_PARTICIPANT_TYPE]);
        }
    }

    private function setIncludeDeletedQuery(QueryBuilder $queryBuilder, array $opts = []): void
    {
        if (empty($opts[self::CRITERIA_INCLUDE_DELETED]) || !$opts[self::CRITERIA_INCLUDE_DELETED]) {
            $queryBuilder->andWhere('participant.deletedAt IS NULL');
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

    private function setVSQuery(QueryBuilder $queryBuilder, array $opts = []): void
    {
        if (!empty($opts[self::CRITERIA_VARIABLE_SYMBOL])) {
            $queryBuilder->andWhere(' participant.variableSymbol = :variableSymbol ');
            $queryBuilder->setParameter('variableSymbol', $opts[self::CRITERIA_VARIABLE_SYMBOL]);
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

    public function getParticipants(array $opts = [], ?bool $includeNotActivated = true, ?int $limit = null, ?int $offset = null): Collection
    {
        $queryBuilder = $this->getParticipantsQueryBuilder($opts, $limit, $offset);

        return Participant::filterCollection(new ArrayCollection($queryBuilder->getQuery()->getResult()), $includeNotActivated);
    }

    public function getParticipant(?array $opts = [], ?bool $includeNotActivated = true): ?Participant
    {
        try {
            $participant = $this->getParticipantsQueryBuilder($opts ?? [], 1, 0)->getQuery()->getOneOrNullResult();
        } catch (NonUniqueResultException) {
            return null;
        }
        if (!($participant instanceof Participant) || (!$includeNotActivated && !$participant->hasActivatedContactUser())) {
            return null;
        }

        return $participant;
    }
}

