<?php
/**
 * @noinspection PhpUnused
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Controller\WebAdmin;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Entity\Registration\RegistrationFlag;
use OswisOrg\OswisCalendarBundle\Entity\Registration\RegistrationFlagCategory;
use OswisOrg\OswisCalendarBundle\Export\ParticipantExportDefinition;
use OswisOrg\OswisCalendarBundle\Repository\Event\EventRepository;
use OswisOrg\OswisCalendarBundle\Repository\Participant\ParticipantRepository;
use OswisOrg\OswisCalendarBundle\Repository\Registration\RegistrationFlagRepository;
use OswisOrg\OswisCalendarBundle\Service\Aggregations\EventAggregationsService;
use OswisOrg\OswisCalendarBundle\Service\Event\EventSeriesService;
use OswisOrg\OswisCalendarBundle\Service\Event\EventService;
use OswisOrg\OswisCalendarBundle\Service\Participant\ParticipantCategoryService;
use OswisOrg\OswisCalendarBundle\Service\Participant\ParticipantFilterEvaluator;
use OswisOrg\OswisCalendarBundle\Service\Participant\ParticipantService;
use OswisOrg\OswisCalendarBundle\Service\Registration\RegistrationOfferService;
use OswisOrg\OswisCoreBundle\Enum\ExportFormat;
use OswisOrg\OswisCoreBundle\Exceptions\NotFoundException;
use OswisOrg\OswisCoreBundle\Export\ExportManager;
use OswisOrg\OswisCoreBundle\Export\ExportRequest;
use OswisOrg\OswisCoreBundle\Export\ExportResponseFactory;
use OswisOrg\OswisCoreBundle\Utils\StringUtils;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Single, registry-driven admin participant list. One {@see list()} action replaces the
 * former five near-identical show* methods (all/unpaid/unpaid-deposit/overpaid/food).
 *
 * Two axes of control, both fully encoded in the URL (shareable/bookmarkable):
 *  - SCOPE — which participants: event (+sub-events) and/or participant category in the
 *    path; unscoped means all participants across events/years.
 *  - FILTER — which of them: a registry key (?filter=), faceted flag checkboxes (?flags[]=,
 *    OR within a category, AND across categories) and a free-form advanced boolean
 *    expression (?expr=). All three compile into one ExpressionLanguage expression
 *    evaluated per participant by {@see ParticipantFilterEvaluator}.
 *
 * Volume: scoped views load the full set (full-export need, no pagination); unscoped views
 * default to pagination and offer an explicit "show all" with a warning.
 */
class WebAdminParticipantsListController extends AbstractController
{
    public const string FILTER_ALL               = 'all';
    public const string FILTER_UNPAID            = 'unpaid';
    public const string FILTER_UNPAID_DEPOSIT    = 'unpaid-deposit';
    public const string FILTER_OVERPAID          = 'overpaid';
    public const string FILTER_FOOD              = 'food';
    public const string FILTER_WITH_REGISTRATION = 'with-registration';
    public const string FILTER_NOT_ACTIVATED     = 'not-activated';
    public const string FILTER_WITH_NOTE         = 'with-note';
    public const string FILTER_DELETED           = 'deleted';

    /** Page size for the unscoped (all-participants) paginated view. */
    private const int PER_PAGE = 100;

    public function __construct(
        public EventService $eventService,
        public ParticipantService $participantService,
        public ParticipantCategoryService $participantCategoryService,
        public RegistrationOfferService $participantRegistrationService,
        public EntityManagerInterface $em,
        public EventSeriesService $eventSeriesService,
        public EventAggregationsService $eventAggregationsService,
        public ExportManager $exportManager,
        public ExportResponseFactory $exportResponseFactory,
        public ParticipantExportDefinition $participantExportDefinition,
        public ParticipantFilterEvaluator $filterEvaluator,
        public RegistrationFlagRepository $registrationFlagRepository,
    ) {
    }

