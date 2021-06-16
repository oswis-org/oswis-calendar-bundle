<?php
/**
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantToken;
use OswisOrg\OswisCalendarBundle\Repository\ParticipantTokenRepository;
use OswisOrg\OswisCoreBundle\Entity\AppUser\AppUser;
use OswisOrg\OswisCoreBundle\Exceptions\InvalidTypeException;
use Psr\Log\LoggerInterface;

class ParticipantTokenService
{
    public function __construct(
        protected EntityManagerInterface $em,
        protected LoggerInterface $logger,
        protected ParticipantTokenRepository $participantTokenRepository,
    ) {
    }

    /**
     * @param  Participant  $participant
     * @param  AppUser  $appUser
     * @param  string|null  $type
     * @param  bool|null  $multipleUseAllowed
     * @param  int|null  $validHours
     *
     * @return ParticipantToken
     * @throws InvalidTypeException
     */
    public function create(
        Participant $participant,
        AppUser $appUser,
        ?string $type = null,
        ?bool $multipleUseAllowed = null,
        ?int $validHours = null
    ): ParticipantToken {
        try {
            // TODO: Complete refactoring needed. Or not???
            $participantToken = new ParticipantToken($participant, $appUser, $type, $multipleUseAllowed ?? false, $validHours);
            $this->em->persist($participantToken);
            $this->em->flush();
            $tokenId = $participantToken->getId();
            $participantId = $participant->getId();
            $this->logger->info("Created new token ($tokenId) of type '$type' for user '$participantId'.");

            return $participantToken;
        } catch (InvalidTypeException $exception) {
            $this->logger->error($exception->getMessage());
            throw $exception;
        }
    }

    public function findToken(?string $token, ?int $participantId): ?ParticipantToken
    {
        return $this->getRepository()->findByToken($token, $participantId);
    }

    public function getRepository(): ParticipantTokenRepository
    {
        return $this->participantTokenRepository;
    }
}
