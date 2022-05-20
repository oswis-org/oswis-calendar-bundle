<?php

namespace OswisOrg\OswisCalendarBundle\ApiPlatform;

use ApiPlatform\Core\Bridge\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Core\Bridge\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Core\Bridge\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use Doctrine\ORM\QueryBuilder;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use Symfony\Component\Security\Core\Security;

class ParticipantContainsUserExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
{
    public function __construct(
        private readonly Security $security,
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
        if ($resourceClass !== Participant::class || $this->security->isGranted('ROLE_ADMIN')) {
            return;
        }
        /** @var \OswisOrg\OswisCoreBundle\Entity\AppUser\AppUser $user */
        $user = $this->security->getUser();
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
        string $operationName = null,
        array $context = []
    ): void {
        $this->addWhere($queryBuilder, $resourceClass);
    }
}