    /**
     * Unified participant list. The whole view is driven by the query string:
     * scope (?eventSlug=, ?participantCategorySlug=) + ?filter=, ?flags[]=, ?expr=, ?page=, ?all=1.
     *
     * Scope lives in the query (not the path) so any combination is expressible — notably a
     * category-only scope, which a positional `/{eventSlug?}/{categorySlug?}` path cannot
     * represent (you can't fill the 2nd optional segment while leaving the 1st empty). Legacy
     * path-form bookmarks 301-redirect here via {@see legacyScopeRedirect()}.
     */
    public function list(Request $request): Response
    {
        $eventSlug = $request->query->get('eventSlug') ?: null;
        $participantCategorySlug = $request->query->get('participantCategorySlug') ?: null;
        $participantCategory = $this->participantCategoryService->getParticipantTypeBySlug($participantCategorySlug);
        $event = $this->eventService->getRepository()->getEvent([EventRepository::CRITERIA_SLUG => $eventSlug]);
        $scoped = (null !== $event) || (null !== $participantCategory);

        $filterKey = $request->query->getString('filter', self::FILTER_ALL);
        if (!$this->isKnownFilter($filterKey)) {
            $filterKey = self::FILTER_ALL;
        }
        $selectedFlags = array_values(array_filter($request->query->all('flags'), 'is_string'));
        $advancedExpr = trim($request->query->getString('expr'));
        $advancedExpr = '' === $advancedExpr ? null : $advancedExpr;
        $showAll = $request->query->getBoolean('all');
        $page = max(1, $request->query->getInt('page', 1));

        $hasActiveFilter = self::FILTER_ALL !== $filterKey || [] !== $selectedFlags || null !== $advancedExpr;

        [$flagFacets, $slugToCategory] = $this->buildFlagOffering($selectedFlags);
        $expression = $this->compileFilterExpression($filterKey, $selectedFlags, $advancedExpr, $slugToCategory);

        $criteria = [
            // We always load deleted rows and let the compiled expression decide
            // (active filters add `not isDeleted()`, the "deleted" filter adds `isDeleted()`).
            ParticipantRepository::CRITERIA_INCLUDE_DELETED       => true,
            ParticipantRepository::CRITERIA_EVENT                 => $event,
            ParticipantRepository::CRITERIA_PARTICIPANT_CATEGORY  => $participantCategory,
            ParticipantRepository::CRITERIA_EVENT_RECURSIVE_DEPTH => 2,
        ];

        $loadAll = $scoped || $showAll;
        $pagination = null;
        if ($loadAll) {
            $loaded = $this->participantService->getParticipants($criteria);
        } else {
            $offset = ($page - 1) * self::PER_PAGE;
            $loaded = $this->participantService->getParticipants($criteria, true, self::PER_PAGE, $offset);
            $pagination = [
                'page'    => $page,
                'perPage' => self::PER_PAGE,
                'hasPrev' => $page > 1,
                'hasNext' => $loaded->count() >= self::PER_PAGE,
            ];
        }

        // Prime the LAZY collections the filter + row template walk, in a constant number
        // of queries (avoids the per-participant N+1).
        $ids = array_values(array_filter($loaded->map(static fn (Participant $p): ?int => $p->getId())->toArray()));
        $this->participantService->getRepository()->primeAggregationCollections($ids);

        $matched = $loaded->filter(fn (Participant $p): bool => $this->filterEvaluator->matches($p, $expression));
        $participantsArray = $matched->toArray();
        usort(
            $participantsArray,
            static fn (Participant $a, Participant $b): int => StringUtils::compareCzech($a->getSortableName(), $b->getSortableName()),
        );

        // Summary stats are computed from the full scoped set (pre-filter) — meaningless
        // and expensive for an unscoped paginated page, so skipped there.
        $stats = $loadAll ? $this->computeStats($loaded) : null;

        return $this->render("@OswisOrgOswisCalendar/web_admin/participants.html.twig", [
            'title'               => 'Přehled přihlášek :: ADMIN',
            'event'               => $event,
            'participantCategory' => $participantCategory,
            'participants'        => new ArrayCollection($participantsArray),
            'scoped'              => $scoped,
            'filterTabs'          => $this->buildFilterTabs($eventSlug, $participantCategorySlug, $filterKey),
            'flagFacets'          => $flagFacets,
            'activeFilter'        => $filterKey,
            'selectedFlags'       => $selectedFlags,
            'advancedExpr'        => $advancedExpr,
            'expression'          => $expression,
            'stats'               => $stats,
            'pagination'          => $pagination,
            'showAll'             => $showAll,
            'hasActiveFilter'     => $hasActiveFilter,
            'filterScopeWarning'  => !$loadAll && $hasActiveFilter,
            'availableFunctions'  => $this->filterEvaluator->getFunctionNames(),
            'eventSlug'           => $eventSlug,
            'participantCategorySlug' => $participantCategorySlug,
            'participantCategories'   => $this->participantCategoryService->getRepository()->findBy([], ['name' => 'ASC']),
        ]);
    }

