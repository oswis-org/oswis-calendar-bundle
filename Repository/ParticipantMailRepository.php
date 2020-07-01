<?php
/**
 * @noinspection PhpUnused
 */

namespace OswisOrg\OswisCalendarBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\AbstractQuery;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use OswisOrg\OswisCalendarBundle\Entity\ParticipantMail\ParticipantMail;
use OswisOrg\OswisCoreBundle\Entity\AppUser\AppUser;

class ParticipantMailRepository extends ServiceEntityRepository
{
    /**
     * @param ManagerRegistry $registry
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
        $queryBuilder->where("mail.appUser = :app_user_id")->setParameter('app_user_id', $appUser->getId());
        $queryBuilder->addOrderBy('mail.id', 'DESC');

        return new ArrayCollection($queryBuilder->getQuery()->getResult(AbstractQuery::HYDRATE_OBJECT));
    }

    final public function findOneBy(array $criteria, array $orderBy = null): ?ParticipantMail
    {
        $result = parent::findOneBy($criteria, $orderBy);

        return $result instanceof ParticipantMail ? $result : null;
    }
}
