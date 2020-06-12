<?php
/**
 * @noinspection RedundantDocCommentTagInspection
 * @noinspection PhpUnused
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Entity\Participant;

use ApiPlatform\Core\Annotation\ApiResource;
use DateTime;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Exception;
use OswisOrg\OswisAddressBookBundle\Entity\AbstractClass\AbstractContact;
use OswisOrg\OswisCalendarBundle\Entity\Event\Event;
use OswisOrg\OswisCalendarBundle\Entity\Event\RegistrationFlag;
use OswisOrg\OswisCalendarBundle\Entity\Event\RegistrationRange;
use OswisOrg\OswisCalendarBundle\Entity\NonPersistent\FlagsByType;
use OswisOrg\OswisCalendarBundle\Exception\EventCapacityExceededException;
use OswisOrg\OswisCoreBundle\Entity\Revisions\AbstractRevision;
use OswisOrg\OswisCoreBundle\Exceptions\OswisException;
use OswisOrg\OswisCoreBundle\Exceptions\PriceInvalidArgumentException;
use OswisOrg\OswisCoreBundle\Filter\SearchAnnotation as Searchable;
use OswisOrg\OswisCoreBundle\Interfaces\Common\BasicInterface;
use OswisOrg\OswisCoreBundle\Traits\Common\BasicMailConfirmationTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\BasicTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\PriorityTrait;
use Symfony\Component\Serializer\Annotation\MaxDepth;
use function assert;

/**
 * Participation of contact in event (attendee, sponsor, organizer, guest, partner...).
 *
 * @Doctrine\ORM\Mapping\Entity(repositoryClass="OswisOrg\OswisCalendarBundle\Repository\ParticipantRepository")
 * @Doctrine\ORM\Mapping\Table(name="calendar_participant")
 * @ApiResource(
 *   attributes={
 *     "filters"={"search"},
 *     "access_control"="is_granted('ROLE_MANAGER')"
 *   },
 *   collectionOperations={
 *     "get"={
 *       "access_control"="is_granted('ROLE_MANAGER')",
 *       "normalization_context"={"groups"={"calendar_participants_get"}, "enable_max_depth"=true},
 *     },
 *     "post"={
 *       "access_control"="is_granted('ROLE_MANAGER')",
 *       "denormalization_context"={"groups"={"calendar_participants_post"}, "enable_max_depth"=true}
 *     }
 *   },
 *   itemOperations={
 *     "get"={
 *       "access_control"="is_granted('ROLE_MANAGER')",
 *       "normalization_context"={"groups"={"calendar_participant_get"}, "enable_max_depth"=true},
 *     },
 *     "put"={
 *       "access_control"="is_granted('ROLE_MANAGER')",
 *       "denormalization_context"={"groups"={"calendar_participant_put"}, "enable_max_depth"=true}
 *     },
 *     "delete"={
 *       "access_control"="is_granted('ROLE_MANAGER')",
 *       "denormalization_context"={"groups"={"calendar_participant_delete"}, "enable_max_depth"=true}
 *     }
 *   }
 * )
 * @Searchable({
 *     "id",
 *     "id",
 *     "name",
 *     "shortName",
 *     "slug",
 *     "color",
 *     "startDateTime",
 *     "endDateTime",
 *     "event.type.id",
 *     "event.type.name",
 *     "event.type.shortName",
 *     "event.type.slug",
 *     "event.type.color",
 *     "contact.id",
 *     "contact.contactName",
 *     "contact.contactDetails.content",
 *     "eventParticipantFlagConnections.eventParticipantFlag.name",
 *     "eventParticipantFlagConnections.eventParticipantFlag.shortName",
 *     "eventParticipantFlagConnections.eventParticipantFlag.slug"
 * })
 * @Doctrine\ORM\Mapping\Cache(usage="NONSTRICT_READ_WRITE", region="calendar_participant")
 */