    /**
     * Backward-compatible alias for the five legacy list URLs (/prihlasky/nezaplacene, …).
     * 301-redirects to the canonical list with the matching ?filter= so old bookmarks keep
     * working while there is a single page going forward. The target filter comes from the
     * route default.
     */
    public function legacyFilterRedirect(
        string $filter,
        ?string $eventSlug = null,
        ?string $participantCategorySlug = null,
    ): RedirectResponse {
        $query = array_filter([
            'eventSlug'               => $eventSlug,
            'participantCategorySlug' => $participantCategorySlug,
            'filter'                  => self::FILTER_ALL === $filter ? null : $filter,
        ], static fn (?string $value): bool => null !== $value);

        return $this->redirectToRoute('oswis_org_oswis_calendar_web_admin_participants_list', $query, Response::HTTP_MOVED_PERMANENTLY);
    }

    /**
     * Backward-compatible alias for the old path-form list URL
     * (/web_admin/seznam-prihlasek/{eventSlug}/{participantCategorySlug?}). 301-redirects to the
     * canonical query-form list, preserving any extra query params (filter/flags/expr/page).
     */
    public function legacyScopeRedirect(
        Request $request,
        string $eventSlug,
        ?string $participantCategorySlug = null,
    ): RedirectResponse {
        $query = $request->query->all();
        $query['eventSlug'] = $eventSlug;
        if (null !== $participantCategorySlug && '' !== $participantCategorySlug) {
            $query['participantCategorySlug'] = $participantCategorySlug;
        }

        return $this->redirectToRoute('oswis_org_oswis_calendar_web_admin_participants_list', $query, Response::HTTP_MOVED_PERMANENTLY);
    }

    /**
     * Registry of named filters. Each maps to an ExpressionLanguage fragment (or null for
     * the special "all"/"deleted" keys whose only effect is the deleted-row handling).
     *
     * @return list<array{key: string, label: string, group: string, expr: string|null}>
     */
    private function getFilterRegistry(): array
    {
        return [
            ['key' => self::FILTER_ALL,               'label' => 'Vše',                 'group' => '',         'expr' => null],
            ['key' => self::FILTER_UNPAID,            'label' => 'Nezaplacení',         'group' => 'Platby',   'expr' => 'remainingPrice() > 0'],
            ['key' => self::FILTER_UNPAID_DEPOSIT,    'label' => 'Nezaplacená záloha',  'group' => 'Platby',   'expr' => 'remainingDeposit() > 0'],
            ['key' => self::FILTER_OVERPAID,          'label' => 'Přeplacení',          'group' => 'Platby',   'expr' => 'remainingPrice() < 0'],
            ['key' => self::FILTER_FOOD,              'label' => 'Stravovací omezení',  'group' => 'Příznaky', 'expr' => sprintf("hasFlagOfType('%s')", RegistrationFlagCategory::TYPE_FOOD)],
            ['key' => self::FILTER_WITH_REGISTRATION, 'label' => 'S přihláškou',        'group' => 'Stav',     'expr' => 'hasRegistration()'],
            ['key' => self::FILTER_NOT_ACTIVATED,     'label' => 'Neaktivovaný účet',   'group' => 'Stav',     'expr' => 'not isActivated()'],
            ['key' => self::FILTER_WITH_NOTE,         'label' => 'S poznámkou',         'group' => 'Stav',     'expr' => 'hasNote()'],
            ['key' => self::FILTER_DELETED,           'label' => 'Smazané',             'group' => 'Stav',     'expr' => null],
        ];
    }

    private function isKnownFilter(string $key): bool
    {
        foreach ($this->getFilterRegistry() as $filter) {
            if ($filter['key'] === $key) {
                return true;
            }
        }

        return false;
    }

    private function registryExpr(string $key): ?string
    {
        foreach ($this->getFilterRegistry() as $filter) {
            if ($filter['key'] === $key) {
                return $filter['expr'];
            }
        }

        return null;
    }

    /**
     * Combine registry filter + flag facets + advanced expression + deleted handling into a
     * single boolean expression evaluated per participant.
     *
     * @param list<string>          $selectedFlags  flag slugs from ?flags[]
     * @param array<string, string> $slugToCategory known flag slug => category slug (untrusted slugs excluded)
     */
    private function compileFilterExpression(
        string $filterKey,
        array $selectedFlags,
        ?string $advancedExpr,
        array $slugToCategory,
    ): string {
        $parts = [];
        // Deleted handling: the dedicated "deleted" filter shows only soft-deleted rows,
        // every other filter excludes them (fixes the legacy "deleted shown among active").
        $parts[] = self::FILTER_DELETED === $filterKey ? 'isDeleted()' : 'not isDeleted()';

        $registryExpr = $this->registryExpr($filterKey);
        if (null !== $registryExpr) {
            $parts[] = $registryExpr;
        }

        $facetExpr = $this->compileFlagFacets($selectedFlags, $slugToCategory);
        if (null !== $facetExpr) {
            $parts[] = $facetExpr;
        }

        if (null !== $advancedExpr) {
            $parts[] = '('.$advancedExpr.')';
        }

        return implode(' and ', $parts);
    }

