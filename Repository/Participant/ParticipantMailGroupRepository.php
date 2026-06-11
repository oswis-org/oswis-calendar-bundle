<?php

namespace OswisOrg\OswisCalendarBundle\Repository\Participant;

use DateTime;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\AbstractQuery;
use Doctrine\Persistence\ManagerRegistry;
use Exception;
use OswisOrg\OswisCalendarBundle\Entity\Event\Event;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Entity\ParticipantMail\ParticipantMailGroup;
use OswisOrg\OswisCoreBundle\Interfaces\Mail\MailCategoryInterface;

class ParticipantMailGroupRepository extends ServiceEntityRepository
{
    /**
     * @param ManagerRegistry $registry
     */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ParticipantMailGroup::class);
    }

    final public function findByUser(Participant $participant, MailCategoryInterface $category): ?ParticipantMailGroup
    {
        $queryBuilder = $this->createQueryBuilder('mail_group');
        $queryBuilder->setParameter("category_id", $category->getId())->setParameter("now", new DateTime());
        $queryBuilder->where("mail_group.category = :category_id");
        $queryBuilder->andWhere("mail_group.startDateTime IS NULL OR mail_group.startDateTime < :now");
        $queryBuilder->andWhere("mail_group.endDateTime IS NULL OR mail_group.endDateTime > :now");
        $queryBuilder->orderBy("mail_group.priority", "DESC");
        try {
            /** @var ParticipantMailGroup[] $appUserEMailGroups */
            $appUserEMailGroups = $queryBuilder->getQuery()->getResult();
            foreach ($appUserEMailGroups as $appUserMailGroup) {
                if ($appUserMailGroup->isApplicable($participant)) {
                    return $appUserMailGroup;
                }
            }

            return null;
        } catch (Exception $e) {
            return null;
        }
    }

    final public function findAutoMailGroups(?Event $event = null, ?string $type = null): Collection
    {
        $queryBuilder = $this->createQueryBuilder('mg')->setParameter('now', new DateTime());
        $queryBuilder->where("mg.automaticMailing = 1");
        // Active-now window: start <= now <= end (inclusive), null = open-ended. The previous
        // expression had inverted operators (:now < start, :now > end) AND broken precedence
        // (A OR B AND C OR D), making it a near-no-op; grouped + corrected here. The entity-level
        // isApplicableByDate re-check stays as defense-in-depth.
        $queryBuilder->andWhere(
            "((mg.startDateTime IS NULL) OR (mg.startDateTime <= :now)) AND ((mg.endDateTime IS NULL) OR (mg.endDateTime >= :now))"
        );
        $queryBuilder->addOrderBy('mg.priority', 'DESC');
        if (null !== $event) {
            $queryBuilder->andWhere("mg.event = :event_id")->setParameter('event_id', $event->getId());
        }
        if (!empty($type)) {
            $queryBuilder->leftJoin('mg.category', 'mc');
            $queryBuilder->andWhere("mc.type = :type")->setParameter('type', $type);
        }
        $result = $queryBuilder->getQuery()->getResult(AbstractQuery::HYDRATE_OBJECT);

        return new ArrayCollection(is_array($result) ? $result : []);
    }

    final public function findOneBy(array $criteria, ?array $orderBy = null): ?ParticipantMailGroup
    {
        $result = parent::findOneBy($criteria, $orderBy);

        return $result instanceof ParticipantMailGroup ? $result : null;
    }
}
