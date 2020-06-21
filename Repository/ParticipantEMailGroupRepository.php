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
use OswisOrg\OswisCalendarBundle\Entity\ParticipantMail\ParticipantMailGroup;
use OswisOrg\OswisCoreBundle\Interfaces\Mail\MailCategoryInterface;

class ParticipantEMailGroupRepository extends ServiceEntityRepository
{
    /**
     * @param ManagerRegistry $registry
     *
     * @throws LogicException
     */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ParticipantMailGroup::class);
    }

    final public function findByUser(Participant $participant, MailCategoryInterface $category): ?ParticipantMailGroup
    {
        $queryBuilder = $this->createQueryBuilder('group');
        $queryBuilder->setParameter("category_id", $category->getId())->setParameter("now", new DateTime());
        $queryBuilder->where("group.category = :category_id");
        $queryBuilder->andWhere("group.startDateTime IS NULL OR group.startDateTime < :now");
        $queryBuilder->andWhere("group.endDateTime IS NULL OR group.endDateTime > :now");
        $queryBuilder->orderBy("group.priority", "DESC");
        try {
            $appUserEMailGroups = $queryBuilder->getQuery()->getResult();
            foreach ($appUserEMailGroups as $appUserMailGroup) {
                if ($appUserMailGroup instanceof ParticipantMailGroup && $appUserMailGroup->isApplicable($participant)) {
                    return $appUserMailGroup;
                }
            }

            return null;
        } catch (Exception $e) {
            return null;
        }
    }

    final public function findOneBy(array $criteria, array $orderBy = null): ?ParticipantMailGroup
    {
        $result = parent::findOneBy($criteria, $orderBy);

        return $result instanceof ParticipantMailGroup ? $result : null;
    }
}
