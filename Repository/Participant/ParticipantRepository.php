<?php
/**
 * @noinspection PhpSameParameterValueInspection
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Repository\Participant;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use OswisOrg\OswisAddressBookBundle\Entity\AbstractClass\AbstractContact;
use OswisOrg\OswisAddressBookBundle\Entity\Person;
use OswisOrg\OswisAddressBookBundle\Entity\ContactDetailCategory;
use OswisOrg\OswisCalendarBundle\Entity\Event\Event;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantCategory;
use OswisOrg\OswisCalendarBundle\Entity\ParticipantMail\ParticipantMail;
use OswisOrg\OswisCalendarBundle\Entity\Registration\RegistrationOffer;
use OswisOrg\OswisCoreBundle\Entity\AppUser\AppUser;

/** @extends ServiceEntityRepository<Participant> */
class ParticipantRepository extends ServiceEntityRepository
{
    public const CRITERIA_ID = 'id';
    public const CRITERIA_EVENT = 'event';
    public const CRITERIA_EVENT_RECURSIVE_DEPTH = 'eventRecursiveDepth';
    public const CRITERIA_PARTICIPANT_TYPE = 'participantType';
    public const CRITERIA_PARTICIPANT_CATEGORY = 'participantCategory';
    public const CRITERIA_OFFER = 'offer';
    public const CRITERIA_INCLUDE_DELETED = 'includeDeleted';
    public const CRITERIA_CONTACT = 'contact';
    public const CRITERIA_APP_USER = 'appUser';
    public const CRITERIA_VARIABLE_SYMBOL = 'variableSymbol';

