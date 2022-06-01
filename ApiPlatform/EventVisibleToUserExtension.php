<?php

namespace OswisOrg\OswisCalendarBundle\ApiPlatform;

use ApiPlatform\Core\Bridge\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Core\Bridge\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Core\Bridge\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use Doctrine\ORM\QueryBuilder;
use OswisOrg\OswisCalendarBundle\Entity\Event\Event;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Repository\Participant\ParticipantRepository;
use OswisOrg\OswisCalendarBundle\Service\Participant\ParticipantService;
use Symfony\Component\Security\Core\Security;

class EventVisibleToUserExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly ParticipantService $participantService,
    ) {
    }

    final public function applyToCollection(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        string $operationName = null
    ): void {
        $this->addWhere($queryBuilder, $resourceClass);
    }

    private function addWhere(QueryBuilder $queryBuilder, string $resourceClass): void
    {
        if ($resourceClass !== Event::class || $this->security->isGranted('ROLE_ADMIN')) {
            return;
        }
        /** @var \OswisOrg\OswisCoreBundle\Entity\AppUser\AppUser $user */
        $user         = $this->security->getUser();
        $participants = $this->participantService->getParticipants([ParticipantRepository::CRITERIA_APP_USER => $user]);
        $events       = array_map(static fn(mixed $p) => $p instanceof Participant ? $p->getEvent() : null, $participants->toArray());
        $rootAlias    = $queryBuilder->getRootAliases()[0];
        $queryBuilder->leftJoin("$rootAlias.superEvent", 'superEvent');
        $queryBuilder->andWhere(" $rootAlias.id IN (:ids) OR $rootAlias.superEvent IN (:ids) ");
        $queryBuilder->setParameter('ids', array_map(static fn(?Event $event) => $event?->getId(), $events));
    }

    final public function applyToItem(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        array $identifiers,
        string $operationName = null,
        array $context = []
    ): void {
        $this->addWhere($queryBuilder, $resourceClass);
    }
}
