<?php

namespace OswisOrg\OswisCalendarBundle\ApiPlatform;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use Doctrine\ORM\QueryBuilder;
use OswisOrg\OswisCalendarBundle\Entity\Event\Event;
use OswisOrg\OswisCalendarBundle\Entity\Event\EventCategory;
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
        $user = $this->security->getUser();
        if (!$user instanceof AppUser) {
            $queryBuilder->andWhere('1 = 0');

            return;
        }
        $participants = $this->participantService->getParticipants([ParticipantRepository::CRITERIA_APP_USER => $user]);
        $events = array_map(static fn (Participant $p) => $p->getEvent(), $participants->toArray());
        $rootAlias = $queryBuilder->getRootAliases()[0];
        $queryBuilder
            ->leftJoin("$rootAlias.superEvent", 'superEvent')
            ->leftJoin("$rootAlias.category", 'visibilityCategory')
            ->andWhere(sprintf(
                '(%s.id IN (:user_event_ids)'
                .' OR %s.superEvent IN (:user_event_ids)'
                .' OR (%s.publicInApp = true AND visibilityCategory.type IN (:public_category_types)))',
                $rootAlias, $rootAlias, $rootAlias,
            ))
            ->setParameter('user_event_ids', array_map(static fn (?Event $event) => $event?->getId(), $events))
            ->setParameter('public_category_types', [EventCategory::YEAR_OF_EVENT, EventCategory::BATCH_OF_EVENT]);
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
