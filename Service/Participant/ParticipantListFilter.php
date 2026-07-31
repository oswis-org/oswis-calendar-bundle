<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Service\Participant;

use Doctrine\Common\Collections\Collection;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Entity\Registration\RegistrationFlag;
use OswisOrg\OswisCalendarBundle\Entity\Registration\RegistrationFlagCategory;
use OswisOrg\OswisCalendarBundle\Repository\Registration\RegistrationFlagRepository;
use OswisOrg\OswisCoreBundle\Utils\StringUtils;
use Symfony\Component\HttpFoundation\Request;

/**
 * JEDINÝ vlastník otázky „které přihlášky se mají vypsat a v jakém pořadí".
 *
 * Vzniklo 2026-07-31 poté, co se ukázalo, že tutéž otázku zodpovídaly TŘI různé cesty —
 * obrazovka seznamu, export ve web adminu a exportní endpoint pro mobilní aplikaci — a nutně
 * se rozešly: obrazovka filtrovala, exporty ne. Uživatel si vyfiltroval nedoplacené, dal export
 * a dostal celou akci (změřeno: 2 řádky na obrazovce vs. 309 v exportu).
 *
 * Pravidlo: kdo vypisuje přihlášky, ptá se TÉTO služby. Filtrovat „skoro stejně" na dvou místech
 * je to, co tenhle problém vyrobilo.
 */
final class ParticipantListFilter
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

    public function __construct(
        private readonly ParticipantFilterEvaluator $filterEvaluator,
        private readonly RegistrationFlagRepository $registrationFlagRepository,
    ) {
    }

    /**
     * Kompletní brána: z požadavku vezme filtr, fasety, výraz, hledání i řazení a aplikuje je
     * na už načtenou (scopovanou) množinu. Tohle volají obrazovka i exporty.
     *
     * @param Collection<int, Participant> $loaded
     *
     * @return list<Participant>
     */
    public function applyFromRequest(Collection $loaded, Request $request): array
    {
        $filterKey = $request->query->getString('filter', self::FILTER_ALL);
        if (!$this->isKnownFilter($filterKey)) {
            $filterKey = self::FILTER_ALL;
        }
        $selectedFlags = array_values(array_filter($request->query->all('flags'), 'is_string'));
        $advancedExpr = trim($request->query->getString('expr'));
        $advancedExpr = '' === $advancedExpr ? null : $advancedExpr;
        $q = trim($request->query->getString('q'));
        $q = '' === $q ? null : $q;
        $sort = $this->normalizeSort($request->query->getString('sort', self::SORT_NAME));
        $dir = 'desc' === $request->query->getString('dir') ? 'desc' : 'asc';
        [, $slugToCategory] = $this->buildFlagOffering($selectedFlags);
        $expression = $this->compileFilterExpression($filterKey, $selectedFlags, $advancedExpr, $slugToCategory, null === $q);

        return $this->apply($loaded, $expression, $q, $sort, $dir);
    }

    /**
     * Aplikuje předpřipravený výraz + hledání + řazení (obrazovka si výraz staví sama, protože
     * ho potřebuje i pro fasety).
     *
     * @param Collection<int, Participant> $loaded
     *
     * @return list<Participant>
     */
    public function apply(Collection $loaded, ?string $expression, ?string $q, string $sort, string $dir): array
    {
        $matched = $loaded->filter(fn (Participant $p): bool => $this->filterEvaluator->matches($p, $expression));
        if (null !== $q) {
            $matched = $matched->filter(fn (Participant $p): bool => $this->participantMatchesQuery($p, $q));
        }

        return $this->sortParticipants(array_values($matched->toArray()), $sort, $dir);
    }

    /**
     * @param list<Participant> $participants
     *
     * @return list<Participant>
     */
    public function sortParticipants(array $participants, string $sort, string $dir): array
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

    public function normalizeSort(string $sort): string
    {
        return in_array($sort, [self::SORT_NAME, self::SORT_PAYMENT, self::SORT_PRICE, self::SORT_CREATED, self::SORT_ID], true)
            ? $sort : self::SORT_NAME;
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
    public function participantMatchesQuery(Participant $participant, string $query): bool
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

    /**
     * Registry of named filters. Each maps to an ExpressionLanguage fragment (or null for
     * the special "all"/"deleted" keys whose only effect is the deleted-row handling).
     *
     * @return list<array{key: string, label: string, group: string, expr: string|null}>
     */
    public function getFilterRegistry(): array
    {
        return [
            ['key' => self::FILTER_ALL,            'label' => 'Vše',                 'group' => '',         'expr' => null],
            ['key' => self::FILTER_PAID,           'label' => 'Zaplaceno',           'group' => 'Platby',   'expr' => 'remainingPrice() == 0'],
            ['key' => self::FILTER_UNPAID,         'label' => 'Nedoplaceno',         'group' => 'Platby',   'expr' => 'remainingPrice() > 0'],
            ['key' => self::FILTER_UNPAID_DEPOSIT, 'label' => 'Nezaplacená záloha',  'group' => 'Platby',   'expr' => 'remainingDeposit() > 0'],
            ['key' => self::FILTER_UNPAID_BALANCE, 'label' => 'Nezaplacený doplatek','group' => 'Platby',   'expr' => 'remainingDeposit() <= 0 and remainingPrice() > 0'],
            ['key' => self::FILTER_OVERPAID,       'label' => 'Přeplaceno',          'group' => 'Platby',   'expr' => 'remainingPrice() < 0'],
            // `not isConfirmed()`, ne `not isActivated()`: druhé se ptá na účet kontaktu, který může být
            // aktivovaný z dřívější přihlášky — takový účastník pak v seznamu nepotvrzených CHYBĚL a
            // nebylo ho jak dohnat (2026-07-29).
            ['key' => self::FILTER_NOT_ACTIVATED,  'label' => 'Neaktivované',        'group' => 'Stav',     'expr' => 'not isConfirmed()'],
            ['key' => self::FILTER_WITH_NOTE,      'label' => 'S poznámkou',         'group' => 'Stav',     'expr' => 'hasNote()'],
            ['key' => self::FILTER_FOOD,           'label' => 'Stravovací omezení',  'group' => 'Příznaky', 'expr' => sprintf("hasFlagOfType('%s')", RegistrationFlagCategory::TYPE_FOOD)],
            ['key' => self::FILTER_DELETED,        'label' => 'Smazané',             'group' => 'Stav',     'expr' => null],
        ];
    }

    public function isKnownFilter(string $key): bool
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
    public function compileFilterExpression(
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
    public function buildFlagOffering(array $selectedFlags): array
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
}