class Participant implements BasicInterface
{
    use BasicTrait;
    use BasicMailConfirmationTrait;
    use PriorityTrait;

    /**
     * @Doctrine\ORM\Mapping\ManyToMany(
     *     targetEntity="OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantNote",
     *     cascade={"all"},
     *     fetch="EAGER"
     * )
     * @Doctrine\ORM\Mapping\JoinTable(
     *     name="calendar_participant_note_connection",
     *     joinColumns={@Doctrine\ORM\Mapping\JoinColumn(name="participant_id", referencedColumnName="id")},
     *     inverseJoinColumns={@Doctrine\ORM\Mapping\JoinColumn(name="participant_note_id", referencedColumnName="id", unique=true)}
     * )
     */
    protected ?Collection $notes = null;

    /**
     * @Doctrine\ORM\Mapping\OneToMany(
     *     targetEntity="OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantPayment",
     *     cascade={"all"},
     *     mappedBy="participant",
     *     fetch="EAGER"
     * )
     * @MaxDepth(1)
     */
    protected ?Collection $payments = null;

    /**
     * Related contact (person or organization).
     * @Doctrine\ORM\Mapping\ManyToOne(
     *     targetEntity="OswisOrg\OswisAddressBookBundle\Entity\AbstractClass\AbstractContact", cascade={"all"}, fetch="EAGER"
     * )
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     */
    protected ?AbstractContact $contact = null;

    /**
     * @Doctrine\ORM\Mapping\ManyToOne(targetEntity="OswisOrg\OswisCalendarBundle\Entity\Event\RegistrationRange", fetch="EAGER")
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     */
    protected ?RegistrationRange $range = null;

    /**
     * @Doctrine\ORM\Mapping\ManyToOne(targetEntity="OswisOrg\OswisCalendarBundle\Entity\Event\Event", fetch="EAGER")
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     */
    protected ?Event $event = null;

    /**
     * @Doctrine\ORM\Mapping\ManyToOne(targetEntity="ParticipantCategory", fetch="EAGER")
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     */
    protected ?ParticipantCategory $participantType = null;

    /**
     * @Doctrine\ORM\Mapping\ManyToMany(
     *     targetEntity="OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantFlagCategory", cascade={"all"}, fetch="EAGER"
     * )
     * @Doctrine\ORM\Mapping\JoinTable(
     *     name="calendar_participant_flag_category_connection",
     *     joinColumns={@Doctrine\ORM\Mapping\JoinColumn(name="participant_id", referencedColumnName="id")},
     *     inverseJoinColumns={@Doctrine\ORM\Mapping\JoinColumn(name="participant_flag_category_id", referencedColumnName="id", unique=true)}
     * )
     */
    protected ?Collection $flagCategories = null;

    /**
     * @Doctrine\ORM\Mapping\ManyToMany(
     *     targetEntity="OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantRange", cascade={"all"}, fetch="EAGER"
     * )
     * @Doctrine\ORM\Mapping\JoinTable(
     *     name="calendar_participant_range_connection_connection",
     *     joinColumns={@Doctrine\ORM\Mapping\JoinColumn(name="participant_id", referencedColumnName="id")},
     *     inverseJoinColumns={@Doctrine\ORM\Mapping\JoinColumn(name="participant_range_id", referencedColumnName="id", unique=true)}
     * )
     */
    protected ?Collection $ranges = null;

    /**
     * @Doctrine\ORM\Mapping\ManyToMany(
     *     targetEntity="ParticipantContact", cascade={"all"}, fetch="EAGER"
     * )
     * @Doctrine\ORM\Mapping\JoinTable(
     *     name="calendar_participant_contact_connection"
     *     joinColumns={@Doctrine\ORM\Mapping\JoinColumn(name="participant_id", referencedColumnName="id")},
     *     inverseJoinColumns={@Doctrine\ORM\Mapping\JoinColumn(name="participant_contact_id", referencedColumnName="id", unique=true)}
     * )
     */
    protected ?Collection $contactConnections = null;

