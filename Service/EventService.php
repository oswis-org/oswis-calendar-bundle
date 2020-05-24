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
use OswisOrg\OswisCalendarBundle\Entity\EventParticipant\EventParticipant;
use OswisOrg\OswisCalendarBundle\Entity\EventParticipant\EventParticipantFlag;
use OswisOrg\OswisCalendarBundle\Entity\EventParticipant\EventParticipantFlagType;
use OswisOrg\OswisCalendarBundle\Entity\EventParticipant\EventParticipantType;
use OswisOrg\OswisCalendarBundle\Exception\EventCapacityExceededException;
use OswisOrg\OswisCalendarBundle\Provider\OswisCalendarSettingsProvider;
use OswisOrg\OswisCalendarBundle\Repository\EventParticipantRepository;
use OswisOrg\OswisCalendarBundle\Repository\EventRepository;
use OswisOrg\OswisCoreBundle\Entity\AppUser\AppUser;
use Psr\Log\LoggerInterface;

class EventService
{
    protected EntityManagerInterface $em;

    protected LoggerInterface $logger;

    protected EventParticipantService $participantService;

    protected OswisCalendarSettingsProvider $calendarSettings;

    protected ?Event $defaultEvent = null;

    public function __construct(EntityManagerInterface $em, LoggerInterface $logger, EventParticipantService $participantService, OswisCalendarSettingsProvider $calendarSettings)
    {
        $this->em = $em;
        $this->logger = $logger;
        $this->participantService = $participantService;
        $this->calendarSettings = $calendarSettings;
        $this->setDefaultEvent();
    }

    public function create(Event $event): Event
    {
        $this->em->persist($event);
        $this->em->flush();
        $this->logger->info('CREATE: Created event (by service): '.$event->getId().' '.$event->getName().'.');

        return $event;
    }

    public function getDefaultEvent(): ?Event
    {
        return $this->defaultEvent;
    }

