<?php

namespace OswisOrg\OswisCalendarBundle\Repository\Participant;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\AbstractQuery;
use Doctrine\Persistence\ManagerRegistry;
use Exception;
use LogicException;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantToken;

class ParticipantTokenRepository extends ServiceEntityRepository
{
    /**
     * @param  ManagerRegistry  $registry
     *
     * @throws LogicException
     */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ParticipantToken::class);
    }

    final public function findByToken(?string $token, ?int $participantId): ?ParticipantToken
    {
        if (empty($token) || $participantId === null) {
            return null;
        }
        $queryBuilder = $this->createQueryBuilder('token');
        $queryBuilder->where('token.token = :token')->setParameter('token', $token);
        $queryBuilder->andWhere('token.participant = :participant_id')->setParameter('participant_id', $participantId);
        $query = $queryBuilder->getQuery();
        try {
            return $query->getOneOrNullResult(AbstractQuery::HYDRATE_OBJECT);
        } catch (Exception) {
            return null;
        }
    }

    final public function findOneBy(array $criteria, array $orderBy = null): ?ParticipantToken
    {
        $result = parent::findOneBy($criteria, $orderBy);

        return $result instanceof ParticipantToken ? $result : null;
    }
}