    /**
     * @Doctrine\ORM\Mapping\Column(type="string", nullable=true)
     */
    protected ?bool $formal = null;

    /**
     * @param ParticipantContact|null $contactConnection
     * @param ParticipantRange|null   $rangeConnection
     * @param Collection|null         $flagConnections
     * @param Collection|null         $participantNotes
     * @param int|null                $priority
     *
     * @throws OswisException|EventCapacityExceededException
     */
    public function __construct(
        ParticipantContact $contactConnection = null,
        ParticipantRange $rangeConnection = null,
        ?Collection $flagConnections = null,
        ?Collection $participantNotes = null,
        ?int $priority = null
    ) {
        $this->contactConnections = new ArrayCollection();
        $this->ranges = new ArrayCollection();
        $this->notes = new ArrayCollection();
        $this->flagCategories = new ArrayCollection();
        $this->setContactConnection($contactConnection);
        $this->setRangeConnection($rangeConnection);
        $this->setNotes($participantNotes);
        $this->setPayments(new ArrayCollection());
        $this->setFlagCategories($flagConnections);
        $this->setPriority($priority);
    }

    /**
     * @param ParticipantContact|null $contactConnection
     *
     * @throws OswisException
     */
    public function setContactConnection(?ParticipantContact $contactConnection): void
    {
        if ($this->getContactConnection() !== $contactConnection) {
            foreach ($this->getContactConnections(true) as $connection) {
                if ($connection instanceof ParticipantContact) {
                    $connection->delete();
                    $this->getContactConnections()->remove($connection);
                }
            }
            if (!$this->getContactConnections()->contains($contactConnection)) {
                $this->getContactConnections()->add($contactConnection);
            }
            $this->updateCachedColumns();
        }
    }

    /**
     * @return ParticipantContact
     * @throws OswisException
     */
    public function getContactConnection(): ?ParticipantContact
    {
        $connections = $this->getContactConnections(true);
        if ($connections->count() > 1) {
            throw new OswisException('Účastník je přiřazen k více událostem najednou.');
        }

        return $connections->first() ?: null;
    }

    public function getContactConnections(bool $onlyActive = false, bool $onlyDeleted = false): Collection
    {
        $connections = $this->contactConnections ??= new ArrayCollection();
        if ($onlyActive) {
            $connections = $connections->filter(fn(ParticipantContact $connection) => $connection->isActive());
        }
        if ($onlyDeleted) {
            $connections = $connections->filter(fn(ParticipantContact $connection) => $connection->isDeleted());
        }

        return $connections;
    }

    public function updateCachedColumns(): void
    {
        try {
            $this->range = $this->getRange();
            $this->contact = $this->getContact();
            $this->event = $this->getEvent();
            $this->participantType = $this->getParticipantType();
        } catch (OswisException $e) {
        }
    }

    /**
     * @return RegistrationRange|null
     * @throws OswisException
     */
    public function getRange(): ?RegistrationRange
    {
        return $this->getRangeConnection() ? $this->getRangeConnection()->getRange() : null;
    }

    /**
     * @param RegistrationRange|null $range
     *
     * @throws OswisException|EventCapacityExceededException
     */
    public function setRange(?RegistrationRange $range): void
    {
        if ($this->getRange() !== $range) {
            $this->setRangeConnection(new ParticipantRange($range));
        }
    }

    /**
     * @return ParticipantRange
     * @throws OswisException
     */
    public function getRangeConnection(): ?ParticipantRange
    {
        $connections = $this->getRanges(true, false);
        if ($connections->count() > 1) {
            throw new OswisException('Účastník je přiřazen k více událostem najednou.');
        }

        return $connections->first() ?: null;
    }

