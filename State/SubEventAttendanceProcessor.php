<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Doctrine\ORM\EntityManagerInterface;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Entity\Participant\SubEventAttendance;
use OswisOrg\OswisCalendarBundle\Repository\Participant\ParticipantRepository;
use OswisOrg\OswisCalendarBundle\Repository\Participant\SubEventAttendanceRepository;
use OswisOrg\OswisCalendarBundle\Service\Participant\ParticipantService;
use OswisOrg\OswisCoreBundle\Entity\AppUser\AppUser;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Handles POST /api/sub-event-attendances:
 * - Resolves the current user's Participant.
 * - Enforces capacity check inside a transaction (HTTP 409 if full).
 * - Persists with status = REGISTERED.
 *
 * Spec: docs/superpowers/specs/2026-05-22-S2-S3-S4-calendar-ux-2.0-design.md S3 step 4.1.2
 *
 * @implements ProcessorInterface<SubEventAttendance, SubEventAttendance>
 */
final class SubEventAttendanceProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
        private readonly ParticipantService $participantService,
        private readonly SubEventAttendanceRepository $attendanceRepository,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): SubEventAttendance
    {
        $user = $this->security->getUser();
        if (!$user instanceof AppUser) {
            throw new AccessDeniedHttpException('Vyžaduje přihlášení.');
        }

        $participants = $this->participantService->getParticipants(
            [ParticipantRepository::CRITERIA_APP_USER => $user]
        );
        $participant = null;
        foreach ($participants as $p) {
            $participant = $p;
            break;
        }
        if (!$participant instanceof Participant) {
            throw new AccessDeniedHttpException('Účet nemá aktivní registraci na ročník.');
        }

        return $this->em->wrapInTransaction(function () use ($data, $participant): SubEventAttendance {
            $event = $data->getEvent();
            $full = $event->getFullCapacity();
            if (null !== $full) {
                $count = $this->attendanceRepository->countActiveByEvent($event);
                if ($count >= $full) {
                    throw new ConflictHttpException('Aktivita je plná.');
                }
            }
            $existing = $this->attendanceRepository->findActiveForParticipantAndEvent($participant, $event);
            if (null !== $existing) {
                return $existing;
            }
            $attendance = new SubEventAttendance($participant, $event);
            $this->em->persist($attendance);
            $this->em->flush();

            return $attendance;
        });
    }
}