    public function setDefaultEvent(): ?Event
    {
        $opts = [
            EventRepository::CRITERIA_SLUG               => $this->calendarSettings->getDefaultEvent(),
            EventRepository::CRITERIA_ONLY_PUBLIC_ON_WEB => true,
            EventRepository::CRITERIA_INCLUDE_DELETED    => false,
        ];
        $event = $this->getRepository()->getEvent($opts);
        foreach ($this->calendarSettings->getDefaultEventFallbacks() as $fallback) {
            if (null === $event && !empty($fallback)) {
                $opts[EventRepository::CRITERIA_SLUG] = $fallback;
                $event = $this->getRepository()->getEvent($opts);
            }
        }

        return $this->defaultEvent = $event;
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
        $organizer = $this->getOrganizers($event)->map(fn(EventParticipant $p) => $p->getContact())->first();
        if (empty($organizer)) {
            $organizer = $event->getSuperEvent() ? $this->getOrganizer($event->getSuperEvent()) : null;
        }

        return empty($organizer) ? null : $organizer;
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
        $newParticipantType = $newParticipant->getParticipantType();
        if (null === $newParticipantType || null === $newContact || null === $newEvent) {
            throw $this->checkEventParticipantConnections($newEvent, $newContact, $newParticipantType);
        }
        $oldEvent = $oldParticipant ? $oldParticipant->getEvent() : null;
        $oldContact = $oldParticipant ? $oldParticipant->getContact() : null;
        $oldParticipantType = $oldParticipant ? $oldParticipant->getParticipantType() : null;
        $newFlags = $newParticipant->getParticipantFlags();
        $oldFlags = $oldParticipant ? $oldParticipant->getParticipantFlags() : new ArrayCollection();
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
        $this->checkFlags($newParticipant, $newEvent, $newParticipantType);
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
        if ($this->getRemainingCapacity($event, $participantType) === 0) {
            throw new EventCapacityExceededException('Kapacita akce '.$event->getName().' byla překročena.');
        }
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

    /**
     * @param EventParticipant     $participant
     * @param Event                $event
     * @param EventParticipantType $participantType
     *
     * @throws EventCapacityExceededException
     */
    public function checkFlags(EventParticipant $participant, Event $event, EventParticipantType $participantType): void
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
            if (!($firstFlagOfType instanceof EventParticipantFlag)) {
                continue;
            }
            $flagType = $firstFlagOfType->getEventParticipantFlagType();
            if ($flagType && !array_key_exists('flag_'.$flagType->getSlug(), $allowedFlagsByTypes)) {
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
        foreach ($allowedFlagsByTypes as $flagsOfType) { // Check if flag amounts in participant belongs to flagType ranges (min and max).
            $flagType = $flagsOfType['flagType'] instanceof EventParticipantFlagType ? $flagsOfType['flagType'] : null;
            $flagTypeSlug = $flagType ? $flagType->getSlug() : '0';
            $flagsAmount = count($flagsByTypes["flag_$flagTypeSlug"]);
            $min = $flagType ? $flagType->getMinInParticipant() ?? 0 : null;
            $max = $flagType ? $flagType->getMaxInParticipant() : null;
            if (null !== $flagType && ($min > $flagsAmount || (null !== $max && $max < $flagsAmount))) {
                $maxMessage = null === $max ? '' : "až $max";
                throw new EventCapacityExceededException("Musí být vybráno $min až $maxMessage příznaků typu ".$flagType->getName().".");
            }
        }
    }

    /**
     * Checks if amount of flags (used in participant) was not exceeded in event.
     *
     * @param Event                $event
     * @param EventParticipantType $participantType
     * @param Collection           $newFlags
     * @param Collection|null      $oldFlags
     *
     * @throws EventCapacityExceededException
     */
    public function checkFlagsCapacity(Event $event, EventParticipantType $participantType, Collection $newFlags, ?Collection $oldFlags = null): void
    {
        $oldFlags ??= new ArrayCollection();
        $eventName = $event->getShortName();
        foreach ($newFlags as $newFlag) {
            $newFlagId = $newFlag instanceof EventParticipantFlag ? $newFlag->getId() : null;
            $newFlagName = $newFlag instanceof EventParticipantFlag ? $newFlag->getName() : null;
            $newFlagCount = $newFlags->filter(fn(EventParticipantFlag $flag) => $flag->getId() === $newFlagId)->count();
            $oldFlagCount = $oldFlags->filter(fn(EventParticipantFlag $flag) => $flag->getId() === $newFlagId)->count();
            if ($this->getParticipantFlagRemainingCapacity($event, $newFlag, $participantType) < ($newFlagCount - $oldFlagCount)) {
                throw new EventCapacityExceededException("Kapacita příznaku $newFlagName u události $eventName byla překročena.");
            }
        }
    }

    /**
     * Get remaining capacity of participant flag in event (by given participantType).
     *
     * @param Event                     $event
     * @param EventParticipantFlag|null $participantFlag
     * @param EventParticipantType|null $participantType
     *
     * @return int Remaining capacity of flag.
     */
    public function getParticipantFlagRemainingCapacity(
        Event $event,
        ?EventParticipantFlag $participantFlag,
        ?EventParticipantType $participantType
    ): int {
        $allowedAmount = $event->getParticipantFlagCapacity($participantFlag, $participantType);
        $actualAmount = $this->participantService->getEventParticipantFlags($event, $participantType, $participantFlag)->count();

        return ($allowedAmount - $actualAmount) < 0 ? 0 : ($allowedAmount - $actualAmount);
    }

    /**
     * Checks if contact is participant in superEvent of event (if it's required).
     *
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

    /**
     * Checks if contact is participant in event as given participantType.
     *
     * @param Event                     $event
     * @param AbstractContact           $contact
     * @param EventParticipantType|null $participantType
     *
     * @return bool
     */
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