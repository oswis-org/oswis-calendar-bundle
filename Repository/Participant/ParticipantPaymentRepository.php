<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Repository\Participant;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use InvalidArgumentException;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantPayment;

/** @extends ServiceEntityRepository<ParticipantPayment> */
class ParticipantPaymentRepository extends ServiceEntityRepository
{
    public const string FILTER_ALL        = 'all';
    public const string FILTER_ORPHANED   = 'orphaned';
    public const string FILTER_WITH_ERROR = 'with-error';
    public const string FILTER_ASSIGNED   = 'assigned';

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ParticipantPayment::class);
    }

    /**
     * @return list<ParticipantPayment>
     */
    /**
     * Kolik PŘÍCHOZÍCH plateb čeká na přiřazení k přihlášce.
     *
     * Záměrně jen kladné částky: mezi nepřiřazenými je spousta odchozích řádků z bankovního
     * výpisu (poplatky, vratky), které k žádné přihlášce nepatří a patřit nemají — surový počet
     * „nepřiřazených" je proto šum. Kladná nepřiřazená částka naopak znamená, že někdo zaplatil
     * a nemá to připsané: na produkci takhle 8 dní ležela záloha 1690 Kč (nález 2026-08-15).
     */
    public function countUnassignedIncoming(): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->andWhere('p.participant IS NULL')
            ->andWhere('p.numericValue > 0')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Existuje už platba s tímhle bankovním `externalId`? Čte se ZÁMĚRNĚ MIMO CACHE.
     *
     * ⚠️ **Tohle je oprava incidentu 2026-08-16** (`docs/OSWIS_1_INCIDENT_PAYMENT_DUPLICATES_2026-08-16.md`):
     * import plateb #240 vložil každý řádek DVAKRÁT — 102 fiktivních plateb za 349 735 Kč u 93 lidí.
     * Deduplikace se tehdy ptala přes `findBy(['externalId' => …])`, jenže `ParticipantPayment` má
     * `#[Cache(usage: 'NONSTRICT_READ_WRITE')]`, takže druhý průchod dostal ZASTARALOU odpověď
     * z druhoúrovňové cache a řádky, zapsané a flushnuté o vteřinu dřív, prostě „neviděl".
     *
     * Proto se tu cache pro tenhle dotaz vypíná (`setCacheable(false)`) a vrací se holé `id`
     * skalárem — žádná hydratace entity, která by se do cache zase vrátila.
     *
     * ⚠️ Sama o sobě tahle metoda duplicitu NEZARUČÍ. Jediná spolehlivá vrstva je unikátní index
     * v databázi; ten přidává migrace `Version20260816…`. Tohle je vrstva druhá, aby import
     * neskončil výjimkou z databáze, ale tiše a správně přeskočil.
     */
    public function findIdByExternalId(string $externalId): ?int
    {
        if ('' === trim($externalId)) {
            return null;
        }
        $id = $this->createQueryBuilder('p')
            ->select('p.id')
            ->andWhere('p.externalId = :externalId')->setParameter('externalId', $externalId)
            ->setMaxResults(1)
            ->getQuery()
            ->setCacheable(false)
            ->getOneOrNullResult();

        return is_array($id) && isset($id['id']) && is_numeric($id['id']) ? (int) $id['id'] : null;
    }

    public function findFiltered(string $filter, int $limit = 500): array
    {
        // Participant has fetch=EAGER on offer/event/participantCategory and AbstractContact
        // has fetch=EAGER on appUser — without join-fetching them here, hydrating each row's
        // participant fires 4 extra queries per distinct participant (the payments overview
        // measured ~1646 queries for 500 rows). These are all to-one joins, so they collapse
        // into the single result query with no row multiplication. EAGER stays intentional;
        // we just load it up-front instead of lazily (per the page-scoped optimisation rule).
        $qb = $this->createQueryBuilder('p')
            ->leftJoin('p.participant', 'participant')->addSelect('participant')
            ->leftJoin('participant.contact', 'contact')->addSelect('contact')
            ->leftJoin('contact.appUser', 'appUser')->addSelect('appUser')
            ->leftJoin('participant.offer', 'offer')->addSelect('offer')
            ->leftJoin('participant.event', 'pEvent')->addSelect('pEvent')
            ->leftJoin('participant.participantCategory', 'pCategory')->addSelect('pCategory')
            ->leftJoin('p.import', 'import')->addSelect('import')
            ->orderBy('p.dateTime', 'DESC')
            ->setMaxResults($limit);

        $this->applyFilter($qb, $filter);

        /** @var list<ParticipantPayment> $result */
        $result = $qb->getQuery()->getResult();

        return $result;
    }

    public function countFiltered(string $filter): int
    {
        $qb = $this->createQueryBuilder('p')->select('COUNT(p.id)');
        $this->applyFilter($qb, $filter);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Platby, které čekají na potvrzovací e-mail — podklad pro odložené odesílání z cronu
     * (import je kvůli 504 už neposílá synchronně, {@see ParticipantPaymentsImportService}).
     *
     * ⚠️ `$notBefore` NENÍ optimalizace, ale POJISTKA. Podmínka „má účastníka a nemá
     * `confirmedByMailAt`" sama o sobě sedí i na **32 historických plateb z let 2020–2025**
     * (ověřeno na produkci 2026-08-12: 10× 2025, 6× 2024, 6× 2023, 8× 2022, 2× starší).
     * Bez stropu na stáří by první běh cronu rozeslal těmto lidem potvrzení k platbám i šest
     * let starým. Ty staré tam zůstaly z různých důvodů (chybějící appUser, spadlá šablona…)
     * a rozhodně se nemají dohánět automaticky.
     *
     * Vrací ID, ne entity: odesílání pak načítá po jednom a průběžně detachuje, aby dávka
     * nedržela celý objektový graf (Participant má EAGER vazby — známý OOM vzorec automailů).
     *
     * @return list<int>
     */
    public function findAwaitingConfirmationIds(\DateTimeInterface $notBefore, int $limit = 100): array
    {
        $rows = $this->createQueryBuilder('p')
            ->select('p.id')
            ->andWhere('p.participant IS NOT NULL')
            ->andWhere('p.confirmedByMailAt IS NULL')
            ->andWhere('p.createdAt >= :notBefore')->setParameter('notBefore', $notBefore)
            ->orderBy('p.id', 'ASC')
            ->setMaxResults(max(1, $limit))
            ->getQuery()->getScalarResult();

        $ids = [];
        foreach ($rows as $row) {
            if (is_array($row) && isset($row['id']) && is_numeric($row['id'])) {
                $ids[] = (int) $row['id'];
            }
        }

        return $ids;
    }

    private function applyFilter(QueryBuilder $qb, string $filter): void
    {
        match ($filter) {
            self::FILTER_ORPHANED   => $qb->andWhere('p.participant IS NULL'),
            self::FILTER_WITH_ERROR => $qb->andWhere("p.errorMessage IS NOT NULL AND p.errorMessage <> ''"),
            self::FILTER_ASSIGNED   => $qb->andWhere('p.participant IS NOT NULL'),
            self::FILTER_ALL        => $qb,
            default                 => throw new InvalidArgumentException("Unknown payment filter '$filter'"),
        };
    }
}