    public function getRanges(bool $onlyActive = false, bool $onlyDeleted = false): Collection
    {
        $connections = $this->ranges ?? new ArrayCollection();
        if ($onlyActive) {
            $connections = $connections->filter(fn(ParticipantRange $connection) => $connection->isActive());
        }
        if ($onlyDeleted) {
            $connections = $connections->filter(fn(ParticipantRange $connection) => $connection->isDeleted());
        }

        return $connections;
    }

    public function getContact(): ?AbstractContact
    {
        try {
            return $this->getContactConnection() ? $this->getContactConnection()->getContact() : null;
        } catch (OswisException $e) {
            return null;
        }
    }

    /**
     * @param AbstractContact $contact
     *
     * @throws OswisException
     */
    public function setContact(AbstractContact $contact): void
    {
        $this->setContactConnection(new ParticipantContact($contact));
    }

    public function getEvent(): ?Event
    {
        try {
            return $this->getRange() ? $this->getRange()->getEvent() : null;
        } catch (OswisException $e) {
            return null;
        }
    }

    public function getParticipantType(): ?ParticipantCategory
    {
        try {
            return $this->getRange() ? $this->getRange()->getParticipantType() : null;
        } catch (OswisException $e) {
            return null;
        }
    }

    /**
     * @param ParticipantRange|null $newRangeConnection
     *
     * @throws EventCapacityExceededException
     * @throws OswisException
     */
    public function setRangeConnection(?ParticipantRange $newRangeConnection): void
    {
        $oldRangeConnection = $this->getRangeConnection();
        if ($oldRangeConnection !== $newRangeConnection) {
            foreach ($this->getRanges(true) as $connection) {
                if ($connection instanceof ParticipantRange) {
                    $connection->delete();
                    $this->getRanges()->remove($connection);
                }
            }
            try {
                if ($newRangeConnection instanceof ParticipantRange) {
                    if (null !== $newRangeConnection->getRange()) {
                        $newRangeConnection->getRange()->simulateAdd($this);
                    }
                    $this->getRanges()->add($newRangeConnection);
                }
            } catch (EventCapacityExceededException $exception) {
                if ($oldRangeConnection instanceof ParticipantRange) {
                    $this->getRanges()->add($oldRangeConnection);
                }
                throw new $exception;
            }
        }
        $this->updateCachedColumns();
    }

    public static function filterCollection(Collection $participants, ?bool $includeNotActivated = true): Collection
    {
        $filtered = new ArrayCollection();
        foreach ($participants as $newParticipant) {
            assert($newParticipant instanceof self);
            if (!$includeNotActivated && !$newParticipant->hasActivatedContactUser()) {
                continue;
            }
            if (!$filtered->contains($newParticipant)) {
                $filtered->add($newParticipant);
            }
        }

        return $filtered;
    }

    /**
     * Checks if there is some activated user assigned to this participant.
     *
     * @param DateTime|null $dateTime Reference date and time.
     *
     * @return bool
     */
    public function hasActivatedContactUser(?DateTime $dateTime = null): bool
    {
        return $this->getContactPersons($dateTime, true)->count() > 0;
    }

    public function getContactPersons(DateTime $dateTime = null, bool $onlyActivated = false): Collection
    {
        return $this->getContact() ? $this->getContact()->getContactPersons($dateTime, $onlyActivated) : new ArrayCollection();
    }

    /**
     * Sort collection of event participants by name (and id).
     *
     * @param Collection $eventParticipants
     *
     * @return Collection
     */
    public static function sortCollection(Collection $eventParticipants): Collection
    {
        $participants = $eventParticipants->toArray();
        self::sortArray($participants);

        return new ArrayCollection($participants);
    }