    /**
     * Faceted flag predicate: OR within a category, AND across categories. Only slugs known
     * to exist (present in $slugToCategory) are used — untrusted slugs are dropped, which
     * also prevents expression injection via ?flags[].
     *
     * @param list<string>          $selectedFlags
     * @param array<string, string> $slugToCategory
     */
    private function compileFlagFacets(array $selectedFlags, array $slugToCategory): ?string
    {
        /** @var array<string, list<string>> $byCategory */
        $byCategory = [];
        foreach ($selectedFlags as $slug) {
            if (!isset($slugToCategory[$slug])) {
                continue; // unknown/forged slug — ignore
            }
            $byCategory[$slugToCategory[$slug]][] = $slug;
        }
        if ([] === $byCategory) {
            return null;
        }
        $groupExpressions = [];
        foreach ($byCategory as $slugs) {
            $orParts = array_map(static fn (string $slug): string => sprintf("hasFlag('%s')", $slug), $slugs);
            $groupExpressions[] = '('.implode(' or ', $orParts).')';
        }

        return implode(' and ', $groupExpressions);
    }

    /**
     * Build the flag facet offering for the UI and the slug→category map for compilation.
     * Flags are grouped by their category; flags with no category fall under "Ostatní".
     *
     * @param list<string> $selectedFlags
     *
     * @return array{0: list<array{categorySlug: string, categoryName: string, flags: list<array{slug: string, label: string, selected: bool}>}>, 1: array<string, string>}
     */
    private function buildFlagOffering(array $selectedFlags): array
    {
        $selectedLookup = array_fill_keys($selectedFlags, true);
        /** @var array<string, array{categoryName: string, flags: list<array{slug: string, label: string, selected: bool}>}> $grouped */
        $grouped = [];
        $slugToCategory = [];

        /** @var RegistrationFlag $flag */
        foreach ($this->registrationFlagRepository->findBy([], ['id' => 'ASC']) as $flag) {
            $slug = $flag->getSlug();
            if ('' === $slug) {
                continue;
            }
            $category = $flag->getCategory();
            $categorySlug = $category?->getSlug() ?? '';
            $categoryName = $category?->getName() ?? 'Ostatní';
            $slugToCategory[$slug] = $categorySlug;
            $grouped[$categorySlug]['categoryName'] ??= $categoryName;
            $grouped[$categorySlug]['flags'][] = [
                'slug'     => $slug,
                'label'    => $flag->getShortName() ?? $flag->getName() ?? $slug,
                'selected' => isset($selectedLookup[$slug]),
            ];
        }

        $facets = [];
        foreach ($grouped as $categorySlug => $data) {
            $facets[] = [
                'categorySlug' => $categorySlug,
                'categoryName' => $data['categoryName'],
                'flags'        => $data['flags'],
            ];
        }

        return [$facets, $slugToCategory];
    }

    /**
     * @return list<array{key: string, url: string, label: string, group: string, active: bool}>
     */
    private function buildFilterTabs(?string $eventSlug, ?string $participantCategorySlug, string $active): array
    {
        $tabs = [];
        foreach ($this->getFilterRegistry() as $filter) {
            $query = ['eventSlug' => $eventSlug, 'participantCategorySlug' => $participantCategorySlug];
            if (self::FILTER_ALL !== $filter['key']) {
                $query['filter'] = $filter['key'];
            }
            $tabs[] = [
                'key'    => $filter['key'],
                'url'    => $this->generateUrl('oswis_org_oswis_calendar_web_admin_participants_list', $query),
                'label'  => $filter['label'],
                'group'  => $filter['group'],
                'active' => $filter['key'] === $active,
            ];
        }

        return $tabs;
    }

