<?php

namespace OswisOrg\OswisCalendarBundle\Repository\Participant;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Entity\ParticipantMail\ParticipantMail;
use OswisOrg\OswisCoreBundle\Entity\AppUser\AppUser;

class ParticipantMailRepository extends ServiceEntityRepository
{
    /**
     * @param  ManagerRegistry  $registry
     *
     * @throws LogicException
     */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ParticipantMail::class);
    }

    final public function findByAppUser(AppUser $appUser): Collection
    {
        $queryBuilder = $this->createQueryBuilder('mail');
        $queryBuilder->andWhere("mail.appUser = :app_user_id")->setParameter('app_user_id', $appUser->getId());
        $queryBuilder->addOrderBy('mail.id', 'DESC');

        return new ArrayCollection($queryBuilder->getQuery()->getResult(AbstractQuery::HYDRATE_OBJECT));
    }

    final public function findSent(Participant $participant, string $type): Collection
    {
        $queryBuilder = $this->createQueryBuilder('mail');
        $queryBuilder->andWhere("mail.participant = :participant_id")->setParameter('participant_id', $participant->getId());
        $queryBuilder->andWhere("mail.type = :type")->setParameter('type', $type);
        $queryBuilder->andWhere("mail.sent IS NOT NULL");
        $queryBuilder->addOrderBy('mail.id', 'DESC');

        return new ArrayCollection($queryBuilder->getQuery()->getResult(AbstractQuery::HYDRATE_OBJECT));
    }

    final public function countSent(Participant $participant, string $type): ?int
    {
        $queryBuilder = $this->createQueryBuilder('mail');
        $queryBuilder->select(' COUNT(mail.id) ');
        $queryBuilder->andWhere("mail.participant = :participant_id")->setParameter('participant_id', $participant->getId());
        $queryBuilder->andWhere("mail.type = :type")->setParameter('type', $type)->andWhere("mail.sent IS NOT NULL");
        $queryBuilder->addOrderBy('mail.id', 'DESC');
        try {
            return (int)$queryBuilder->getQuery()->getSingleScalarResult();
        } catch (NoResultException | NonUniqueResultException) {
            return null;
        }
    }

    final public function findOneBy(array $criteria, array $orderBy = null): ?ParticipantMail
    {
        $result = parent::findOneBy($criteria, $orderBy);

        return $result instanceof ParticipantMail ? $result : null;
    }
}
