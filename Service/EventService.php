<?php
/**
 * @noinspection RedundantDocCommentTagInspection
 * @noinspection MethodShouldBeFinalInspection
 * @noinspection PhpUnused
 */

namespace Zakjakub\OswisCalendarBundle\Service;

use DateTime;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Zakjakub\OswisAddressBookBundle\Entity\AbstractClass\AbstractContact;
use Zakjakub\OswisAddressBookBundle\Entity\Place;
use Zakjakub\OswisCalendarBundle\Entity\Event\Event;
use Zakjakub\OswisCalendarBundle\Entity\Event\EventSeries;
use Zakjakub\OswisCalendarBundle\Entity\Event\EventType;
use Zakjakub\OswisCalendarBundle\Entity\EventParticipant\EventParticipant;
use Zakjakub\OswisCalendarBundle\Entity\EventParticipant\EventParticipantFlag;
use Zakjakub\OswisCalendarBundle\Entity\EventParticipant\EventParticipantType;
use Zakjakub\OswisCalendarBundle\Exceptions\EventCapacityExceededException;
use Zakjakub\OswisCalendarBundle\Repository\EventParticipantRepository;
use Zakjakub\OswisCalendarBundle\Repository\EventRepository;
use Zakjakub\OswisCoreBundle\Entity\AppUser;
use Zakjakub\OswisCoreBundle\Entity\Nameable;

class EventService
{
    protected EntityManagerInterface $em;

    protected LoggerInterface $logger;

    protected EventParticipantService $participantService;

    public function __construct(EntityManagerInterface $em, ?LoggerInterface $logger, EventParticipantService $participantService)
    {
        $this->em = $em;
        $this->logger = $logger;
        $this->participantService = $participantService;
    }

    final public function create(
        ?Nameable $nameable = null,
        ?Event $superEvent = null,
        ?Place $location = null,
        ?EventType $eventType = null,
        ?DateTime $startDateTime = null,
        ?DateTime $endDateTime = null,
        ?EventSeries $eventSeries = null,
        ?bool $priceRecursiveFromParent = null
    ): Event {
        $entity = new Event($nameable, $superEvent, $location, $eventType, $startDateTime, $endDateTime, $eventSeries, $priceRecursiveFromParent);
        $this->em->persist($entity);
        $this->em->flush();
        $this->logger->info('CREATE: Created event (by service): '.$entity->getId().' '.$entity->getName().'.');

        return $entity;
    }

    public function getRepository(): EventRepository
    {
        $repository = $this->em->getRepository(Event::class);
        assert($repository instanceof EventRepository);

        return $repository;
    }

    public function getEventParticipantService(): EventParticipantService
    {
        return $this->participantService;
    }

    public function getRemainingCapacity(Event $event, ?EventParticipantType $eventParticipantType = null): ?int
    {
        $occupancy = $this->getOccupancy($event, $eventParticipantType);
        $maximumCapacity = $event->getCapacity($eventParticipantType);
        if (null !== $maximumCapacity) {
            return ($maximumCapacity - $occupancy) > 0 ? ($maximumCapacity - $occupancy) : 0;
        }

        return null;
    }

    final public function getOccupancy(
        Event $event,
        ?EventParticipantType $participantType = null,
        ?bool $includeDeleted = false,
        ?bool $includeNotActivated = true,
        ?int $recursiveDepth = null
    ): int {
        $opts = [
            EventParticipantRepository::CRITERIA_EVENT                 => $event,
            EventParticipantRepository::CRITERIA_PARTICIPANT_TYPE      => $participantType,
            EventParticipantRepository::CRITERIA_INCLUDE_DELETED       => $includeDeleted,
            EventParticipantRepository::CRITERIA_EVENT_RECURSIVE_DEPTH => $recursiveDepth,
        ];

        return $this->participantService->getEventParticipants($opts, $includeNotActivated)->count();
    }

    final public function containsAppUser(Event $event, AppUser $appUser, EventParticipantType $participantType = null): bool
    {
        $opts = [
            EventParticipantRepository::CRITERIA_EVENT            => $event,
            EventParticipantRepository::CRITERIA_PARTICIPANT_TYPE => $participantType,
            EventParticipantRepository::CRITERIA_APP_USER         => $appUser,
        ];

        return $this->participantService->getRepository()->getEventParticipants($opts)->count() > 0;
    }