    /**
     * Summary statistics over a (pre-filter) participant collection.
     *
     * @param Collection<int, Participant> $participants
     *
     * @return array{total: int, deleted: int, paid: int, unpaid: int, sumPaid: int, sumPrice: int, sumRemaining: int}
     */
    private function computeStats(Collection $participants): array
    {
        $stats = ['total' => 0, 'deleted' => 0, 'paid' => 0, 'unpaid' => 0, 'sumPaid' => 0, 'sumPrice' => 0, 'sumRemaining' => 0];
        foreach ($participants as $participant) {
            if ($participant->isDeleted()) {
                ++$stats['deleted'];
                continue;
            }
            ++$stats['total'];
            $stats['sumPaid'] += $participant->getPaidPrice();
            $stats['sumPrice'] += $participant->getPrice();
            $remaining = $participant->getRemainingPrice();
            if ($remaining > 0) {
                ++$stats['unpaid'];
                $stats['sumRemaining'] += $remaining;
            } else {
                ++$stats['paid'];
            }
        }

        return $stats;
    }

    public function getParticipantsData(
        ?string $eventSlug = null,
        ?string $participantCategorySlug = null,
        bool $includeDeleted = true
    ): array {
        $data = [];
        $data['participantCategory']
            = $this->participantCategoryService->getParticipantTypeBySlug($participantCategorySlug);
        $data['event'] = $this->eventService->getRepository()->getEvent([EventRepository::CRITERIA_SLUG => $eventSlug]);
        $data['participants'] = $this->participantService->getParticipants([
            ParticipantRepository::CRITERIA_INCLUDE_DELETED => $includeDeleted,
            ParticipantRepository::CRITERIA_EVENT => $data['event'],
            ParticipantRepository::CRITERIA_PARTICIPANT_CATEGORY => $data['participantCategory'],
            ParticipantRepository::CRITERIA_EVENT_RECURSIVE_DEPTH => 2,
        ]);
        $data['title'] = "Přehled účastníků :: ADMIN";
        $participantsArray = $data['participants']->toArray();
        usort($participantsArray, static function (Participant $a, Participant $b) {
            return StringUtils::compareCzech($a->getSortableName(), $b->getSortableName());
        });
        $data['participants'] = new ArrayCollection($participantsArray);

        return $data;
    }

    /**
     * @throws InvalidArgumentException
     * @throws \League\Csv\CannotInsertRecord
     * @throws \League\Csv\Exception
     * @throws \Mpdf\MpdfException
     * @throws \Twig\Error\LoaderError
     * @throws \Twig\Error\RuntimeError
     * @throws \Twig\Error\SyntaxError
     */
    public function showParticipantsCsv(Request $request): Response
    {
        $eventSlug = $request->query->get('eventSlug') ?: null;
        $participantCategorySlug = $request->query->get('participantCategorySlug') ?: null;
        $includeDeleted = $request->query->getBoolean('includeDeleted');
        $data = $this->getParticipantsData($eventSlug, $participantCategorySlug, $includeDeleted);
        $participants = $data['participants'] ?? null;
        if (!$participants instanceof Collection) {
            $participants = new ArrayCollection();
        }
        $columnKeys = array_values(array_filter($request->query->all('columns'), 'is_string'));
        $exportRequest = new ExportRequest(
            ExportFormat::fromRequest($request->query->getString('format')),
            [] === $columnKeys ? null : $columnKeys,
        );

        return $this->exportResponseFactory->toResponse(
            $this->exportManager->render($this->participantExportDefinition, $participants, $exportRequest),
        );
    }

    public function showYearsCompare(?string $eventSeriesSlug = null): Response
    {
        $events = $this->eventService->getRepository()->getEvents([
            EventRepository::CRITERIA_SERIES_SLUG => $eventSeriesSlug,
            EventRepository::CRITERIA_TYPE_STRING => 'year-of-event',
        ]);

        return $this->render("@OswisOrgOswisCalendar/web_admin/years-compare.html.twig", [
            'title' => "Srovnání ročníků :: ADMIN",
            'events' => $events,
        ]);
    }


    /**
     * @throws NotFoundException
     */
    public function showEvent(?string $eventSlug = null): Response
    {
        $event = $this->eventService->getRepository()->getEvent([EventRepository::CRITERIA_SLUG => $eventSlug]);
        $defaultEvent = $this->eventService->getDefaultEvent();
        $event ??= $defaultEvent;
        if (null === $event) {
            throw new NotFoundException("Událost '$eventSlug' nenalezena.");
        }

        return $this->render('@OswisOrgOswisCalendar/web_admin/event.html.twig', [
            'title'          => 'Přehled události :: ADMIN',
            'event'          => $event,
            'isDefaultEvent' => $event === $defaultEvent,
            ...$this->eventAggregationsService->getEventAggregations($event),
        ]);
    }
}
