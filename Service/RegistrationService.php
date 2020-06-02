<?php
/**
 * @noinspection RedundantDocCommentTagInspection
 * @noinspection MethodShouldBeFinalInspection
 * @noinspection PhpUnused
 */

namespace OswisOrg\OswisCalendarBundle\Service;

use DateTime;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityManagerInterface;
use OswisOrg\OswisAddressBookBundle\Entity\AbstractClass\AbstractContact;
use OswisOrg\OswisCalendarBundle\Entity\Event\Event;
use OswisOrg\OswisCalendarBundle\Entity\Event\RegistrationsRange;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantFlag;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantFlagType;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantType;
use OswisOrg\OswisCalendarBundle\Exception\EventCapacityExceededException;
use OswisOrg\OswisCalendarBundle\Provider\OswisCalendarSettingsProvider;
use OswisOrg\OswisCalendarBundle\Repository\ParticipantRepository;
use OswisOrg\OswisCalendarBundle\Repository\RegistrationsRangeRepository;
use OswisOrg\OswisCoreBundle\Entity\AppUser\AppUser;
use Psr\Log\LoggerInterface;

class RegistrationService
{
    protected EntityManagerInterface $em;

    protected LoggerInterface $logger;

    protected ParticipantService $participantService;

    protected RegistrationsRangeRepository $registrationsRangeRepository;

    protected OswisCalendarSettingsProvider $calendarSettings;

    public function __construct(
        EntityManagerInterface $em,
        LoggerInterface $logger,
        ParticipantService $participantService,
        RegistrationsRangeRepository $registrationsRangeRepository,
        OswisCalendarSettingsProvider $calendarSettings
    ) {
        $this->em = $em;
        $this->logger = $logger;
        $this->participantService = $participantService;
        $this->registrationsRangeRepository = $registrationsRangeRepository;
        $this->calendarSettings = $calendarSettings;
    }

    public function getParticipantService(): ParticipantService
    {
        return $this->participantService;
    }


    public function getRepository(): RegistrationsRangeRepository
    {
        $repository = $this->em->getRepository(RegistrationsRange::class);
        assert($repository instanceof RegistrationsRangeRepository);

        return $repository;
    }


    final public function containsAppUser(Event $event, AppUser $appUser, ParticipantType $participantType = null): bool
    {
        $opts = [
            ParticipantRepository::CRITERIA_EVENT            => $event,
            ParticipantRepository::CRITERIA_PARTICIPANT_TYPE => $participantType,
            ParticipantRepository::CRITERIA_APP_USER         => $appUser,
        ];

        return $this->participantService->getRepository()->getParticipants($opts)->count() > 0;
    }

    /**
     * @param Participant      $newParticipant
     * @param Participant|null $oldParticipant
     *
     * @throws EventCapacityExceededException
     */
    public function simulateRegistration(Participant $newParticipant, ?Participant $oldParticipant = null): void
    {
        $newRange = $newParticipant->getRegistrationsRange();
        $newContact = $newParticipant->getContact();
        $newParticipantType = $newParticipant->getParticipantType();
        if (null === $newParticipantType || null === $newContact || null === $newRange) {
            throw $this->missingRelations($newRange, $newContact, $newParticipantType);
        }
        $oldRange = $oldParticipant ? $oldParticipant->getRegistrationsRange() : null;
        $oldContact = $oldParticipant ? $oldParticipant->getContact() : null;
        $oldParticipantType = $oldParticipant ? $oldParticipant->getParticipantType() : null;
        $newFlags = $newParticipant->getParticipantFlags();
        $oldFlags = $oldParticipant ? $oldParticipant->getParticipantFlags() : new ArrayCollection();
        /// TODO: Check all conditions.
        if (null !== $oldContact && $newContact->getId() !== $oldContact->getId()) { // Contact was changed.
            throw new EventCapacityExceededException('Výměna účastníka v přihlášce není implementována.');
        }
        if (null !== $oldParticipantType && $newParticipantType->getId() !== $oldParticipantType->getId()) { // Participant type was changed.
            throw new EventCapacityExceededException('Změna typu účastníka není povolena.');
        }
        if (null === $oldEvent || $newEvent->getId() !== $oldEvent->getId()) { // Event was changed or participant is new.
            $this->checkRegistrationRanges($newEvent, $newParticipantType, $newParticipant->getCreatedDateTime() ?? new DateTime());
            $this->checkCapacity($newEvent, $newParticipantType);
        }
        /// TODO: Check "isParentRequired".
        $this->checkFlags($newParticipant, $newEvent, $newParticipantType);
        $this->checkFlagsCapacity($newEvent, $newParticipantType, $newFlags, $oldFlags);
    }

