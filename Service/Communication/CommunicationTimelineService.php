<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Service\Communication;

use Doctrine\ORM\EntityManagerInterface;
use OswisOrg\OswisAddressBookBundle\Entity\AbstractClass\AbstractContact;
use OswisOrg\OswisCoreBundle\Entity\AppUserMail\AppUserEditMail;
use OswisOrg\OswisCoreBundle\Entity\AppUserMail\AppUserMail;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Entity\ParticipantMail\ParticipantMail;
use OswisOrg\OswisCalendarBundle\Repository\Imap\ParticipantIncomingMailRepository;
use OswisOrg\OswisCalendarBundle\Repository\Participant\ParticipantMailRepository;
use OswisOrg\OswisCalendarBundle\Repository\ParticipantNote\ParticipantManualNoteRepository;
use OswisOrg\OswisCoreBundle\Interfaces\Communication\CommunicationEntryInterface;

/**
 * Aggregate timeline entries from all communication-channel repositories.
 *
 * Sort: occurredAt DESC (most recent first). Filters internal entries when
 * caller asks for public-only view (participant portal).
 *
 * Spec: docs/superpowers/specs/2026-05-24-communication-history-design.md §3.
 */
final readonly class CommunicationTimelineService
{
    public function __construct(
        private ParticipantMailRepository $mailRepository,
        private ParticipantManualNoteRepository $manualNoteRepository,
        private ParticipantIncomingMailRepository $incomingMailRepository,
        private EntityManagerInterface $em,
    ) {
    }

    /**
     * @return list<CommunicationEntryInterface>
     */
    public function forParticipant(Participant $participant, bool $includeInternal = true): array
    {
        $entries = array_merge(
            $this->fetchMails($participant),
            $this->fetchAccountMails($participant),
            $this->manualNoteRepository->findByParticipant($participant),
            $this->incomingMailRepository->findByParticipant($participant),
        );

        if (!$includeInternal) {
            $entries = array_filter(
                $entries,
                static fn (CommunicationEntryInterface $entry): bool => $entry->isPublicForParticipant(),
            );
        }

        usort(
            $entries,
            static function (CommunicationEntryInterface $a, CommunicationEntryInterface $b): int {
                $aTime = $a->getOccurredAt()?->getTimestamp() ?? 0;
                $bTime = $b->getOccurredAt()?->getTimestamp() ?? 0;

                return $bTime <=> $aTime;
            },
        );

        return $entries;
    }

    /**
     * @return list<CommunicationEntryInterface>
     */
    private function fetchMails(Participant $participant): array
    {
        // Bez podmínky na `sent`: neúspěšné pokusy a zprávy v nejistém stavu patří do historie
        // stejně jako doručené — tým se jinak nedozví, že e-mail nedorazil (a ptá se ho účastník).
        $qb = $this->mailRepository->createQueryBuilder('mail')
            ->andWhere('mail.participant = :participant')
            ->setParameter('participant', $participant)
            ->orderBy('mail.sent', 'DESC')
            ->addOrderBy('mail.id', 'DESC');

        $result = $qb->getQuery()->getResult();
        if (!is_array($result)) {
            return [];
        }

        $entries = [];
        foreach ($result as $row) {
            if ($row instanceof ParticipantMail) {
                $entries[] = $row;
            }
        }

        return $entries;
    }

    /**
     * E-maily k ÚČTU kontaktních osob přihlášky (aktivace účtu, zapomenuté heslo, změna údajů,
     * pokračování v přihlášce).
     *
     * Do historie patří: když se člověk ptá „nepřišel mi e-mail", tým dosud neviděl právě tu
     * půlku pošty, které se to nejčastěji týká. Účty jsou v core, přihlášky v kalendáři — směr
     * závislostí sedí (kalendář smí do core).
     *
     * @return list<CommunicationEntryInterface>
     */
    private function fetchAccountMails(Participant $participant): array
    {
        $appUserIds = [];
        foreach ($participant->getContactPersons(false) as $contactPerson) {
            $appUserId = $contactPerson instanceof AbstractContact ? $contactPerson->getAppUser()?->getId() : null;
            if (null !== $appUserId) {
                $appUserIds[] = $appUserId;
            }
        }
        if ([] === $appUserIds) {
            return [];
        }
        $entries = [];
        foreach ([AppUserMail::class, AppUserEditMail::class] as $class) {
            $result = $this->em->createQueryBuilder()
                ->select('mail')
                ->from($class, 'mail')
                ->andWhere('mail.appUser IN (:appUsers)')
                ->setParameter('appUsers', $appUserIds)
                ->orderBy('mail.id', 'DESC')
                ->getQuery()
                ->getResult();
            foreach (is_array($result) ? $result : [] as $row) {
                if ($row instanceof CommunicationEntryInterface) {
                    $entries[] = $row;
                }
            }
        }

        return $entries;
    }
}
