<?php
/**
 * @noinspection PhpUnused
 */

namespace OswisOrg\OswisCalendarBundle\Repository;

use DateTime;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Exception;
use LogicException;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Entity\ParticipantEMail\ParticipantEMailGroup;
use OswisOrg\OswisCoreBundle\Interfaces\EMail\EMailCategoryInterface;

class ParticipantEMailGroupRepository extends ServiceEntityRepository
{
    /**
     * @param ManagerRegistry $registry
     *
     * @throws LogicException
     */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ParticipantEMailGroup::class);
    }

    final public function findByUser(Participant $participant, EMailCategoryInterface $category): ?ParticipantEMailGroup
    {
        $queryBuilder = $this->createQueryBuilder('group');
        $queryBuilder->setParameter("category_id", $category->getId())->setParameter("now", new DateTime());
        $queryBuilder->where("group.category = :category_id");
        $queryBuilder->andWhere("group.startDateTime IS NULL OR group.startDateTime < :now");
        $queryBuilder->andWhere("group.endDateTime IS NULL OR group.endDateTime > :now");
        $queryBuilder->orderBy("group.priority", "DESC");
        try {
            $appUserEMailGroups = $queryBuilder->getQuery()->getResult();
            foreach ($appUserEMailGroups as $appUserEMailGroup) {
                if ($appUserEMailGroup instanceof ParticipantEMailGroup && $appUserEMailGroup->isApplicable($participant)) {
                    return $appUserEMailGroup;
                }
            }

            return null;
        } catch (Exception $e) {
            return null;
        }
    }

    final public function findOneBy(array $criteria, array $orderBy = null): ?ParticipantEMailGroup
    {
        $result = parent::findOneBy($criteria, $orderBy);

        return $result instanceof ParticipantEMailGroup ? $result : null;
    }
}
