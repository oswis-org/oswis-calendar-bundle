<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Service\Communication;

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
    ) {
    }

    /**
     * @return list<CommunicationEntryInterface>
     */
    public function forParticipant(Participant $participant, bool $includeInternal = true): array
    {
        $entries = array_merge(
            $this->fetchMails($participant),
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

        return array_values($entries);
    }

    /**
     * @return list<CommunicationEntryInterface>
     */
    private function fetchMails(Participant $participant): array
    {
        $qb = $this->mailRepository->createQueryBuilder('mail')
            ->andWhere('mail.participant = :participant')
            ->andWhere('mail.sent IS NOT NULL')
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
}