    /**
     * Sort array of event participants by name (and id).
     *
     * @param array $eventParticipants
     */
    public static function sortArray(array &$eventParticipants): void
    {
        usort(
            $eventParticipants,
            static function (Participant $arg1, Participant $arg2) {
                if (!$arg1->getContact() || !$arg2->getContact()) {
                    $cmpResult = 0;
                } else {
                    $cmpResult = strcmp(
                        $arg1->getContact()->getSortableName(),
                        $arg2->getContact()->getSortableName()
                    );
                }

                return $cmpResult === 0 ? AbstractRevision::cmpId($arg2->getId(), $arg1->getId()) : $cmpResult;
            }
        );
    }

    public function isDeleted(): bool
    {
        try {
            return !($this->getRange() && $this->getEvent() && $this->getParticipantType());
        } catch (OswisException $e) {
            return false;
        }
    }

    /**
     * Recognizes if participant must be addressed in a formal way.
     *
     * @param bool $recursive
     *
     * @return bool Participant must be addressed in a formal way.
     */
    public function isFormal(bool $recursive = false): ?bool
    {
        if ($recursive && null === $this->formal) {
            return $this->getParticipantType() ? $this->getParticipantType()->isFormal() : true;
        }

        return $this->formal;
    }

    public function setFormal(?bool $formal): void
    {
        $this->formal = $formal;
    }

    /**
     * Checks if participant is marked as manager (by one of management participant types).
     * @return bool
     */
    public function isManager(): bool
    {
        $type = $this->getParticipantType();

        return null !== $type ? in_array($type->getType(), ParticipantCategory::MANAGEMENT_TYPES, true) : false;
    }

    public function getName(): ?string
    {
        return null !== $this->getContact() ? $this->getContact()->getName() : null;
    }

    public function removeEmptyParticipantNotes(): void
    {
        $this->setNotes(
            $this->getNotes()->filter(fn(ParticipantNote $note): bool => !empty($note->getTextValue()))
        );
    }

    public function getNotes(): Collection
    {
        return $this->notes ??= new ArrayCollection();
    }

    public function setNotes(?Collection $notes): void
    {
        $this->notes = $notes ?? new ArrayCollection();
    }

    public function removeNote(?ParticipantNote $note): void
    {
        null !== $note && $this->notes->removeElement($note);
    }

    public function addNote(?ParticipantNote $note): void
    {
        if (null !== $note && !$this->getNotes()->contains($note)) {
            $this->getNotes()->add($note);
        }
    }

    public function addFlagCategory(?ParticipantFlagCategory $flagCategory): void
    {
        if (null !== $flagCategory && !$this->getFlagCategories()->contains($flagCategory)) {
            $this->getFlagCategories()->add($flagCategory);
        }
    }

    public function getFlagCategories(
        bool $onlyActive = false,
        bool $onlyDeleted = false,
        ?RegistrationFlagCategory $flagCategory = null,
        ?RegistrationFlag $flag = null,
        ?string $flagType = null
    ): Collection {
        $connections = $this->flagCategories ??= new ArrayCollection();
        if ($onlyActive) {
            $connections = $connections->filter(fn(ParticipantFlagCategory $connection) => $connection->isActive());
        }
        if ($onlyDeleted) {
            $connections = $connections->filter(fn(ParticipantFlagCategory $connection) => $connection->isDeleted());
        }
        if (null !== $flag) {
            $connections = $connections->filter(fn(ParticipantFlagCategory $connection) => $connection->getFlag() === $flag);
        }
        if (null !== $flagCategory) {
            $connections = $connections->filter(fn(ParticipantFlagCategory $connection) => $connection->getFlagType() === $flagCategory);
        }
        if (null !== $flagType) {
            $connections = $connections->filter(fn(ParticipantFlagCategory $connection) => $connection->getFlagTypeString() === $flagType);
        }

        return $connections;
    }

