<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\ApiPlatform;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use Doctrine\ORM\QueryBuilder;
use OswisOrg\OswisCalendarBundle\Entity\Event\Event;
use OswisOrg\OswisCalendarBundle\Repository\Participant\ParticipantRepository;
use OswisOrg\OswisCalendarBundle\Service\Participant\ParticipantService;
use OswisOrg\OswisCoreBundle\Entity\AppUser\AppUser;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * When `?onlyMine=true` is set, restrict Event collection to events the current user attends
 * directly (Participant.event) or through SubEventAttendance.event.
 *
 * Spec: docs/superpowers/specs/2026-05-22-S2-S3-S4-calendar-ux-2.0-design.md S2 step 4.1.4
 */
final class OnlyMineEventsExtension implements QueryCollectionExtensionInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly ParticipantService $participantService,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function applyToCollection(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        ?Operation $operation = null,
        ?array $context = [],
    ): void {
        if ($resourceClass !== Event::class) {
            return;
        }
        $req = $this->requestStack->getCurrentRequest();
        if (null === $req || $req->query->get('onlyMine') !== 'true') {
            return;
        }
        $user = $this->security->getUser();
        if (!$user instanceof AppUser) {
            $queryBuilder->andWhere('1 = 0');

            return;
        }
        $participants = $this->participantService->getParticipants(
            [ParticipantRepository::CRITERIA_APP_USER => $user]
        );
        $eventIds = [];
        foreach ($participants as $p) {
            $event = $p->getEvent();
            if (null !== $event) {
                $eventIds[] = $event->getId();
            }
            foreach ($p->getSubEventAttendances() as $att) {
                if ($att->isActive()) {
                    $eventIds[] = $att->getEvent()->getId();
                }
            }
        }
        $eventIds = array_values(array_unique(array_filter(
            $eventIds,
            static fn (mixed $v): bool => is_int($v),
        )));
        if ([] === $eventIds) {
            $queryBuilder->andWhere('1 = 0');

            return;
        }
        $rootAlias = $queryBuilder->getRootAliases()[0];
        $queryBuilder->andWhere("$rootAlias.id IN (:onlyMineIds)")
                     ->setParameter('onlyMineIds', $eventIds);
    }
}
