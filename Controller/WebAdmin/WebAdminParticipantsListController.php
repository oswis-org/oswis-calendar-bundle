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
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantCategory;
use OswisOrg\OswisCalendarBundle\Entity\Registration\RegistrationFlag;
use OswisOrg\OswisCalendarBundle\Entity\Registration\RegistrationFlagCategory;
use OswisOrg\OswisCalendarBundle\Export\ParticipantExportDefinition;
use OswisOrg\OswisCalendarBundle\Entity\Event\Event;
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
use OswisOrg\OswisCoreBundle\Service\ExportService;
use OswisOrg\OswisCoreBundle\Utils\StringUtils;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
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
    public const string FILTER_PAID              = 'paid';
    public const string FILTER_UNPAID            = 'unpaid';
    public const string FILTER_UNPAID_DEPOSIT    = 'unpaid-deposit';
    public const string FILTER_UNPAID_BALANCE    = 'unpaid-balance';
    public const string FILTER_OVERPAID          = 'overpaid';
    public const string FILTER_FOOD              = 'food';
    public const string FILTER_WITH_REGISTRATION = 'with-registration';
    public const string FILTER_NOT_ACTIVATED     = 'not-activated';
    public const string FILTER_WITH_NOTE         = 'with-note';
    public const string FILTER_DELETED           = 'deleted';

    public const string SORT_NAME    = 'name';
    public const string SORT_PAYMENT = 'payment';
    public const string SORT_PRICE   = 'price';
    public const string SORT_CREATED = 'created';
    public const string SORT_ID      = 'id';

    /** Page size for the unscoped (all-participants) paginated view. */
    private const int PER_PAGE = 100;

    /**
     * Hard ceiling on rows in a single export (CSV/PDF) — shared with the API export controller.
     * A full export hydrates the whole heavy Participant graph per row; an unscoped all-years dump
     * (thousands of rows) exhausts memory. We load at most EXPORT_MAX_ROWS+1 (true SQL LIMIT →
     * never hydrate beyond the cap) and, when the scope exceeds the cap, REFUSE the export with a
     * clear error rather than silently truncating — the user is told to narrow the scope (pick an
     * event/year). Scoped exports (per turnus/ročník) sit well under this, so the everyday path is
     * unaffected. {@see ParticipantExportDefinition::MAX_EXPORT_ROWS}.
     */
    private const int EXPORT_MAX_ROWS = ParticipantExportDefinition::MAX_EXPORT_ROWS;

    /** Default participant type when none is requested — the everyday "Účastník" view. */
    private const string DEFAULT_PARTICIPANT_CATEGORY = 'ucastnik';

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
        public ExportService $exportService,
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
        // Scope: one or more events (?eventSlug= single + ?events[] multi) and/or a category.
        [$events, $eventSlugs] = $this->resolveEventsFromRequest($request);
        // Default the participant type to "Účastník" (the everyday view) when none is given;
        // an explicit empty value (the "— typ účastníka —" option) means *all types*.
        if ($request->query->has('participantCategorySlug')) {
            $participantCategorySlug = $request->query->get('participantCategorySlug') ?: null;
        } else {
            $participantCategorySlug = self::DEFAULT_PARTICIPANT_CATEGORY;
        }
        $participantCategory = $this->participantCategoryService->getParticipantTypeBySlug($participantCategorySlug);
        $depthRaw = $request->query->get('depth');
        $depthOverride = (null !== $depthRaw && '' !== $depthRaw) ? max(0, (int) $depthRaw) : null;
        $allEvents = $request->query->getBoolean('allEvents');

        // Default scope = the current default event. Showing every participant across all
        // events at once is opt-in (?allEvents=1), not the landing view. The decision MUST
        // look at explicit query intent (has 'participantCategorySlug'), not the resolved
        // category — since 89a16b6 the category defaults to "ucastnik" and is never null,
        // which silently disabled this block (bare menu click listed ALL events again).
        $isDefaultScope = false;
        if ([] === $events && !$request->query->has('participantCategorySlug') && !$allEvents) {
            $defaultEvent = $this->eventService->getDefaultEvent();
            if (null !== $defaultEvent) {
                $events = [$defaultEvent];
                $isDefaultScope = true;
            }
        }
        $scoped = ([] !== $events) || (null !== $participantCategory);

        $filterKey = $request->query->getString('filter', self::FILTER_ALL);
        if (!$this->isKnownFilter($filterKey)) {
            $filterKey = self::FILTER_ALL;
        }
        $selectedFlags = array_values(array_filter($request->query->all('flags'), 'is_string'));
        $advancedExpr = trim($request->query->getString('expr'));
        $advancedExpr = '' === $advancedExpr ? null : $advancedExpr;
        $sort = $this->normalizeSort($request->query->getString('sort', self::SORT_NAME));
        $dir = 'desc' === $request->query->getString('dir') ? 'desc' : 'asc';
        $showAll = $request->query->getBoolean('all');
        $page = max(1, $request->query->getInt('page', 1));
        // Free-text search (name / e-mail / phone / variable symbol). Diacritic-insensitive,
        // matched in PHP over the loaded set (see participantMatchesQuery()).
        $q = trim($request->query->getString('q'));
        $q = '' === $q ? null : $q;

        $hasActiveFilter = self::FILTER_ALL !== $filterKey || [] !== $selectedFlags || null !== $advancedExpr || null !== $q;

        [$flagFacets, $slugToCategory] = $this->buildFlagOffering($selectedFlags);
        // When a text search is active, span active + deleted (so a deleted person is findable
        // by name); otherwise keep the normal deleted exclusion.
        $expression = $this->compileFilterExpression($filterKey, $selectedFlags, $advancedExpr, $slugToCategory, null === $q);

        $loadAll = $scoped || $showAll;
        $pagination = null;
        if ($scoped) {
            $loaded = $this->loadScopedParticipants($events, $participantCategory, $depthOverride);
        } elseif ($showAll) {
            $loaded = $this->participantService->getParticipants($this->unscopedCriteria());
        } else {
            $offset = ($page - 1) * self::PER_PAGE;
            $loaded = $this->participantService->getParticipants($this->unscopedCriteria(), true, self::PER_PAGE, $offset);
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
        // When searching, also prime the contact details (phones/e-mails) so the text match
        // doesn't fire one lazy query per participant for phone/VS.
        $this->participantService->getRepository()->primeAggregationCollections($ids, null !== $q);

        // Per-flag participant counts over the loaded (scoped) set — shown next to each facet
        // option (how many in the current scope carry that flag), like an e-shop facet count.
        $flagCounts = $this->computeFlagCounts($loaded);

        $matched = $loaded->filter(fn (Participant $p): bool => $this->filterEvaluator->matches($p, $expression));
        if (null !== $q) {
            $matched = $matched->filter(fn (Participant $p): bool => $this->participantMatchesQuery($p, $q));
        }
        $participantsArray = $this->sortParticipants(array_values($matched->toArray()), $sort, $dir);

        // Summary stats reflect the *filtered* set (so picking "Nezaplacení" or searching
        // recomputes the totals to match what's shown). Skipped on the unscoped paginated
        // page — meaningless/expensive for a single page of an unbounded set.
        $stats = $loadAll ? $this->computeStats($matched) : null;
        // Per-pill counts over the scoped set (pre-filter), so each status pill shows how many
        // in this scope match it (e-shop-style) — stable regardless of which pill is active.
        $pillCounts = $loadAll ? $this->computePillCounts($loaded) : [];

        // The scope/sort params that every in-page control must carry forward (as hidden
        // fields in the GET filter form and as query merges in links) so nothing is lost.
        $scopeParams = $this->buildScopeParams($eventSlugs, $participantCategorySlug, $depthOverride, $sort, $dir, $allEvents, $q);

        // Year events (+ their turnusy via subEvents in the template) feed the event
        // picker dropdown — a single control to jump to any year/turnus/all events,
        // replacing the ad-hoc chip + scope links. Sorted newest-first.
        /** @var list<Event> $yearEvents */
        $yearEvents = array_values($this->eventService->getRepository()->getEvents([
            EventRepository::CRITERIA_TYPE_STRING => 'year-of-event',
        ])->toArray());
        usort($yearEvents, static fn (Event $a, Event $b): int => ($b->getStartDateTimeRecursive()?->getTimestamp() ?? 0) <=> ($a->getStartDateTimeRecursive()?->getTimestamp() ?? 0));

        // Bohatší browser <title>: "Přehled přihlášek · <akce> · <typ> :: ADMIN".
        // (Breadcrumb z `title` bere jen základ před " · ", takže žádná redundantní proměnná.)
        // getShortName je prostý sloupec — NE mutující getName (viz OOM note).
        $titleParts = ['Přehled přihlášek'];
        if ($allEvents) {
            $titleParts[] = 'všechny akce';
        } elseif (1 === count($events)) {
            $titleParts[] = (string) ($events[0]->getShortName() ?: $events[0]->getName());
        } elseif (count($events) > 1) {
            $titleParts[] = count($events).' akcí';
        }
        if (null !== $participantCategory) {
            $titleParts[] = (string) $participantCategory->getName();
        }

        return $this->render("@OswisOrgOswisCalendar/web_admin/participants.html.twig", [
            'title'               => implode(' · ', $titleParts).' :: ADMIN',
            'event'               => 1 === count($events) ? $events[0] : null,
            'events'              => $events,
            'participantCategory' => $participantCategory,
            'participants'        => new ArrayCollection($participantsArray),
            'scoped'              => $scoped,
            'filterTabs'          => $this->buildFilterTabs($filterKey),
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
            'flagCounts'          => $flagCounts,
            'pillCounts'          => $pillCounts,
            'scopeParams'         => $scopeParams,
            'isDefaultScope'      => $isDefaultScope,
            'allEvents'           => $allEvents,
            'sort'                => $sort,
            'dir'                 => $dir,
            'q'                   => $q,
            'depthOverride'       => $depthOverride,
            'participantCategorySlug' => $participantCategorySlug,
            'participantCategories'   => $this->sortedParticipantCategories(),
            'yearEvents'              => $yearEvents,
            'defaultEvent'            => $this->eventService->getDefaultEvent(),
            'exportPresets'           => $this->participantExportDefinition->getPresets(),
        ]);
    }

    /**
     * Participant categories for the filter dropdown, sorted Czech-alphabetically in PHP.
     * (findBy()'s DB ORDER BY uses utf8mb4_unicode_ci, which sorts Č/Š/Ř… after Z.)
     *
     * @return list<ParticipantCategory>
     */
    private function sortedParticipantCategories(): array
    {
        $categories = $this->participantCategoryService->getRepository()->findBy([]);
        usort(
            $categories,
            static fn (ParticipantCategory $a, ParticipantCategory $b): int => StringUtils::compareCzech(
                $a->getName(),
                $b->getName(),
            ),
        );

        return $categories;
    }

    /**
     * Resolve the scope events from the request: ?eventSlug= (single) merged with ?events[]
     * (multi), de-duplicated, each resolved to an Event (unknown slugs dropped).
     *
     * @return array{0: list<\OswisOrg\OswisCalendarBundle\Entity\Event\Event>, 1: list<string>}
     */
    private function resolveEventsFromRequest(Request $request): array
    {
        $slugs = array_filter($request->query->all('events'), 'is_string');
        $single = $request->query->get('eventSlug');
        if (is_string($single) && '' !== $single) {
            $slugs[] = $single;
        }
        $slugs = array_values(array_unique(array_filter($slugs, static fn (string $s): bool => '' !== $s)));

        $events = [];
        $resolvedSlugs = [];
        foreach ($slugs as $slug) {
            $event = $this->eventService->getRepository()->getEvent([EventRepository::CRITERIA_SLUG => $slug]);
            if (null !== $event) {
                $events[] = $event;
                $resolvedSlugs[] = $slug;
            }
        }

        return [$events, $resolvedSlugs];
    }

    /**
     * Load the scoped participant set: union of each event's participants (recursing into
     * sub-events to the per-event default depth, or an explicit override) de-duplicated by id,
     * optionally narrowed to a participant category. Empty $events = category-only scope.
     *
     * @param list<\OswisOrg\OswisCalendarBundle\Entity\Event\Event> $events
     *
     * @return ArrayCollection<int, Participant>
     */
    private function loadScopedParticipants(array $events, ?ParticipantCategory $category, ?int $depthOverride, bool $includeDeleted = true, ?int $limit = null): ArrayCollection
    {
        if ([] === $events) {
            $collection = $this->participantService->getParticipants([
                ParticipantRepository::CRITERIA_INCLUDE_DELETED      => $includeDeleted,
                ParticipantRepository::CRITERIA_PARTICIPANT_CATEGORY => $category,
                ParticipantRepository::CRITERIA_EVENT_RECURSIVE_DEPTH => 0,
            ], true, $limit);

            return new ArrayCollection($collection->toArray());
        }
        /** @var array<int, Participant> $byId */
        $byId = [];
        foreach ($events as $event) {
            $depth = $depthOverride ?? $this->defaultDepth($event);
            $collection = $this->participantService->getParticipants([
                ParticipantRepository::CRITERIA_INCLUDE_DELETED       => $includeDeleted,
                ParticipantRepository::CRITERIA_EVENT                 => $event,
                ParticipantRepository::CRITERIA_PARTICIPANT_CATEGORY  => $category,
                ParticipantRepository::CRITERIA_EVENT_RECURSIVE_DEPTH => $depth,
            ], true, $limit);
            foreach ($collection as $participant) {
                $id = $participant->getId();
                if (null !== $id) {
                    $byId[$id] = $participant;
                }
            }
            // Stop loading further events once the cap is reached — the export will be refused
            // anyway. Break on EITHER the merged unique count OR this single event already hitting
            // the sentinel (a lone event over the cap → the whole export is over it). This bounds
            // hydration at ~2× the cap regardless of how many events are multi-selected, without
            // any silent truncation: every break path leaves count($byId) ≥ limit, so the caller's
            // over-cap check fires.
            if (null !== $limit && (count($byId) >= $limit || $collection->count() >= $limit)) {
                break;
            }
        }

        return new ArrayCollection(array_values($byId));
    }

    /**
     * Sensible default sub-event recursion depth for an event.
     *
     * A year (ročník) aggregates its turnusy, which are the registration units, so it recurses
     * one level (depth 1) to gather their participants. Any other event (a turnus or a plain
     * event) shows only its own direct registrations (depth 0) — by design we do NOT drill into
     * a turnus's sub-events (e.g. skupiny) by default; use the sub-event links or an explicit
     * ?depth= override for that.
     */
    private function defaultDepth(\OswisOrg\OswisCalendarBundle\Entity\Event\Event $event): int
    {
        return $event->isYear() ? 1 : 0;
    }

    /**
     * @param list<Participant> $participants
     *
     * @return list<Participant>
     */
    private function sortParticipants(array $participants, string $sort, string $dir): array
    {
        $factor = 'desc' === $dir ? -1 : 1;
        usort($participants, static function (Participant $a, Participant $b) use ($sort, $factor): int {
            $comparison = match ($sort) {
                self::SORT_PAYMENT => $a->getRemainingPrice() <=> $b->getRemainingPrice(),
                self::SORT_PRICE   => $a->getPrice() <=> $b->getPrice(),
                self::SORT_CREATED => ($a->getCreatedAt()?->getTimestamp() ?? 0) <=> ($b->getCreatedAt()?->getTimestamp() ?? 0),
                self::SORT_ID      => ($a->getId() ?? 0) <=> ($b->getId() ?? 0),
                default            => StringUtils::compareCzech($a->getSortableName(), $b->getSortableName()),
            };

            return $factor * $comparison;
        });

        return $participants;
    }

    private function normalizeSort(string $sort): string
    {
        return in_array($sort, [self::SORT_NAME, self::SORT_PAYMENT, self::SORT_PRICE, self::SORT_CREATED, self::SORT_ID], true)
            ? $sort : self::SORT_NAME;
    }

    /**
     * Criteria for the unscoped (all events) view.
     *
     * @return array<string, mixed>
     */
    private function unscopedCriteria(): array
    {
        return [
            ParticipantRepository::CRITERIA_INCLUDE_DELETED       => true,
            ParticipantRepository::CRITERIA_EVENT_RECURSIVE_DEPTH => 0,
        ];
    }

    /**
     * The scope+sort query params to carry across every in-page control. Single event →
     * eventSlug; multiple → events[]. Defaults (sort=name, dir=asc, auto depth) are omitted.
     *
     * @param list<string> $eventSlugs
     *
     * @return array<string, string|int|list<string>>
     */
    /**
     * Free-text match of one participant against a search query. Diacritic-insensitive
     * (folds accents via {@see StringUtils::removeAccents()}, so "reznicek" finds "Řezníček")
     * across name, e-mail, phone and variable symbol. Phone/VS are additionally compared
     * digits-only so "608 192 514" and "608192514" both match.
     */
    private function participantMatchesQuery(Participant $participant, string $query): bool
    {
        $needle = mb_strtolower(StringUtils::removeAccents($query));
        if ('' === $needle) {
            return true;
        }
        $contact = $participant->getContactForRead();
        $textHaystacks = [
            $participant->getName(),
            $participant->getSortableName(),
            $contact?->getEmail(),
            $contact?->getPhone(),
            $participant->getVariableSymbol(),
        ];
        foreach ($textHaystacks as $value) {
            if (null !== $value && '' !== $value
                && str_contains(mb_strtolower(StringUtils::removeAccents($value)), $needle)) {
                return true;
            }
        }
        // Numeric match for phone / VS regardless of spacing/formatting.
        $digitsNeedle = preg_replace('/\D+/', '', $query) ?? '';
        if ('' !== $digitsNeedle) {
            foreach ([$contact?->getPhone(), $participant->getVariableSymbol()] as $number) {
                if (null !== $number && str_contains((string) preg_replace('/\D+/', '', $number), $digitsNeedle)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function buildScopeParams(array $eventSlugs, ?string $participantCategorySlug, ?int $depthOverride, string $sort, string $dir, bool $allEvents = false, ?string $q = null): array
    {
        $params = [];
        if (null !== $q) {
            $params['q'] = $q;
        }
        if (1 === count($eventSlugs)) {
            $params['eventSlug'] = $eventSlugs[0];
        } elseif (count($eventSlugs) > 1) {
            $params['events'] = $eventSlugs;
        }
        if ($allEvents) {
            $params['allEvents'] = 1;
        }
        if (null !== $participantCategorySlug) {
            $params['participantCategorySlug'] = $participantCategorySlug;
        }
        if (null !== $depthOverride) {
            $params['depth'] = $depthOverride;
        }
        if (self::SORT_NAME !== $sort) {
            $params['sort'] = $sort;
        }
        if ('asc' !== $dir) {
            $params['dir'] = $dir;
        }

        return $params;
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
            ['key' => self::FILTER_ALL,            'label' => 'Vše',                 'group' => '',         'expr' => null],
            ['key' => self::FILTER_PAID,           'label' => 'Zaplaceno',           'group' => 'Platby',   'expr' => 'remainingPrice() == 0'],
            ['key' => self::FILTER_UNPAID,         'label' => 'Nedoplaceno',         'group' => 'Platby',   'expr' => 'remainingPrice() > 0'],
            ['key' => self::FILTER_UNPAID_DEPOSIT, 'label' => 'Nezaplacená záloha',  'group' => 'Platby',   'expr' => 'remainingDeposit() > 0'],
            ['key' => self::FILTER_UNPAID_BALANCE, 'label' => 'Nezaplacený doplatek','group' => 'Platby',   'expr' => 'remainingDeposit() <= 0 and remainingPrice() > 0'],
            ['key' => self::FILTER_OVERPAID,       'label' => 'Přeplaceno',          'group' => 'Platby',   'expr' => 'remainingPrice() < 0'],
            ['key' => self::FILTER_NOT_ACTIVATED,  'label' => 'Neaktivované',        'group' => 'Stav',     'expr' => 'not isActivated()'],
            ['key' => self::FILTER_WITH_NOTE,      'label' => 'S poznámkou',         'group' => 'Stav',     'expr' => 'hasNote()'],
            ['key' => self::FILTER_FOOD,           'label' => 'Stravovací omezení',  'group' => 'Příznaky', 'expr' => sprintf("hasFlagOfType('%s')", RegistrationFlagCategory::TYPE_FOOD)],
            ['key' => self::FILTER_DELETED,        'label' => 'Smazané',             'group' => 'Stav',     'expr' => null],
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
        bool $excludeDeleted = true,
    ): string {
        $parts = [];
        // Deleted handling: the dedicated "deleted" filter shows only soft-deleted rows;
        // every other filter excludes them (fixes the legacy "deleted shown among active") —
        // UNLESS a text search is active ($excludeDeleted=false), where we deliberately span
        // active + deleted so an admin can find a deleted person by name. Deleted rows are
        // visually flagged (red row + "❌ smazáno") so mixed results stay unambiguous.
        if (self::FILTER_DELETED === $filterKey) {
            $parts[] = 'isDeleted()';
        } elseif ($excludeDeleted) {
            $parts[] = 'not isDeleted()';
        }

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
     * Flags are grouped by their category (flags with no category fall under "Ostatní"), and
     * within a category into named sections — e.g. t-shirt sizes split into Pánská / Dámská the
     * way the registration form presents them. That split lives only in the flag slug/name
     * (there is no sub-category entity), so it's derived, not stored.
     *
     * @param list<string> $selectedFlags
     *
     * @return array{0: list<array{categorySlug: string, categoryName: string, sections: list<array{name: ?string, flags: list<array{slug: string, label: string, selected: bool}>}>}>, 1: array<string, string>}
     */
    private function buildFlagOffering(array $selectedFlags): array
    {
        $selectedLookup = array_fill_keys($selectedFlags, true);
        /** @var array<string, array{categoryName: string, sections: array<string, array{name: ?string, flags: list<array{slug: string, label: string, selected: bool}>}>}> $grouped */
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

            // Sub-section comes from the flag's own grouping logic (same as the registration
            // form: explicit form group, else t-shirt gender, else none) — systematic, works
            // for every flag including admin-only ones, and needs no per-event offer.
            $sectionName = $flag->getFlagGroupName();
            $sectionKey = $sectionName ?? '';

            $grouped[$categorySlug]['categoryName'] ??= $categoryName;
            $grouped[$categorySlug]['sections'][$sectionKey]['name'] = $sectionName;
            $grouped[$categorySlug]['sections'][$sectionKey]['flags'][] = [
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
                'sections'     => array_values($data['sections']),
            ];
        }

        return [$facets, $slugToCategory];
    }

    /**
     * Filter pills for the GET form (the selected scope/sort ride along as hidden fields, so
     * these only need the radio value/label/group/active flag — no per-tab URL).
     *
     * @return list<array{key: string, label: string, group: string, active: bool}>
     */
    private function buildFilterTabs(string $active): array
    {
        $tabs = [];
        foreach ($this->getFilterRegistry() as $filter) {
            $tabs[] = [
                'key'    => $filter['key'],
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
    /**
     * Count, over the given (scoped) set, how many non-deleted participants carry each flag,
     * keyed by flag slug — shown next to each facet option. Uses only primed collections (no
     * queries) and getSlug() (never the mutating getName(); see the OOM note in getTShirtGroup).
     *
     * @param Collection<int, Participant> $participants
     *
     * @return array<string, int>
     */
    private function computeFlagCounts(Collection $participants): array
    {
        $counts = [];
        foreach ($participants as $participant) {
            if ($participant->isDeleted()) {
                continue;
            }
            $seen = [];
            foreach ($participant->getFlagGroups() as $flagGroup) {
                if (null !== $flagGroup->getDeletedAt()) {
                    continue;
                }
                foreach ($flagGroup->getParticipantFlags() as $participantFlag) {
                    if (null !== $participantFlag->getDeletedAt()) {
                        continue;
                    }
                    $slug = $participantFlag->getFlag()?->getSlug();
                    if (null !== $slug && '' !== $slug && !isset($seen[$slug])) {
                        $seen[$slug] = true;
                        $counts[$slug] = ($counts[$slug] ?? 0) + 1;
                    }
                }
            }
        }

        return $counts;
    }

    /**
     * Count, over the scoped set, how many participants match each status pill — so the merged
     * pill+count bar can show "Nedoplaceno (12)" etc. Reuses the registry expressions (single
     * source of truth) so the counts can never diverge from what clicking the pill returns.
     * 'all' = non-deleted total, 'deleted' = deleted count, the rest = non-deleted matching expr.
     *
     * @param Collection<int, Participant> $participants
     *
     * @return array<string, int>
     */
    private function computePillCounts(Collection $participants): array
    {
        $nonDeleted = [];
        $deleted = 0;
        foreach ($participants as $participant) {
            if ($participant->isDeleted()) {
                ++$deleted;
            } else {
                $nonDeleted[] = $participant;
            }
        }
        $counts = [];
        foreach ($this->getFilterRegistry() as $filter) {
            $key = $filter['key'];
            if (self::FILTER_DELETED === $key) {
                $counts[$key] = $deleted;
                continue;
            }
            $expr = $filter['expr'];
            if (null === $expr) { // 'all'
                $counts[$key] = count($nonDeleted);
                continue;
            }
            $n = 0;
            foreach ($nonDeleted as $participant) {
                if ($this->filterEvaluator->matches($participant, $expr)) {
                    ++$n;
                }
            }
            $counts[$key] = $n;
        }

        return $counts;
    }

    /**
     * @param Collection<int, Participant> $participants
     *
     * @return array<string, int>
     */
    private function computeStats(Collection $participants): array
    {
        $stats = ['total' => 0, 'deleted' => 0, 'paid' => 0, 'depositPaid' => 0, 'unpaid' => 0, 'sumPaid' => 0, 'sumPrice' => 0, 'sumRemaining' => 0];
        foreach ($participants as $participant) {
            if ($participant->isDeleted()) {
                ++$stats['deleted'];
                continue;
            }
            ++$stats['total'];
            $stats['sumPaid'] += $participant->getPaidPrice();
            $stats['sumPrice'] += $participant->getPrice();
            $remaining = $participant->getRemainingPrice();
            if ($remaining <= 0) {
                ++$stats['paid']; // fully paid (or overpaid)
                continue;
            }
            $stats['sumRemaining'] += $remaining;
            // Still owing something: deposit covered vs nothing paid yet (matches the row badges).
            if ($participant->getRemainingDeposit() <= 0) {
                ++$stats['depositPaid'];
            } else {
                ++$stats['unpaid'];
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
        $scope = $this->loadExportScope($request);
        if ($scope instanceof Response) {
            return $scope; // over-cap redirect
        }
        $columnKeys = array_values(array_filter($request->query->all('columns'), 'is_string'));
        $exportRequest = new ExportRequest(
            ExportFormat::fromRequest($request->query->getString('format')),
            [] === $columnKeys ? null : $columnKeys,
            $scope['subtitle'],
        );

        return $this->exportResponseFactory->toResponse(
            $this->exportManager->render(
                $this->participantExportDefinition,
                new ArrayCollection($scope['participants']),
                $exportRequest,
            ),
        );
    }

    /**
     * Rich "browse-style" PDF of the participant list — the same visual content as the web admin
     * screen (coloured flag badges with prices, payment status, notes, contact), rendered
     * server-side through the branded PDF chrome. Distinct from the tabular CSV/PDF export.
     *
     * @throws \Mpdf\MpdfException
     * @throws \Twig\Error\LoaderError
     * @throws \Twig\Error\RuntimeError
     * @throws \Twig\Error\SyntaxError
     */
    public function showParticipantsListPdf(Request $request): Response
    {
        $scope = $this->loadExportScope($request);
        if ($scope instanceof Response) {
            return $scope; // over-cap redirect
        }
        $html = $this->renderView('@OswisOrgOswisCalendar/web_admin/participants-list-pdf.html.twig', [
            'participants' => $scope['participants'],
            'subtitle'     => $scope['subtitle'],
            'categoryName' => $scope['categoryName'],
            'eventNames'   => $scope['eventNames'],
            'count'        => $scope['count'],
        ]);
        $pdf = $this->exportService->getPdfFromHtml($html, false, 'Přehled přihlášek', $scope['subtitle'], ['přehled', 'přihlášky']);
        $filename = 'prehled-prihlasek_'.date('Y-m-d_His').'.pdf';

        return new Response($pdf, Response::HTTP_OK, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $filename),
        ]);
    }

    /**
     * Resolve + load + sort the scoped participant set for any export, applying the shared
     * {@see EXPORT_MAX_ROWS} cap. Returns the sorted participants + a human scope subtitle, or a
     * RedirectResponse (with a flash) when the scope exceeds the cap — never a silent truncation.
     *
     * @return array{participants: list<Participant>, subtitle: string, categoryName: string|null, eventNames: list<string>, count: int}|RedirectResponse
     */
    private function loadExportScope(Request $request): array|RedirectResponse
    {
        // Same scoped set the list shows (multi-event + depth aware). Unscoped = everything.
        [$events, ] = $this->resolveEventsFromRequest($request);
        // Default the participant type to "Účastník" (the everyday view) when none is given;
        // an explicit empty value (the "— typ účastníka —" option) means *all types*.
        if ($request->query->has('participantCategorySlug')) {
            $participantCategorySlug = $request->query->get('participantCategorySlug') ?: null;
        } else {
            $participantCategorySlug = self::DEFAULT_PARTICIPANT_CATEGORY;
        }
        $participantCategory = $this->participantCategoryService->getParticipantTypeBySlug($participantCategorySlug);
        $depthRaw = $request->query->get('depth');
        $depthOverride = (null !== $depthRaw && '' !== $depthRaw) ? max(0, (int) $depthRaw) : null;
        $includeDeleted = $request->query->getBoolean('includeDeleted');

        // Mirror list(): a bare query (no explicit scope) means the current default event,
        // so an export from the default landing view exports exactly what the list shows
        // (and not every participant ever, which would just trip the row cap).
        if ([] === $events && !$request->query->has('participantCategorySlug') && !$request->query->getBoolean('allEvents')) {
            $defaultEvent = $this->eventService->getDefaultEvent();
            if (null !== $defaultEvent) {
                $events = [$defaultEvent];
            }
        }

        // Load at most EXPORT_MAX_ROWS+1 (true SQL LIMIT → memory never blows up on a huge scope).
        // The +1 is the overflow sentinel: if it comes back, the real set is over the cap.
        $loadLimit = self::EXPORT_MAX_ROWS + 1;
        if ([] !== $events || null !== $participantCategory) {
            $participants = $this->loadScopedParticipants($events, $participantCategory, $depthOverride, $includeDeleted, $loadLimit);
        } else {
            $participants = $this->participantService->getParticipants([
                ParticipantRepository::CRITERIA_INCLUDE_DELETED       => $includeDeleted,
                ParticipantRepository::CRITERIA_EVENT_RECURSIVE_DEPTH => 0,
            ], true, $loadLimit);
        }
        // Over the cap → refuse with a clear error (never silently truncate). Bounce back to the
        // list (which paginates, so it renders fine) with the same scope so the user can narrow it.
        if ($participants->count() > self::EXPORT_MAX_ROWS) {
            $this->addFlash('danger', sprintf(
                'Export je omezen na %d záznamů a tento výběr je překračuje. Zužte rozsah – vyberte konkrétní akci nebo ročník (případně typ účastníka) – a export opakujte.',
                self::EXPORT_MAX_ROWS,
            ));
            $redirectParams = $request->query->all();
            unset($redirectParams['format'], $redirectParams['columns']);

            return $this->redirectToRoute('oswis_org_oswis_calendar_web_admin_participants_list', $redirectParams);
        }
        $participantsArray = $participants->toArray();
        usort(
            $participantsArray,
            static fn (Participant $a, Participant $b): int => StringUtils::compareCzech($a->getSortableName(), $b->getSortableName()),
        );
        // Human scope subtitle: akce/typ + počet — ať je výstup jednoznačně identifikovatelný.
        $scopeBits = [];
        if (1 === count($events)) {
            $scopeBits[] = (string) ($events[0]->getShortName() ?: $events[0]->getName());
        } elseif (count($events) > 1) {
            $scopeBits[] = count($events).' akcí';
        } else {
            $scopeBits[] = 'všechny akce';
        }
        if (null !== $participantCategory) {
            $scopeBits[] = (string) $participantCategory->getName();
        }
        $scopeBits[] = count($participantsArray).' záznamů';

        $eventNames = array_map(
            static fn (Event $e): string => (string) ($e->getShortName() ?: $e->getName()),
            $events,
        );

        return [
            'participants' => $participantsArray,
            'subtitle'     => implode(' · ', $scopeBits),
            'categoryName' => $participantCategory?->getName(),
            'eventNames'   => $eventNames,
            'count'        => count($participantsArray),
        ];
    }

    public function showYearsCompare(?string $eventSeriesSlug = null): Response
    {
        $events = $this->eventService->getRepository()->getEvents([
            EventRepository::CRITERIA_SERIES_SLUG => $eventSeriesSlug,
            EventRepository::CRITERIA_TYPE_STRING => 'year-of-event',
        ]);

        return $this->render("@OswisOrgOswisCalendar/web_admin/years-compare.html.twig", [
            'title'        => "Srovnání ročníků :: ADMIN",
            'events'       => $events,
            'defaultEvent' => $this->eventService->getDefaultEvent(),
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
            'defaultEvent'   => $defaultEvent,
            ...$this->eventAggregationsService->getEventAggregations($event),
        ]);
    }
}
