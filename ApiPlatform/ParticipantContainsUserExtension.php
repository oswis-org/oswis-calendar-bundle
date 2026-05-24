<?php

namespace OswisOrg\OswisCalendarBundle\ApiPlatform;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use Doctrine\ORM\QueryBuilder;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCoreBundle\Entity\AppUser\AppUser;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;

class ParticipantContainsUserExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly RequestStack $requestStack,
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
        if ($resourceClass !== Participant::class) {
            return;
        }
        $user = $this->security->getUser();
        if (!$user instanceof AppUser) {
            $queryBuilder->andWhere('1 = 0');

            return;
        }
        // Portal frontends pass `?onlyMine=1` to force per-user scoping even
        // for ROLE_MANAGER / ROLE_ADMIN sessions (data-leak prevention when
        // admin opens /portal/participants). Without it, legacy behaviour
        // applies: managers see all, non-managers scope to own participants.
        $request = $this->requestStack->getCurrentRequest();
        $onlyMine = $request !== null && '' !== (string) $request->query->get('onlyMine', '');

        if (!$onlyMine
            && ($this->security->isGranted('ROLE_MANAGER')
                || $this->security->isGranted('ROLE_ADMIN'))) {
            return;
        }
        $rootAlias = $queryBuilder->getRootAliases()[0];
        $queryBuilder->leftJoin("$rootAlias.contact", 'contact');
        $queryBuilder->leftJoin("contact.appUser", 'appUser');
        $queryBuilder->andWhere('appUser = :appUserId')->setParameter('appUserId', $user->getId());
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
