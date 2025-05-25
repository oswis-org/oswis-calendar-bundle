<?php

namespace OswisOrg\OswisCalendarBundle\ApiPlatform;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use Doctrine\ORM\QueryBuilder;
use OswisOrg\OswisCalendarBundle\Entity\Event\Event;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Repository\Participant\ParticipantRepository;
use OswisOrg\OswisCalendarBundle\Service\Participant\ParticipantService;
use OswisOrg\OswisCoreBundle\Entity\AppUser\AppUser;
use Symfony\Bundle\SecurityBundle\Security;

class EventVisibleToUserExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly ParticipantService $participantService,
    )
    {
    }

    final public function applyToCollection(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        ?Operation $operation = null,
        ?array $context = [],
    ): void {
        $this->addWhere($queryBuilder, $resourceClass);
    }

    private function addWhere(QueryBuilder $queryBuilder, string $resourceClass): void
    {
        if ($resourceClass !== Event::class || $this->security->isGranted('ROLE_ADMIN')
            || $this->security->isGranted(
                'ROLE_ROOT'
            )) {
            return;
        }
        /** @var AppUser $user */
        $user = $this->security->getUser();
        $participants = $this->participantService->getParticipants([ParticipantRepository::CRITERIA_APP_USER => $user]);
        $events = array_map(static fn (Participant $p) => $p->getEvent(), $participants->toArray());
        $rootAlias = $queryBuilder->getRootAliases()[0];
        $queryBuilder->leftJoin("$rootAlias.superEvent", 'superEvent');
        $queryBuilder->andWhere(" $rootAlias.id IN (:ids) OR $rootAlias.superEvent IN (:ids) ");
        $queryBuilder->setParameter('ids', array_map(static fn (?Event $event) => $event?->getId(), $events));
    }

    final public function applyToItem(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        array $identifiers,
        ?Operation $operation = null,
        array $context = []
    ): void {
        $this->addWhere($queryBuilder, $resourceClass);
    }
}