    public function setFlagCategories(?Collection $newFlagCategories): void
    {
        $this->flagCategories ??= new ArrayCollection();
        $newFlagCategories ??= new ArrayCollection();
        foreach ($this->getFlagCategories() as $oldFlagCategory) {
            if (!$newFlagCategories->contains($oldFlagCategory)) {
                $this->removeFlagCategory($oldFlagCategory);
            }
        }
        foreach ($newFlagCategories as $newFlagCategory) {
            if (!$this->getFlagCategories()->contains($newFlagCategory)) {
                $this->addFlagCategory($newFlagCategory);
            }
        }
    }

    public function removeFlagCategory(?ParticipantFlagCategory $flagCategory): void
    {
        if (null !== $flagCategory) {
            $flagCategory->delete();
            $this->getFlagCategories()->removeElement($flagCategory);
        }
    }

    /**
     * @return int
     * @throws OswisException
     * @throws PriceInvalidArgumentException
     */
    public function getRemainingRest(): int
    {
        return $this->getPriceRest() - $this->getPaidPrice() + $this->getDepositValue();
    }

    /**
     * Gets part of price that is not marked as deposit.
     * @return int
     * @throws OswisException
     * @throws PriceInvalidArgumentException
     */
    public function getPriceRest(): int
    {
        return $this->getPrice() - $this->getDepositValue();
    }

    /**
     * Get whole price of event for this participant (including flags price).
     * @return int
     * @throws OswisException
     * @throws PriceInvalidArgumentException
     */
    public function getPrice(): int
    {
        if (null === $this->getRange()) {
            throw new PriceInvalidArgumentException(' (událost nezadána)');
        }
        if (null === $this->getParticipantType()) {
            throw new PriceInvalidArgumentException(' (typ uživatele nezadán)');
        }
        $price = $this->getRange()->getPrice($this->getParticipantType()) + $this->getFlagsPrice();

        return $price < 0 ? 0 : $price;
    }

    public function getFlagsPrice(?RegistrationFlagCategory $flagType = null, bool $onlyActive = true): int
    {
        $price = 0;
        foreach ($this->getFlagCategories($onlyActive, false, $flagType) as $flagConnection) {
            $price += $flagConnection instanceof ParticipantFlagCategory ? $flagConnection->getPrice() : 0;
        }

        return $price;
    }

    /**
     * Gets part of price that is marked as deposit.
     * @return int
     * @throws OswisException
     * @throws PriceInvalidArgumentException
     */
    public function getDepositValue(): ?int
    {
        if (null === $this->getRange() || null === $this->getParticipantType()) {
            throw new PriceInvalidArgumentException();
        }
        $price = $this->getRange()->getDepositValue($this->getParticipantType()) + $this->getFlagsDepositValue();

        return $price < 0 ? 0 : $price;
    }

    public function getFlagsDepositValue(?RegistrationFlagCategory $flagType = null, bool $onlyActive = true): int
    {
        $price = 0;
        foreach ($this->getFlagCategories($onlyActive, false, $flagType) as $flagConnection) {
            $price += $flagConnection instanceof ParticipantFlagCategory ? $flagConnection->getDepositValue() : 0;
        }

        return $price;
    }

    /**
     * Gets part of price that was already paid.
     * @return int
     */
    public function getPaidPrice(): int
    {
        $paid = 0;
        foreach ($this->getPayments() as $eventParticipantPayment) {
            $paid += $eventParticipantPayment instanceof ParticipantPayment ? $eventParticipantPayment->getNumericValue() : 0;
        }

        return $paid;
    }

    public function getPayments(): Collection
    {
        return $this->payments ??= new ArrayCollection();
    }

    public function setPayments(?Collection $newParticipantPayments): void
    {
        $this->payments ??= new ArrayCollection();
        $newParticipantPayments ??= new ArrayCollection();
        foreach ($this->getPayments() as $oldPayment) {
            if (!$newParticipantPayments->contains($oldPayment)) {
                $this->removePayment($oldPayment);
            }
        }
        foreach ($newParticipantPayments as $newPayment) {
            if (!$this->getPayments()->contains($newPayment)) {
                $this->addPayment($newPayment);
            }
        }
    }

