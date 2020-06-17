<?php
/**
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantToken;
use OswisOrg\OswisCalendarBundle\Repository\ParticipantTokenRepository;
use OswisOrg\OswisCoreBundle\Exceptions\InvalidTypeException;
use OswisOrg\OswisCoreBundle\Exceptions\OswisException;
use Psr\Log\LoggerInterface;

class ParticipantTokenService
{
    protected EntityManagerInterface $em;

    protected LoggerInterface $logger;

    public function __construct(EntityManagerInterface $em, LoggerInterface $logger)
    {
        $this->em = $em;
        $this->logger = $logger;
    }

    /**
     * @param Participant $participant
     * @param string|null $type
     * @param bool|null   $multipleUseAllowed
     * @param int|null    $validHours
     *
     * @return ParticipantToken
     * @throws InvalidTypeException
     */
    public function create(Participant $participant, ?string $type = null, ?bool $multipleUseAllowed = null, ?int $validHours = null): ParticipantToken
    {
        try {
            // TODO: Complete refactoring needed.
            $participantToken = new ParticipantToken($participant, $participant->getEmail(), $type, $multipleUseAllowed, $validHours);
            $this->em->persist($participantToken);
            $this->em->flush();
            $tokenId = $participantToken->getId();
            $appUserId = $participant->getId();
            $this->logger->info("Created new token ($tokenId) of type '$type' for user '$appUserId'.");

            return $participantToken;
        } catch (InvalidTypeException $exception) {
            $this->logger->error($exception->getMessage());
            throw $exception;
        }
    }

    /**
     * @return ParticipantTokenRepository
     * @throws OswisException
     */
    public function getRepository(): ParticipantTokenRepository
    {
        $repository = $this->em->getRepository(ParticipantToken::class);
        if (!($repository instanceof ParticipantTokenRepository)) {
            throw new OswisException('Nepodařilo se získat ParticipantTokenRepository.');
        }

        return $repository;
    }
}
