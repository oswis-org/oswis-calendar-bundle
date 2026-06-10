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
     * IDs of participants of $event (+ recursive sub-events to $recursiveDepth) that have NOT yet
     * been sent a mail of $type, capped at $limit. SQL-side dedup (correlated NOT EXISTS) + a true
     * LIMIT on an id-only query (no fetch-joins) → no whole-cohort hydration and no lazy-collection
     * N+1 (the bug in the old load-all → PHP filter(hasEMailOfType) → slice path). {@see ParticipantService::sendAutoMails}.
     *
     * @return list<int>
     */
    public function findUnmailedParticipantIds(
        Event $event,
        string $type,
        int $limit,
        int $recursiveDepth = 4,
        bool $includeDeleted = false,
    ): array {
        $qb = $this->createQueryBuilder('p')->select('p.id');
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

