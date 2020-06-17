<?php
/**
 * @noinspection PhpUnused
 */

namespace OswisOrg\OswisCalendarBundle\Repository;

use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Query;
use Exception;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantToken;

class ParticipantTokenRepository extends EntityRepository
{
    final public function findByToken(string $token, int $participantId): ?ParticipantToken
    {
        $queryBuilder = $this->createQueryBuilder('token');
        $queryBuilder->where('token.token = :token')->setParameter('token', $token);
        $queryBuilder->andWhere('token.participant = :participant_id')->setParameter('participant_id', $participantId);
        $query = $queryBuilder->getQuery();
        try {
            return $query->getOneOrNullResult(Query::HYDRATE_OBJECT);
        } catch (Exception $e) {
            return null;
        }
    }

    final public function findOneBy(array $criteria, array $orderBy = null): ?ParticipantToken
    {
        $result = parent::findOneBy($criteria, $orderBy);

        return $result instanceof ParticipantToken ? $result : null;
    }
}