    public function missingRelations(?RegistrationsRange $range, ?AbstractContact $contact, ?ParticipantType $participantType): EventCapacityExceededException
    {
        if (null === $range) {
            return new EventCapacityExceededException('Přihlašovací rozsah události nenalezen.');
        }
        if (null === $participantType) {
            return new EventCapacityExceededException('Neplatný typ uživatele.');
        }
        if (null === $contact) {
            return new EventCapacityExceededException('Účastník neexistuje.');
        }

        return new EventCapacityExceededException('Přihláška je poškozena.');
    }

    /**
     * @param Event           $event
     * @param ParticipantType $participantType
     * @param DateTime|null   $dateTime
     *
     * @throws EventCapacityExceededException
     */
    public function checkRegistrationRanges(Event $event, ParticipantType $participantType, ?DateTime $dateTime = null): void
    {
        if (!$event->isRegistrationsAllowed($participantType, $dateTime)) {
            throw new EventCapacityExceededException('Přihlašování na událost '.$event->getName().' není aktuálně povoleno.');
        }
    }

    /**
     * @param Event           $event
     * @param ParticipantType $participantType
     *
     * @throws EventCapacityExceededException
     */
    public function checkCapacity(Event $event, ParticipantType $participantType): void
    {
        if ($this->getRemainingCapacity($event, $participantType) === 0) {
            throw new EventCapacityExceededException('Kapacita akce '.$event->getName().' byla překročena.');
        }
    }

    public function getRemainingCapacity(Event $event, ?ParticipantType $eventParticipantType = null): ?int
    {
        $occupancy = $this->participantService->getOccupancy($event, $eventParticipantType);
        $maximumCapacity = $event->getCapacity($eventParticipantType);
        if (null !== $maximumCapacity) {
            return ($maximumCapacity - $occupancy) > 0 ? ($maximumCapacity - $occupancy) : 0;
        }

        return null;
    }

    /**
     * @param Participant     $participant
     * @param Event           $event
     * @param ParticipantType $participantType
     *
     * @throws EventCapacityExceededException
     */
    public function checkFlags(Participant $participant, Event $event, ParticipantType $participantType): void
    {
        $flagsByTypes = $participant->getFlagsAggregatedByType();
        $allowedFlagsByTypes = $event->getAllowedFlagsAggregatedByType($participantType);
        $this->checkFlagsExistence($event, $flagsByTypes, $allowedFlagsByTypes);
        $this->checkFlagsRanges($flagsByTypes, $allowedFlagsByTypes);
    }

    /**
     * Checks if type of each used flag can be used in event.
     *
     * @param Event $event
     * @param array $flagsByTypes
     * @param array $allowedFlagsByTypes
     *
     * @throws EventCapacityExceededException
     */
    private function checkFlagsExistence(Event $event, array $flagsByTypes, array $allowedFlagsByTypes): void
    {
        foreach ($flagsByTypes as $flagsOfType) { // Check if flagType is allowed in event.
            $firstFlagOfType = $flagsOfType[array_key_first($flagsOfType)] ?? null;
            if (!($firstFlagOfType instanceof ParticipantFlag)) {
                continue;
            }
            $flagType = $firstFlagOfType->getFlagType();
            if ($flagType && !array_key_exists($flagType->getSlug(), $allowedFlagsByTypes)) {
                $message = 'Příznak typu '.$flagType->getName().' není u události '.$event->getName().' povolen.';
                throw new EventCapacityExceededException($message);
            }
        }
    }