    /**
     * Checks if participant contains given flag.
     *
     * @param RegistrationFlag $flag
     * @param bool             $onlyActive
     *
     * @return bool
     */
    public function hasFlag(RegistrationFlag $flag, bool $onlyActive = true): bool
    {
        return $this->getFlags(null, $onlyActive)->exists(
            fn(RegistrationFlag $oneFlag) => $flag->getId() === $oneFlag->getId()
        );
    }

    public function getFlags(
        ?RegistrationFlagCategory $flagCategory = null,
        RegistrationFlag $flag = null,
        ?string $flagType = null,
        bool $onlyActive = true
    ): Collection {
        $participantFlags = new ArrayCollection();
        foreach ($this->getFlagCategories($onlyActive, false, $flagCategory, $flag, $flagType) as $flagCategory) {
            if (!($flagCategory instanceof ParticipantFlagCategory)) {
                continue;
            }
            $flagCategory->get
        }


        return $this->getFlagRanges($flagCategory, $onlyActive)->map(fn(RegistrationsFlagRange $range) => $range->getFlag());
    }

    public function getFlagRanges(?RegistrationFlagCategory $flagType = null, bool $onlyActive = true): Collection
    {
        return $this->getFlagCategories($onlyActive, false, $flagType)->map(
            fn(ParticipantFlagCategory $connection) => $connection->getFlagCategoryRange()
        );
    }

    /**
     * Checks if participant has some flag of given type (given by type string).
     *
     * @param string|null $flagType
     * @param bool        $onlyActive
     *
     * @return bool Participant contains some flag of given type.
     */
    public function hasFlagOfType(?string $flagType, bool $onlyActive = true): bool
    {
        return $this->getFlags(null, $onlyActive)->exists(
            fn(RegistrationFlag $f) => $flagType && $f->getTypeOfType() === $flagType
        );
    }

    /**
     * Gets price remains to be paid.
     * @return int
     * @throws OswisException
     * @throws PriceInvalidArgumentException
     */
    public function getRemainingPrice(): int
    {
        return $this->getPrice() - $this->getPaidPrice();
    }

    /**
     * Gets price deposit that remains to be paid.
     * @return int
     * @throws OswisException
     * @throws PriceInvalidArgumentException
     */
    public function getRemainingDeposit(): int
    {
        $remaining = null !== $this->getDepositValue() ? $this->getDepositValue() - $this->getPaidPrice() : 0;

        return $remaining > 0 ? $remaining : 0;
    }

    /**
     * Gets percentage of price paid (as float).
     * @return float
     * @throws OswisException
     * @throws PriceInvalidArgumentException
     */
    public function getPaidPricePercentage(): float
    {
        return $this->getPaidPrice() / $this->getPrice();
    }

    public function removePayment(?ParticipantPayment $participantPayment): void
    {
        if ($participantPayment && $this->payments->removeElement($participantPayment)) {
            $participantPayment->setParticipant(null);
        }
    }

    public function addPayment(?ParticipantPayment $participantPayment): void
    {
        if ($participantPayment && !$this->getPayments()->contains($participantPayment)) {
            $this->getPayments()->add($participantPayment);
            $participantPayment->setParticipant($this);
        }
    }

    /**
     * Get variable symbol of this eventParticipant (default is cropped phone number or ID).
     */
    public function getVariableSymbol(): ?string
    {
        $phone = $this->getContact() ? $this->getContact()->getPhone() : null;
        $symbol = preg_replace('/\s/', '', $phone);
        $symbol = substr(trim($symbol), strlen(trim($symbol)) - 9, 9);

        return empty($symbol) ? ''.$this->getId() : $symbol;
    }

    /**
     * Gets array of flags aggregated by their types.
     * @return array
     */
    public function getFlagsAggregatedByType(): array
    {
        return FlagsByType::getFlagsAggregatedByType($this->getFlags());
    }
}