    final public function getOrganizer(Event $event): ?AbstractContact
    {
        return $this->getOrganizers($event)->first();
    }

    final public function getOrganizers(Event $event): Collection
    {
        return $this->participantService->getEventParticipantsByTypeOfType($event, EventParticipantType::TYPE_ORGANIZER);
    }

    /**
     * @param EventParticipant      $newParticipant
     * @param EventParticipant|null $oldParticipant
     *
     * @throws EventCapacityExceededException
     */
    final public function simulateAddEventParticipant(EventParticipant $newParticipant, ?EventParticipant $oldParticipant = null): void
    {
        $newEvent = $newParticipant->getEvent();
        $newContact = $newParticipant->getContact();
        $newParticipantType = $newParticipant->getEventParticipantType();
        if (null === $newParticipantType || null === $newContact || null === $newEvent) {
            throw $this->checkEventParticipantConnections($newEvent, $newContact, $newParticipantType);
        }
        $oldEvent = $oldParticipant ? $oldParticipant->getEvent() : null;
        $oldContact = $oldParticipant ? $oldParticipant->getContact() : null;
        $oldParticipantType = $oldParticipant ? $oldParticipant->getEventParticipantType() : null;
        $newFlags = $newParticipant->getEventParticipantFlags();
        $oldFlags = $oldParticipant ? $oldParticipant->getEventParticipantFlags() : new ArrayCollection();
        /// TODO: Check all conditions.
        if (null !== $oldContact && $newContact->getId() !== $oldContact->getId()) { // Contact was changed.
            throw new EventCapacityExceededException('Výměna účastníka v přihlášce není povolena.');
        }
        if (null !== $oldParticipantType && $newParticipantType->getId() !== $oldParticipantType->getId()) { // Participant type was changed.
            throw new EventCapacityExceededException('Změna typu účastníka není povolena.');
        }
        if (null === $oldEvent || $newEvent->getId() !== $oldEvent->getId()) { // Event was changed or participant is new.
            $this->checkRegistrationRanges($newEvent, $newParticipantType, $newParticipant->getCreatedDateTime() ?? new DateTime());
            $this->checkCapacity($newEvent, $newParticipantType);
        }
        /// TODO: Check "isParentRequired".
        $this->checkFlagsRanges($newParticipant, $newEvent, $newParticipantType);
        $this->checkFlagsCapacity($newEvent, $newParticipantType, $newFlags, $oldFlags);
    }