    /**
     * Checks if the amount of flags of each type meets range of that type.
     *
     * @param array $flagsByTypes
     * @param array $allowedFlagsByTypes
     *
     * @throws EventCapacityExceededException
     */
    private function checkFlagsRanges(array $flagsByTypes, array $allowedFlagsByTypes): void
    {
        foreach ($allowedFlagsByTypes as $flagsOfType) {
            $flagType = $flagsOfType['flagType'] instanceof ParticipantFlagType ? $flagsOfType['flagType'] : null;
            $flagTypeSlug = $flagType ? $flagType->getSlug() : '0';
            $flagsAmount = count($flagsByTypes[$flagTypeSlug] ?? []);
            $min = $flagType ? $flagType->getMinInParticipant() ?? 0 : 0;
            $max = $flagType ? $flagType->getMaxInParticipant() : null;
            if (null !== $flagType && ($min > $flagsAmount || (null !== $max && $max < $flagsAmount))) {
                $maxMessage = null === $max ? '' : "až $max";
                throw new EventCapacityExceededException("Musí být vybráno $min $maxMessage příznaků typu ".$flagType->getName().".");
            }
        }
    }

    /**
     * Checks if amount of flags (used in participant) was not exceeded in event.
     *
     * @param Event           $event
     * @param ParticipantType $participantType
     * @param Collection      $newFlags
     * @param Collection|null $oldFlags
     *
     * @throws EventCapacityExceededException
     */
    public function checkFlagsCapacity(Event $event, ParticipantType $participantType, Collection $newFlags, ?Collection $oldFlags = null): void
    {
        $oldFlags ??= new ArrayCollection();
        $eventName = $event->getShortName();
        foreach ($newFlags as $newFlag) {
            $newFlagId = $newFlag instanceof ParticipantFlag ? $newFlag->getId() : null;
            $newFlagName = $newFlag instanceof ParticipantFlag ? $newFlag->getName() : null;
            $newFlagCount = $newFlags->filter(fn(ParticipantFlag $flag) => $flag->getId() === $newFlagId)->count();
            $oldFlagCount = $oldFlags->filter(fn(ParticipantFlag $flag) => $flag->getId() === $newFlagId)->count();
            if ($this->getParticipantFlagRemainingCapacity($event, $newFlag, $participantType) < ($newFlagCount - $oldFlagCount)) {
                throw new EventCapacityExceededException("Kapacita příznaku $newFlagName u události $eventName byla překročena.");
            }
        }
    }

    /**
     * Get remaining capacity of participant flag in event (by given participantType).
     *
     * @param Event                $event
     * @param ParticipantFlag|null $participantFlag
     * @param ParticipantType|null $participantType
     *
     * @return int Remaining capacity of flag.
     */
    public function getParticipantFlagRemainingCapacity(
        Event $event,
        ?ParticipantFlag $participantFlag,
        ?ParticipantType $participantType
    ): int {
        $allowedAmount = $event->getParticipantFlagCapacity($participantFlag, $participantType);
        $actualAmount = $this->participantService->getEventParticipantFlags($event, $participantType, $participantFlag)->count();

        return ($allowedAmount - $actualAmount) < 0 ? 0 : ($allowedAmount - $actualAmount);
    }

    /**
     * Checks if contact is participant in superEvent of event (if it's required).
     *
     * Throws exception if participant is not present in superEvent and it's required.
     *
     * @param Event           $newEvent
     * @param ParticipantType $newParticipantType
     * @param AbstractContact $newContact
     *
     * @throws EventCapacityExceededException
     */
    public function checkSuperEventContain(Event $newEvent, ParticipantType $newParticipantType, AbstractContact $newContact): void
    {
        if (!$newEvent->isSuperEventRequired($newParticipantType)) {
            return;
        }
        $isInSuperEvent = false;
        foreach ($newEvent->getSuperEvents() as $superEvent) {
            if ($superEvent instanceof Event && $this->participantService->isContactContainedInEvent($superEvent, $newContact, $newParticipantType)) {
                $isInSuperEvent = true;
            }
        }
        if (!$isInSuperEvent) {
            throw new EventCapacityExceededException('Je nutné se zúčastnit i nadřazené události.');
        }
    }

}