    /**
     * @param ManagerRegistry $registry
     *
     * @throws LogicException
     */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Participant::class);
    }

    final public function findOneBy(array $criteria, ?array $orderBy = null): ?Participant
    {
        $result = parent::findOneBy($criteria, $orderBy);

        return $result instanceof Participant ? $result : null;
    }

    public function countParticipants(array $opts = []): ?int
    {
        return $this->getParticipants($opts)->count();
    }

    public function getParticipants(
        array $opts = [],
        ?bool $includeNotActivated = true,
        ?int $limit = null,
        ?int $offset = null,
    ): Collection
    {
        $queryBuilder = $this->getParticipantsQueryBuilder($opts, $limit, $offset);
        $result = $queryBuilder->getQuery()->getResult();

        return Participant::filterCollection(
        /** @phpstan-ignore-next-line */
            new ArrayCollection(is_array($result) ? $result : []),
            $includeNotActivated
        );
    }

    /**
     * Eager-primes the three LAZY to-many collections that the event aggregation dashboard
     * walks per attendee — flagGroups (which drags its already-EAGER flag/offer/category
     * subtree), payments and notes — in a constant number of queries instead of N lazy
     * SELECTs per participant (the N+1 the dashboard otherwise fires). The rows hydrate onto
     * the same managed Participant instances via the identity map, so this is purely a fetch
     * optimisation: it changes no data and produces identical aggregation numbers.
     *
     * Each collection is primed by its own query on purpose — fetch-joining two sibling
     * to-many relations in a single query would cartesian-explode the row count.
     *
     * @param list<int> $ids attendee ids already loaded via {@see getParticipants()}
     * @param bool $primeContactDetails also fetch-join contact phones/e-mails (their own query)
     *                                  — only needed by the free-text search to avoid an N+1 on
     *                                  phone/VS; skipped otherwise so the common path stays lean.
     */
    public function primeAggregationCollections(array $ids, bool $primeContactDetails = false): void
    {
        if ([] === $ids) {
            return;
        }
        foreach (array_chunk($ids, 200) as $chunk) {
            $this->createQueryBuilder('p')
                ->addSelect('pfg', 'pf')
                ->leftJoin('p.flagGroups', 'pfg')
                ->leftJoin('pfg.participantFlags', 'pf')
                ->where('p.id IN (:ids)')->setParameter('ids', $chunk)
                ->getQuery()->getResult();
            $this->createQueryBuilder('p')
                ->addSelect('pay')
                ->leftJoin('p.payments', 'pay')
                ->where('p.id IN (:ids)')->setParameter('ids', $chunk)
                ->getQuery()->getResult();
            $this->createQueryBuilder('p')
                ->addSelect('n')
                ->leftJoin('p.notes', 'n')
                ->where('p.id IN (:ids)')->setParameter('ids', $chunk)
                ->getQuery()->getResult();
            // participantContacts → contact (EAGER) → appUser (EAGER): the graph getContact()
            // walks per participant for gender + activated-user checks. The two to-one hops are
            // fetch-joined so the whole contact subtree comes with the collection in one query.
            $this->createQueryBuilder('p')
                ->addSelect('pc', 'c', 'cau')
                ->leftJoin('p.participantContacts', 'pc')
                ->leftJoin('pc.contact', 'c')
                ->leftJoin('c.appUser', 'cau')
                ->where('p.id IN (:ids)')->setParameter('ids', $chunk)
                ->getQuery()->getResult();
            // participantRegistrations → offer: getParticipantRegistration()/getOffer() reach
            // into this collection per participant for price/offer resolution. offer is fetch-
            // joined (it would EAGER-load anyway) so the registration subtree comes in one query.
            $this->createQueryBuilder('p')
                ->addSelect('pr', 'pro')
                ->leftJoin('p.participantRegistrations', 'pr')
                ->leftJoin('pr.offer', 'pro')
                ->where('p.id IN (:ids)')->setParameter('ids', $chunk)
                ->getQuery()->getResult();
            if ($primeContactDetails) {
                // Contact phones/e-mails (lazy to-many) for the free-text search — its own
                // query (joining details alongside another to-many would cartesian-explode).
                $this->createQueryBuilder('p')
                    ->addSelect('scpc', 'scc', 'scd')
                    ->leftJoin('p.participantContacts', 'scpc')
                    ->leftJoin('scpc.contact', 'scc')
                    ->leftJoin('scc.details', 'scd')
                    ->where('p.id IN (:ids)')->setParameter('ids', $chunk)
                    ->getQuery()->getResult();
            }
        }
    }

    public function getParticipantsQueryBuilder(array $opts = [], ?int $limit = null, ?int $offset = null): QueryBuilder
    {
        $queryBuilder = $this->createQueryBuilder('participant');
        // Eager-load only single-valued associations (ManyToOne). Joining the four
        // collection associations (notes, payments, participantRegistrations,
        // participantContacts) into the same SELECT multiplied rows by the cartesian
        // product of their cardinalities and pushed hydration past the 128 MB PHP
        // default on events with ~300+ participants. The Twig partial walks those
        // collections via getters, so Doctrine lazy-loads them per row — slower
        // wall-clock but bounded memory. Re-introduce eager loads behind a paginator
        // when paging the list view (out of scope for this hotfix).
        // contactAppUser: AbstractContact::$appUser is a fetch=EAGER OneToOne, so Doctrine
        // loads it for every hydrated contact regardless — fetch-joining it here folds those
        // N single-row eager SELECTs into this one query (a behaviour-preserving, pagination-
        // safe to-one join: it changes no rows and no results, only the query count).
        $select = 'participant, offer, contact, event, participantCategory, contactAppUser';
        $queryBuilder->select($select);
        $queryBuilder->leftJoin('participant.offer', 'offer');
        $queryBuilder->leftJoin('participant.contact', 'contact');
        $queryBuilder->leftJoin('contact.appUser', 'contactAppUser');
        $queryBuilder->leftJoin('participant.event', 'event');
        $queryBuilder->leftJoin('participant.participantCategory', 'participantCategory');
        $this->setSuperEventQuery($queryBuilder, $opts);
        $this->setIdQuery($queryBuilder, $opts);
        $this->setRangeQuery($queryBuilder, $opts);
        $this->setParticipantCategoryQuery($queryBuilder, $opts);
        $this->setParticipantTypeQuery($queryBuilder, $opts);
        $this->setIncludeDeletedQuery($queryBuilder, $opts);
        $this->setContactQuery($queryBuilder, $opts);
        $this->setAppUserQuery($queryBuilder, $opts);
        $this->setVSQuery($queryBuilder, $opts);
        $this->setLimit($queryBuilder, $limit, $offset);
        $this->setOrderBy($queryBuilder);

        return $queryBuilder;
    }

    /**
     * Count active attendee participants per direct sub-event of $parentEvent.
     *
     * Returns a map { (int)subEventId => (int)count } so callers can index without
     * issuing an extra COUNT() per sub-event in a loop.
     *
     * @return array<int, int>
     */
    public function countAttendeesGroupedBySubEvent(Event $parentEvent): array
    {
        $rows = $this->createQueryBuilder('p')
            ->select('IDENTITY(p.event) AS eventId, COUNT(p.id) AS cnt')
            ->innerJoin('p.event', 'e')
            ->leftJoin('p.participantCategory', 'pc')
            ->where('e.superEvent = :parent')
            ->andWhere('pc.type = :attendeeType')
            ->andWhere('p.deletedAt IS NULL')
            ->setParameter('parent', $parentEvent)
            ->setParameter('attendeeType', ParticipantCategory::TYPE_ATTENDEE)
            ->groupBy('p.event')
            ->getQuery()
            ->getArrayResult();

        $out = [];
        foreach ($rows as $r) {
            if (!is_array($r) || !isset($r['eventId'], $r['cnt']) || !is_numeric($r['eventId']) || !is_numeric($r['cnt'])) {
                continue;
            }
            $out[(int) $r['eventId']] = (int) $r['cnt'];
        }

        return $out;
    }

    /**
     * Přihlášky, které čekají na potvrzení — účet u nich není aktivovaný.
     *
     * Dokud člověk neklikne na odkaz v ověřovacím e-mailu, přihláška existuje, ale nic dalšího
     * se s ní neděje: nechodí shrnutí s pokyny k platbě, nedostane se do aplikace. Tým to
     * dosud nikde neviděl pohromadě — přišlo se na to, až když dotyčný napsal, že mu nic
     * nedorazilo. Nejčastější příčina je překlep v adrese (prod #3846 „…@gmal.com") nebo
     * propadlý odkaz.
     *
     * Ukazuje se i datum posledního odeslaného e-mailu, protože rozhodnutí zní „poslat znovu,
     * nebo zavolat?" a to se bez něj udělat nedá.
     *
     * @return list<array{id: int, name: string, email: ?string, registered: ?\DateTimeInterface,
     *                    lastMail: ?\DateTimeInterface}>
     */
    public function findWaitingForActivation(Event $parentEvent, int $limit = 30): array
    {
        // ⚠️ `lastMail` se z agregace vrací jako ŘETĚZEC („2026-07-15 13:08:11"), ne jako
        // DateTime — Doctrine hydratuje typy jen u mapovaných polí, ne u výsledku MAX().
        // Šablona na něm volala `.format()` a celá úvodní stránka administrace spadla na 500.
        /** @var list<array{id: int|string, name: ?string, email: ?string,
         *                 registered: ?\DateTimeInterface, lastMail: ?string}> $rows */
        $rows = $this->createQueryBuilder('p')
            ->select(
                'p.id AS id, c.name AS name, u.email AS email, p.createdAt AS registered,'
                .' (SELECT MAX(m2.sent) FROM '.ParticipantMail::class.' m2 WHERE m2.participant = p) AS lastMail'
            )
            ->innerJoin('p.event', 'e')
            ->innerJoin('p.participantContacts', 'pc')
            ->innerJoin('pc.contact', 'c')
            ->leftJoin('c.appUser', 'u')
            ->where('e.superEvent = :parent')
            ->andWhere('p.deletedAt IS NULL')
            // `activated IS NULL` je totéž kritérium, jaké používá `hasActivatedContactUser()`;
            // účet bez uživatele spadá do stejné skupiny — taky se nemá kdo přihlásit.
            ->andWhere('u.id IS NULL OR u.activated IS NULL')
            ->setParameter('parent', $parentEvent)
            ->orderBy('p.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id'         => (int) $row['id'],
                'name'       => is_string($row['name'] ?? null) ? $row['name'] : '',
                'email'      => is_string($row['email'] ?? null) ? $row['email'] : null,
                'registered' => $row['registered'] ?? null,
                'lastMail'   => is_string($row['lastMail'] ?? null) && '' !== $row['lastMail']
                    ? new \DateTimeImmutable($row['lastMail'])
                    : null,
            ];
        }

        return $out;
    }

    /**
     * Přihlášky, kterým se NIKDY nedoručilo shrnutí — tedy lidé bez pokynů k platbě.
     *
     * PROČ: shrnutí se posílá při registraci ({@see \OswisOrg\OswisCalendarBundle\EventSubscriber\ParticipantSubscriber::postWrite()})
     * a při aktivaci. Když to jednou selže, **nic to nezopakuje** — na rozdíl od potvrzení plateb,
     * která má cron ({@see \OswisOrg\OswisCalendarBundle\Service\Participant\ParticipantPaymentService::sendPendingConfirmations()}).
     * Takový člověk pak nemá pokyny k platbě a nikdo se to nedozví; přesně tak zapadla přihláška
     * #3246 (paměť `project_summary_mail_lost_2026-07-29`).
     *
     * Ověřeno na produkci 17. 8. 2026: **7 z 532** letošních aktivních přihlášek nemá doručené
     * shrnutí. Většina je z doby před opravou `userConfirmedAt` (commit 7712582, v0.2.66),
     * ale bez tohohle výpisu by na ně nikdo nepřišel.
     *
     * Důkaz doručení je sloupec `sent`, ne existence řádku mailu — mail se může založit a
     * neodejít (paměť `reference_missing_serialization_group_silent_200` má tentýž motiv:
     * „vypadá to hotově" ≠ „stalo se to").
     *
     * @return list<array{id: int, name: string, registered: ?\DateTimeInterface}> od nejnovější
     */
    public function findMissingSummary(Event $parentEvent, int $limit = 20): array
    {
        $rows = $this->createQueryBuilder('p')
            ->select('p.id AS id, c.name AS name, p.createdAt AS registered')
            ->innerJoin('p.event', 'e')
            ->innerJoin('p.participantContacts', 'pc')
            ->innerJoin('pc.contact', 'c')
            ->where('e.superEvent = :parent')
            ->andWhere('p.deletedAt IS NULL')
            ->andWhere(
                'NOT EXISTS ('
                .'SELECT 1 FROM '.ParticipantMail::class.' m'
                .' WHERE m.participant = p AND m.type = :summaryType AND m.sent IS NOT NULL)',
            )
            ->setParameter('parent', $parentEvent)
            ->setParameter('summaryType', ParticipantMail::TYPE_SUMMARY)
            ->orderBy('p.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();

        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['id']) || !is_numeric($row['id'])) {
                continue;
            }
            $registered = $row['registered'] ?? null;
            $out[] = [
                'id'         => (int) $row['id'],
                'name'       => is_string($row['name'] ?? null) ? $row['name'] : '',
                'registered' => $registered instanceof \DateTimeInterface ? $registered : null,
            ];
        }

        return $out;
    }

    /**
     * Detail duplicitních přihlášek pro srovnávací obrazovku — ke KAŽDÉ přihlášce to, co na ní visí.
     *
     * ⚠️ **Duplicita NENÍ automaticky chyba.** Ověřeno na datech 19. 8. 2026: ze tří případů byly
     * dva omyl (dvakrát TÝŽ turnus, u jednoho z nich nic zaplaceno), ale třetí byla legitimní —
     * člověk jel oba turnusy a poslal na ně DVĚ různé platby (různá externí id i data).
     * Obrazovka proto nesmí nic navrhovat jako „správné", jen ukázat fakta a nechat rozhodnout tým.
     *
     * Vrací se úmyslně i počet plateb a částka: podle nich se pozná, kterou přihlášku nelze
     * jen tak zrušit, aniž by se nejdřív přesunuly peníze.
     *
     * `klic` určuje, do které skupiny řádek patří — nově to nemusí být jen shodný kontakt,
     * ale i shodné telefonní číslo pod různými kontakty; `duvod` je pak vysvětlení pro obrazovku.
     *
     * @return list<array{klic: string, duvod: ?string, participantId: int, contactId: int, name: string,
     *                    eventName: string, createdAt: ?\DateTimeInterface, payments: int, paid: float,
     *                    notes: int}>
     */
    public function findDuplicateRegistrationDetails(Event $parentEvent, int $limit = 60): array
    {
        $contactIds = array_column($this->findDuplicateRegistrations($parentEvent, $limit), 'contactId');
        if ([] === $contactIds) {
            return [];
        }
        /** @var list<array{participantId: int|string, contactId: int|string, name: ?string,
         *                 eventName: ?string, createdAt: ?\DateTimeInterface}> $rows */
        $rows = $this->createQueryBuilder('p')
            ->select(
                'p.id AS participantId, IDENTITY(pc.contact) AS contactId, c.name AS name,'
                .' e.name AS eventName, p.createdAt AS createdAt'
            )
            ->innerJoin('p.event', 'e')
            ->innerJoin('p.participantContacts', 'pc')
            ->innerJoin('pc.contact', 'c')
            ->where('e.superEvent = :parent')
            ->andWhere('p.deletedAt IS NULL')
            ->andWhere('pc.contact IN (:contacts)')
            ->orderBy('c.name', 'ASC')
            ->addOrderBy('p.id', 'ASC')
            ->setParameter('parent', $parentEvent)
            ->setParameter('contacts', $contactIds)
            ->getQuery()
            ->getArrayResult();

        $out = [];
        $podleKontaktu = [];
        foreach ($rows as $row) {
            $participantId = (int) $row['participantId'];
            $contactId = (int) $row['contactId'];
            $souhrn = $this->souhrnPrihlasky($participantId);
            $podleKontaktu['kontakt-'.$contactId][] = $participantId;
            $out[] = [
                'klic'          => 'kontakt-'.$contactId,
                'duvod'         => null,
                'participantId' => $participantId,
                'contactId'     => $contactId,
                'name'          => is_string($row['name'] ?? null) ? $row['name'] : '',
                'eventName'     => is_string($row['eventName'] ?? null) ? $row['eventName'] : '',
                'createdAt'     => $row['createdAt'] ?? null,
                'payments'      => $souhrn['payments'],
                'paid'          => $souhrn['paid'],
                'notes'         => $souhrn['notes'],
            ];
        }

        return array_merge($out, $this->duplicityPodleTelefonu($parentEvent, $podleKontaktu));
    }

    /**
     * Druhá cesta k duplicitě: stejné telefonní číslo pod RŮZNÝMI kontakty.
     *
     * Seskupení podle kontaktu (výše) najde jen člověka, který se hlásil dvakrát pod týmž
     * kontaktem. Kdo se ale podruhé přihlásí s jinou e-mailovou adresou — typicky proto, že
     * v té první měl překlep a nedorazil mu ověřovací e-mail — založí si kontakt i účet nový
     * a první cesta ho nevidí. Přesně to se stalo 24. 8. 2026: dvě živé přihlášky (#3846
     * `…@gmal.com` a #3848 `…@gmail.com`) se **stejným telefonem 735511004**, a obrazovka
     * duplicit hlásila „Nic k řešení".
     *
     * Telefon je přitom **variabilní symbol** platby, takže dvě živé přihlášky se stejným
     * číslem znamenají, že příchozí platbu nelze jednoznačně přiřadit.
     *
     * ⚠️ Shoda JMÉNA se tu záměrně nepoužívá — ze čtyř „duplicit" 21. 8. 2026 byly dvě
     * jmenovkyně a mazat podle jména by znamenalo smazat zaplacenou přihlášku cizího člověka.
     *
     * @param  array<string, list<int>>  $jizNalezene skupiny z prvního průchodu (klíč => id přihlášek)
     *
     * @return list<array{klic: string, duvod: ?string, participantId: int, contactId: int, name: string,
     *                    eventName: string, createdAt: ?\DateTimeInterface, payments: int, paid: float, notes: int}>
     */
    private function duplicityPodleTelefonu(Event $parentEvent, array $jizNalezene): array
    {
        /** @var list<array{participantId: int|string, contactId: int|string, name: ?string,
         *                 eventName: ?string, createdAt: ?\DateTimeInterface, telefon: ?string}> $radky */
        $radky = $this->createQueryBuilder('p')
            ->select(
                'p.id AS participantId, IDENTITY(pc.contact) AS contactId, c.name AS name,'
                .' e.name AS eventName, p.createdAt AS createdAt, d.content AS telefon'
            )
            ->innerJoin('p.event', 'e')
            ->innerJoin('p.participantContacts', 'pc')
            ->innerJoin('pc.contact', 'c')
            ->innerJoin('c.details', 'd')
            ->innerJoin('d.detailCategory', 'cat')
            ->where('e.superEvent = :parent')
            ->andWhere('p.deletedAt IS NULL')
            ->andWhere('cat.type = :typ')
            ->setParameter('parent', $parentEvent)
            ->setParameter('typ', ContactDetailCategory::TYPE_PHONE)
            ->getQuery()
            ->getArrayResult();

        // Seskupení až v PHP: normalizace čísla (mezery, předvolba) se v DQL dělá špatně
        // a letošních přihlášek jsou stovky, ne statisíce.
        $skupiny = [];
        foreach ($radky as $radek) {
            $cislo = $this->normalizujTelefon(is_string($radek['telefon'] ?? null) ? $radek['telefon'] : '');
            if ('' === $cislo) {
                continue;
            }
            $skupiny[$cislo][(int) $radek['participantId']] = $radek;
        }

        $out = [];
        foreach ($skupiny as $cislo => $prihlasky) {
            if (count($prihlasky) < 2) {
                continue;
            }
            // Víc přihlášek pod JEDNÍM kontaktem už našel první průchod — tady nás zajímá
            // jen případ, kdy je za stejným číslem víc různých kontaktů.
            $kontakty = array_unique(array_map(static fn (array $r): int => (int) $r['contactId'], $prihlasky));
            if (count($kontakty) < 2) {
                continue;
            }
            // A pokud tu tutéž sestavu přihlášek už ukazuje skupina podle kontaktu, neopakovat.
            $ids = array_keys($prihlasky);
            sort($ids);
            foreach ($jizNalezene as $nalezene) {
                $porovnani = $nalezene;
                sort($porovnani);
                if ($porovnani === $ids) {
                    continue 2;
                }
            }

            foreach ($prihlasky as $participantId => $radek) {
                $souhrn = $this->souhrnPrihlasky($participantId);
                $out[] = [
                    'klic'          => 'telefon-'.$cislo,
                    'duvod'         => 'stejné telefonní číslo '.$cislo.' pod různými kontakty'
                                       .' — telefon je variabilní symbol, takže platbu nelze jednoznačně přiřadit',
                    'participantId' => $participantId,
                    'contactId'     => (int) $radek['contactId'],
                    'name'          => is_string($radek['name'] ?? null) ? $radek['name'] : '',
                    'eventName'     => is_string($radek['eventName'] ?? null) ? $radek['eventName'] : '',
                    'createdAt'     => $radek['createdAt'] ?? null,
                    'payments'      => $souhrn['payments'],
                    'paid'          => $souhrn['paid'],
                    'notes'         => $souhrn['notes'],
                ];
            }
        }

        return $out;
    }

    /** Jen číslice, a z nich posledních devět — tím zmizí mezery i předvolba (+420 / 00420). */
    private function normalizujTelefon(string $telefon): string
    {
        $cislice = preg_replace('/\D+/', '', $telefon) ?? '';

        return strlen($cislice) >= 9 ? substr($cislice, -9) : '';
    }

    /**
     * Počty a částka k jedné přihlášce. Schválně syrovým SQL: hydratace plného grafu účastníka
     * mutuje `getName()` na L2-cachovaných entitách (viz `WebAdminParticipantsController::setGender`)
     * a tady jde jen o tři čísla.
     *
     * @return array{payments: int, paid: float, notes: int}
     */
    private function souhrnPrihlasky(int $participantId): array
    {
        $spojeni = $this->getEntityManager()->getConnection();
        /** @var array{plateb: int|string, castka: string|float|null}|false $platby */
        $platby = $spojeni->fetchAssociative(
            'SELECT COUNT(*) AS plateb, COALESCE(SUM(numeric_value), 0) AS castka'
            .' FROM calendar_participant_payment WHERE participant_id = :id',
            ['id' => $participantId],
        );
        $poznamek = $spojeni->fetchOne(
            'SELECT COUNT(*) FROM calendar_participant_note WHERE participant_id = :id AND deleted_at IS NULL',
            ['id' => $participantId],
        );

        return [
            'payments' => false === $platby ? 0 : (int) $platby['plateb'],
            'paid'     => false === $platby ? 0.0 : (float) $platby['castka'],
            'notes'    => is_numeric($poznamek) ? (int) $poznamek : 0,
        ];
    }

    /**
     * Lidé, kteří mají pod default akcí VÍC NEŽ JEDNU živou přihlášku.
     *
     * PROČ: server-side pojistka proti dvojímu odeslání formuláře je záměrně **60sekundová** — řeší
     * dvojí POST z iOS Safari, ne to, že se člověk o dva dny později přihlásí znovu (typicky v domnění,
     * že mu první přihláška nedošla). Takové duplicity nikdo neuvidí až do příjezdového stolu: drží
     * místo v kapacitě, chodí jim dvojí pošta a u stolu se pak řeší, který záznam je ten pravý.
     * Ověřeno na produkci 2026-08-15 — tři případy, žádný z nich systém nezachytil.
     *
     * Stejně LEVNÉ jako ostatní dashboard staty: jeden group-by COUNT nad `id`, žádná hydratace
     * ({@see AdminDashboardExtension}).
     *
     * @return list<array{contactId: int, name: string, count: int}> seřazeno od nejvíc duplicitních
     */
    public function findDuplicateRegistrations(Event $parentEvent, int $limit = 20): array
    {
        $rows = $this->createQueryBuilder('p')
            ->select('IDENTITY(pc.contact) AS contactId, c.name AS name, COUNT(p.id) AS cnt')
            ->innerJoin('p.event', 'e')
            ->innerJoin('p.participantContacts', 'pc')
            ->innerJoin('pc.contact', 'c')
            ->where('e.superEvent = :parent')
            ->andWhere('p.deletedAt IS NULL')
            ->groupBy('pc.contact')
            ->addGroupBy('c.name')
            ->having('COUNT(p.id) > 1')
            ->orderBy('cnt', 'DESC')
            ->setParameter('parent', $parentEvent)
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();

        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['contactId'], $row['cnt'])
                || !is_numeric($row['contactId']) || !is_numeric($row['cnt'])) {
                continue;
            }
            $out[] = [
                'contactId' => (int) $row['contactId'],
                'name'      => is_string($row['name'] ?? null) ? $row['name'] : '',
                'count'     => (int) $row['cnt'],
            ];
        }

        return $out;
    }

    /**
     * IDs of participants of $event (+ recursive sub-events to $recursiveDepth) that have NOT yet
     * been sent a mail of $type, capped at $limit. SQL-side dedup (correlated NOT EXISTS) + a true
     * LIMIT on an id-only query (no fetch-joins) → no whole-cohort hydration and no lazy-collection
     * N+1 (the bug in the old load-all → PHP filter(hasEMailOfType) → slice path). {@see ParticipantService::sendAutoMails}.
     *
     * $afterId is a pagination cursor (only ids > $afterId, they are returned ASC): callers whose
     * PHP-side checks (group filter expression, isActive) reject candidates MUST page with it —
     * permanently-rejected ids never become "mailed", so without the cursor they would clog the
     * LIMIT window forever and recipients beyond it would never be reached.
     *
     * @return list<int>
     */
    public function findUnmailedParticipantIds(
        Event $event,
        string $type,
        int $limit,
        int $recursiveDepth = 4,
        bool $includeDeleted = false,
        int $afterId = 0,
    ): array {
        $qb = $this->createQueryBuilder('p')->select('p.id');
        if ($afterId > 0) {
            $qb->andWhere('p.id > :afterId')->setParameter('afterId', $afterId);
        }
        // Recursive event scope (to-one superEvent joins → no row multiplication; not selected).
        $qb->leftJoin('p.event', 'e0');
        $eventOr = 'p.event = :ev';
        for ($i = 0; $i < max(0, $recursiveDepth); $i++) {
            $j = $i + 1;
            $qb->leftJoin("e$i.superEvent", "e$j");
            $eventOr .= " OR e$j = :ev";
        }
        $qb->andWhere($eventOr)->setParameter('ev', $event->getId());
        if (!$includeDeleted) {
            $qb->andWhere('p.deletedAt IS NULL');
        }
        // Already-sent dedup, SQL-side (failed rows with sent IS NULL are NOT excluded → retried).
        $qb->andWhere(
            'NOT EXISTS (SELECT 1 FROM '.ParticipantMail::class.' pm WHERE pm.participant = p AND pm.type = :mailType AND pm.sent IS NOT NULL)',
        )->setParameter('mailType', $type);
        $qb->orderBy('p.id', 'ASC')->setMaxResults(max(1, $limit));

        $ids = [];
        foreach ($qb->getQuery()->getScalarResult() as $row) {
            if (is_array($row) && isset($row['id']) && is_numeric($row['id'])) {
                $ids[] = (int) $row['id'];
            }
        }

        return $ids;
    }

    /**
     * The $limit most recently created active, event-bound participants, with their to-one
     * associations (contact / event / contactAppUser) fetch-joined so the preview sample-recipient
     * picker can show a name + event without lazy N+1. To-one joins + LIMIT is pagination-safe (no row
     * multiplication), unlike fetch-joining the to-many collections. Filters keep the picker on real
     * recipients: a Person (recipient-facing mail goes to people, not the organizer Organization)
     * registered to an event. {@see MailPreviewService::pickSampleParticipant}.
     *
     * @return list<Participant>
     */
    public function findSampleParticipants(int $limit = 30): array
    {
        $queryBuilder = $this->createQueryBuilder('participant')
            ->select('participant, contact, event, contactAppUser')
            ->leftJoin('participant.contact', 'contact')
            ->leftJoin('contact.appUser', 'contactAppUser')
            ->leftJoin('participant.event', 'event')
            ->andWhere('participant.deletedAt IS NULL')
            ->andWhere('participant.event IS NOT NULL')
            ->andWhere('contact INSTANCE OF '.Person::class)
            ->orderBy('participant.id', 'DESC')
            ->setMaxResults(max(1, $limit));
        $result = $queryBuilder->getQuery()->getResult();
        $participants = [];
        foreach (is_array($result) ? $result : [] as $participant) {
            if ($participant instanceof Participant) {
                $participants[] = $participant;
            }
        }

        return $participants;
    }

    /**
     * Load the given participants (by id) with their to-one associations (contact / event / appUser)
     * fetch-joined — for the bulk-mail composer's recipient list + per-recipient preview. To-one joins
     * + IN(:ids) is bounded (no row multiplication, no collection walking). Ordered by id for a stable
     * list. {@see WebAdminBulkMailController::compose}.
     *
     * @param list<int> $ids
     *
     * @return list<Participant>
     */
    public function findByIds(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }
        $queryBuilder = $this->createQueryBuilder('participant')
            ->select('participant, contact, event, contactAppUser')
            ->leftJoin('participant.contact', 'contact')
            ->leftJoin('contact.appUser', 'contactAppUser')
            ->leftJoin('participant.event', 'event')
            ->andWhere('participant.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->orderBy('participant.id', 'ASC');
        $result = $queryBuilder->getQuery()->getResult();
        $participants = [];
        foreach (is_array($result) ? $result : [] as $participant) {
            if ($participant instanceof Participant) {
                $participants[] = $participant;
            }
        }

        return $participants;
    }

    private function setSuperEventQuery(QueryBuilder $queryBuilder, array $opts = []): void
    {
        if (!empty($opts[self::CRITERIA_EVENT]) && $opts[self::CRITERIA_EVENT] instanceof Event) {
            $eventQuery = ' participant.event = :event_id ';
            $queryBuilder->leftJoin('participant.event', 'e0');
            $recursiveDepth = !empty($opts[self::CRITERIA_EVENT_RECURSIVE_DEPTH])
                ? $opts[self::CRITERIA_EVENT_RECURSIVE_DEPTH] : 0;
            for ($i = 0; $i < $recursiveDepth; $i++) {
                $j = $i + 1;
                $queryBuilder->leftJoin("e$i.superEvent", "e$j");
                $eventQuery .= " OR e$j = :event_id ";
            }
            $queryBuilder->andWhere($eventQuery)->setParameter('event_id', $opts[self::CRITERIA_EVENT]->getId());
        }
    }

    private function setIdQuery(QueryBuilder $queryBuilder, array $opts = []): void
    {
        if (!empty($opts[self::CRITERIA_ID])) {
            $queryBuilder->andWhere(' participant.id = :id ')->setParameter('id', $opts[self::CRITERIA_ID]);
        }
    }

    private function setRangeQuery(QueryBuilder $queryBuilder, array $opts = []): void
    {
        if (!empty($opts[self::CRITERIA_OFFER]) && $opts[self::CRITERIA_OFFER] instanceof RegistrationOffer) {
            $queryBuilder->andWhere('participant.offer = :offer_id');
            $queryBuilder->setParameter('offer_id', $opts[self::CRITERIA_OFFER]->getId());
        }
    }

    private function setParticipantCategoryQuery(QueryBuilder $queryBuilder, array $opts = []): void
    {
        if (!empty($opts[self::CRITERIA_PARTICIPANT_CATEGORY])
            && $opts[self::CRITERIA_PARTICIPANT_CATEGORY] instanceof ParticipantCategory) {
            $queryBuilder->andWhere('participant.participantCategory = :type_id');
            $queryBuilder->setParameter('type_id', $opts[self::CRITERIA_PARTICIPANT_CATEGORY]->getId());
        }
    }

    private function setParticipantTypeQuery(QueryBuilder $queryBuilder, array $opts = []): void
    {
        if (!empty($opts[self::CRITERIA_PARTICIPANT_TYPE]) && is_string($opts[self::CRITERIA_PARTICIPANT_TYPE])) {
            $queryBuilder->leftJoin('participant.participantCategory', 'participant_category_for_string');
            $queryBuilder->andWhere('participant_category_for_string.type = :type_string');
            $queryBuilder->setParameter('type_string', $opts[self::CRITERIA_PARTICIPANT_TYPE]);
        }
    }

    private function setIncludeDeletedQuery(QueryBuilder $queryBuilder, array $opts = []): void
    {
        if (empty($opts[self::CRITERIA_INCLUDE_DELETED])) {
            $queryBuilder->andWhere('participant.deletedAt IS NULL');
        }
    }

    private function setContactQuery(QueryBuilder $queryBuilder, array $opts = []): void
    {
        if (!empty($opts[self::CRITERIA_CONTACT]) && $opts[self::CRITERIA_CONTACT] instanceof AbstractContact) {
            $queryBuilder->andWhere('participant.contact = :contact_id')->setParameter(
                'contact_id',
                $opts[self::CRITERIA_CONTACT]->getId()
            );
        }
    }

    private function setAppUserQuery(QueryBuilder $queryBuilder, array $opts = []): void
    {
        if (!empty($opts[self::CRITERIA_APP_USER]) && $opts[self::CRITERIA_APP_USER] instanceof AppUser) {
            $queryBuilder->andWhere('contact.appUser = :app_user_id');
            $queryBuilder->setParameter('app_user_id', $opts[self::CRITERIA_APP_USER]->getId());
        }
    }

    private function setVSQuery(QueryBuilder $queryBuilder, array $opts = []): void
    {
        if (!empty($opts[self::CRITERIA_VARIABLE_SYMBOL])) {
            $queryBuilder->andWhere(' participant.variableSymbol = :variableSymbol ');
            $queryBuilder->setParameter('variableSymbol', $opts[self::CRITERIA_VARIABLE_SYMBOL]);
        }
    }

    private function setLimit(QueryBuilder $queryBuilder, ?int $limit = null, ?int $offset = null): void
    {
        if (null !== $limit) {
            $queryBuilder->setMaxResults($limit);
        }
        if (null !== $offset) {
            $queryBuilder->setFirstResult($offset);
        }
    }

    private function setOrderBy(QueryBuilder $queryBuilder, bool $priority = true, bool $name = true): void
    {
        if ($priority) {
            $queryBuilder->addOrderBy('participant.priority', 'DESC');
        }
        if ($name) {
            $queryBuilder->leftJoin('participant.contact', 'contact_0');
            $queryBuilder->addOrderBy('contact_0.sortableName', 'ASC');
        }
        $queryBuilder->addOrderBy('participant.id', 'ASC');
    }

    public function getParticipant(?array $opts = [], ?bool $includeNotActivated = true): ?Participant
    {
        try {
            $participant = $this->getParticipantsQueryBuilder($opts ?? [], 1, 0)->getQuery()->getOneOrNullResult();
        } catch (NonUniqueResultException) {
            return null;
        }
        if (!($participant instanceof Participant)
            || (!$includeNotActivated
                && !$participant->hasActivatedContactUser())) {
            return null;
        }

        return $participant;
    }
}