    public function checkEventParticipantConnections(
        ?Event $event,
        ?AbstractContact $contact,
        ?EventParticipantType $participantType
    ): EventCapacityExceededException {
        if (null === $event) {
            return new EventCapacityExceededException('Událost nenalezena.');
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
     * @param Event                $event
     * @param EventParticipantType $participantType
     * @param DateTime|null        $dateTime
     *
     * @throws EventCapacityExceededException
     */
    public function checkRegistrationRanges(Event $event, EventParticipantType $participantType, ?DateTime $dateTime = null): void
    {
        if (!$event->isRegistrationsAllowed($participantType, $dateTime)) {
            throw new EventCapacityExceededException('Přihlašování na událost '.$event->getName().' není aktuálně povoleno.');
        }
    }

    /**
     * @param Event                $event
     * @param EventParticipantType $participantType
     *
     * @throws EventCapacityExceededException
     */
    public function checkCapacity(Event $event, EventParticipantType $participantType): void
    {
        if ($this->$this->getRemainingCapacity($event, $participantType) === 0) {
            throw new EventCapacityExceededException('Kapacita akce '.$event->getName().' byla překročena.');
        }
    }

    /**
     * @param EventParticipant     $participant
     * @param Event                $event
     * @param EventParticipantType $participantType
     *
     * @throws EventCapacityExceededException
     */
    public function checkFlagsRanges(EventParticipant $participant, Event $event, EventParticipantType $participantType): void
    {
        $participantFlagsByTypes = $participant->getFlagsAggregatedByType();
        $allowedFlagsByTypes = $event->getAllowedFlagsAggregatedByType($participantType);
        foreach ($participantFlagsByTypes as $oneTypeFlags) { // Check if flagType is allowed in event.
            $flagOfType = $oneTypeFlags[array_key_first($oneTypeFlags)];
            assert($flagOfType instanceof EventParticipantFlag);
            $flagType = $flagOfType->getEventParticipantFlagType();
            if ($flagType && !array_key_exists($flagType->getSlug(), $allowedFlagsByTypes)) {
                $message = 'Příznak typu '.$flagType->getName().' není u události '.$event->getName().' povolen.';
                throw new EventCapacityExceededException($message);
            }
        }
        foreach ($allowedFlagsByTypes as $oneTypeFlags) { // Check if flag amounts in participant belongs to flagType ranges (min and max).
            $flagOfType = $oneTypeFlags[array_key_first($oneTypeFlags)];
            assert($flagOfType instanceof EventParticipantFlag);
            $flagType = $flagOfType->getEventParticipantFlagType();
            if ($flagType && $flagType->getMinInEventParticipant() > count($participantFlagsByTypes[$flagType->getId()])) {
                throw new EventCapacityExceededException('Musí být vybrán příznak typu '.$flagType->getName().'.');
            }
            if ($flagType && $flagType->getMaxInEventParticipant() < count($participantFlagsByTypes[$flagType->getId()])) {
                throw new EventCapacityExceededException('Překročen počet příznaků typu '.$flagType->getName().'.');
            }
        }
    }

    /**
     * @param Event                $event
     * @param EventParticipantType $participantType
     * @param Collection           $newFlags
     * @param Collection|null      $oldFlags
     *
     * @throws EventCapacityExceededException
     * @noinspection PhpUndefinedMethodInspection
     */
    public function checkFlagsCapacity(Event $event, EventParticipantType $participantType, Collection $newFlags, ?Collection $oldFlags = null): void
    {
        $oldFlags ??= new ArrayCollection();
        foreach ($newFlags as $newFlag) {
            assert($newFlag instanceof EventParticipantFlag);
            $newFlagCount = $newFlags->filter(fn(EventParticipantFlag $flag) => $newFlag->getId() === $flag->getId())->count();
            $oldFlagCount = $oldFlags->filter(fn(EventParticipantFlag $flag) => $newFlag->getId() === $flag->getId())->count();
            if ($this->getAllowedEventParticipantFlagRemainingAmount($event, $newFlag, $participantType) < ($newFlagCount - $oldFlagCount)) {
                $message = 'Kapacita příznaku '.$newFlag->getName().' u události '.$event->getName().' byla překročena.';
                throw new EventCapacityExceededException($message);
            }
        }
    }

    public function getAllowedEventParticipantFlagRemainingAmount(
        Event $event,
        ?EventParticipantFlag $participantFlag,
        ?EventParticipantType $participantType
    ): int {
        $allowedAmount = $event->getAllowedEventParticipantFlagAmount($participantFlag, $participantType);
        $actualAmount = $this->participantService->getEventParticipantFlags($event, $participantType, $participantFlag)->count();

        return ($allowedAmount - $actualAmount) < 0 ? 0 : ($allowedAmount - $actualAmount);
    }

    /**
     * @param Event                $newEvent
     * @param EventParticipantType $newParticipantType
     * @param AbstractContact      $newContact
     *
     * @throws EventCapacityExceededException
     */
    public function checkSuperEventContain(Event $newEvent, EventParticipantType $newParticipantType, AbstractContact $newContact): void
    {
        if ($newEvent->isSuperEventRequired($newParticipantType)) {
            $isInSuperEvent = false;
            foreach ($newEvent->getSuperEvents() as $superEvent) {
                if ($superEvent instanceof Event && $this->containsContact($superEvent, $newContact, $newParticipantType)) {
                    $isInSuperEvent = true;
                }
            }
            if (!$isInSuperEvent) {
                throw new EventCapacityExceededException('Je nutné se zúčastnit i nadřazené události.');
            }
        }
    }

    final public function containsContact(Event $event, AbstractContact $contact, EventParticipantType $participantType = null): bool
    {
        $opts = [
            EventParticipantRepository::CRITERIA_EVENT            => $event,
            EventParticipantRepository::CRITERIA_PARTICIPANT_TYPE => $participantType,
            EventParticipantRepository::CRITERIA_CONTACT          => $contact,
        ];

        return $this->participantService->getRepository()->getEventParticipants($opts)->count() > 0;
    }
}