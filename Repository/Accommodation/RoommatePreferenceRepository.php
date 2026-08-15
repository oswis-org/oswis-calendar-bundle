<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Repository\Accommodation;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use OswisOrg\OswisCalendarBundle\Entity\Accommodation\RoommatePreference;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;

/**
 * @extends ServiceEntityRepository<RoommatePreference>
 */
class RoommatePreferenceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RoommatePreference::class);
    }

    /** @return list<RoommatePreference> */
    public function findByParticipant(Participant $participant): array
    {
        return $this->findBy(['participant' => $participant]);
    }

    /**
     * Preference, které se účastníka týkají Z OBOU STRAN — jak ty, které vyslovil sám, tak ty,
     * ve kterých je uveden jako spolubydlící někoho jiného.
     *
     * Obousměrnost je tu podstatná: požadavek na spolubydlení zadává tým typicky jen u JEDNOHO
     * z dvojice (přišel e-mailem od jednoho z nich). Kdyby se hledalo jen `participant = X`,
     * ubytovací stanice by při přiřazování toho druhého nic nevěděla a dvojici by rozdělila.
     *
     * @return list<RoommatePreference>
     */
    public function findRelatedToParticipant(Participant $participant): array
    {
        /** @var list<RoommatePreference> $result */
        $result = $this->createQueryBuilder('rp')
            ->where('rp.participant = :participant')
            ->orWhere('rp.withParticipant = :participant')
            ->setParameter('participant', $participant)
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Nespárované preference (volný text bez navázaného účastníka) napříč akcí — vstup pro
     * dávkové přepárování. Lidé se hlásí průběžně, takže jméno, které dnes nesedí na nikoho,
     * může být zítra spárovatelné.
     *
     * @return list<RoommatePreference>
     */
    public function findUnmatched(): array
    {
        /** @var list<RoommatePreference> $result */
        $result = $this->createQueryBuilder('rp')
            ->where('rp.withParticipant IS NULL')
            ->andWhere('rp.status != :ok')
            ->setParameter('ok', RoommatePreference::STATUS_OK)
            ->getQuery()
            ->getResult();

        return $result;
    }
}
