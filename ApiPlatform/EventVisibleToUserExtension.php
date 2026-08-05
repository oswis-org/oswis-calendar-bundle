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
use Symfony\Component\HttpFoundation\RequestStack;

class EventVisibleToUserExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly ParticipantService $participantService,
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
        if ($resourceClass !== Event::class) {
            return;
        }
        // ⚠️ Smazané akce ven PŘED výjimkou pro tým — smazaná aktivita nemá co dělat ani
        // v editoru programu. Platí pro všechny role, únik ven je `?includeDeleted=1`.
        $this->skryjSmazane($queryBuilder);
        // ROLE_MANAGER+ (staff/editor) sees every event — incl. internal services — needed for the
        // program editor. ROLE_ADMIN/ROOT already implied by the role hierarchy, kept explicit.
        if ($this->security->isGranted('ROLE_MANAGER')
            || $this->security->isGranted('ROLE_ADMIN')
            || $this->security->isGranted('ROLE_ROOT')) {
            return;
        }
        $user = $this->security->getUser();
        if (!$user instanceof AppUser) {
            $queryBuilder->andWhere('1 = 0');

            return;
        }
        $participants = $this->participantService->getParticipants([ParticipantRepository::CRITERIA_APP_USER => $user]);
        $events = array_map(static fn (Participant $p) => $p->getEvent(), $participants->toArray());
        // Program-release gate (žádost usera): účastník uvidí PROGRAMOVÝ podstrom turnusu jen když je
        // program turnusu ZVEŘEJNĚN (Event::isProgramReleased — programReleasedAt nastaveno a už nastalo).
        // Svůj turnus (a veřejné ročník/turnus) vidí vždy; program se staví skrytě a tým ho zveřejní až
        // hotový a zkontrolovaný. Prázdné releasedIds → IN () nic nematchne → program skrytý.
        $now = new \DateTime();
        $releasedIds = array_values(array_filter(array_map(
            static fn (?Event $e) => (null !== $e && $e->isProgramReleased($now)) ? $e->getId() : null,
            $events,
        )));
        $rootAlias = $queryBuilder->getRootAliases()[0];
        $queryBuilder
            ->leftJoin("$rootAlias.superEvent", 'superEvent')
            ->leftJoin("$rootAlias.category", 'visibilityCategory');
        // A participant sees the whole PUBLIC program subtree of their turnus (blok → podakce → …),
        // not just direct children — so the app can render the full programme with foreign groups
        // dimmed. Internal services (publicInApp = false) stay hidden. Strictly ADDITIVE: walks the
        // superEvent chain a few levels up and only WIDENS visibility to publicInApp=true events —
        // ale jen u turnusů s VYDANÝM programem (:released_event_ids).
        $ancestorConditions = ['superEvent IN (:released_event_ids)'];
        $prevAncestor = 'superEvent';
        for ($i = 2; $i <= 5; $i++) {
            $join = "ancestorEvt$i";
            $queryBuilder->leftJoin("$prevAncestor.superEvent", $join);
            $ancestorConditions[] = "$join IN (:released_event_ids)";
            $prevAncestor = $join;
        }
        $queryBuilder
            ->andWhere(sprintf(
                '(%s.id IN (:user_event_ids)'
                // ⚠️ `publicInApp = true` MUSÍ být i tady. Do 2026-08-02 tahle větev podmínku
                // neměla, takže účastník viděl KAŽDOU přímou podakci turnusu se zveřejněným
                // programem — včetně interních („Informační schůze instruktorů"). Neprojevilo se
                // to, dokud byl program prázdný.
                .' OR (%s.publicInApp = true AND %s.superEvent IN (:released_event_ids))'
                .' OR (%s.publicInApp = true AND visibilityCategory.type IN (:public_category_types))'
                .' OR (%s.publicInApp = true AND (%s)))',
                $rootAlias, $rootAlias, $rootAlias, $rootAlias, $rootAlias, implode(' OR ', $ancestorConditions),
            ))
            ->setParameter('user_event_ids', array_map(static fn (?Event $event) => $event?->getId(), $events))
            ->setParameter('released_event_ids', $releasedIds)
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

    /**
     * Smazané akce a aktivity z API pryč.
     *
     * ⚠️ Bez tohohle je API vracelo. Event **není** Gedmo SoftDeleteable a globální filtr
     * `softdeleteable` není zapnutý ([[reference_softdeleteable_filter_not_enabled]]), takže
     * `deletedAt` u akcí neřešil nikdo — přitom web-admin aktivity i akce maže právě měkce
     * (`WebAdminProgramController`, `WebAdminEventController`). Tým tedy zrušil aktivitu a
     * účastníkům zůstala v programu: dorazili by na něco, co se nekoná (nalezeno 2026-08-05,
     * ověřeno na klonu — smazaná akce se vracela v kolekci i detailem 200).
     *
     * Platí i pro tým: smazaná aktivita nemá co dělat ani v editoru programu. Kdo ji opravdu
     * potřebuje (obnova), si řekne `?includeDeleted=1` — stejná konvence jako u přihlášek.
     */
    private function skryjSmazane(QueryBuilder $queryBuilder): void
    {
        $request = $this->requestStack->getCurrentRequest();
        if (null !== $request && '' !== (string) $request->query->get('includeDeleted', '')) {
            return;
        }
        $rootAlias = $queryBuilder->getRootAliases()[0];
        $queryBuilder->andWhere("$rootAlias.deletedAt IS NULL");
    }
}
