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
use OswisOrg\OswisCalendarBundle\Entity\Event\RegistrationsRange;
use OswisOrg\OswisCalendarBundle\Entity\NonPersistent\FlagsAggregatedByType;
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
     * @Doctrine\ORM\Mapping\ManyToOne(targetEntity="OswisOrg\OswisCalendarBundle\Entity\Event\RegistrationsRange", fetch="EAGER")
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     */
    protected ?RegistrationsRange $range = null;

    /**
     * @Doctrine\ORM\Mapping\ManyToOne(targetEntity="OswisOrg\OswisCalendarBundle\Entity\Event\Event", fetch="EAGER")
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     */
    protected ?Event $event = null;

    /**
     * @Doctrine\ORM\Mapping\ManyToMany(
     *     targetEntity="OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantFlagRangeConnection", cascade={"all"}, fetch="EAGER"
     * )
     * @Doctrine\ORM\Mapping\JoinTable(
     *     name="calendar_participant_flag_range_connection_connection",
     *     joinColumns={@Doctrine\ORM\Mapping\JoinColumn(name="participant_id", referencedColumnName="id")},
     *     inverseJoinColumns={@Doctrine\ORM\Mapping\JoinColumn(name="participant_flag_range_id", referencedColumnName="id", unique=true)}
     * )
     */
    protected ?Collection $flagRangeConnections = null;

    /**
     * @Doctrine\ORM\Mapping\ManyToMany(
     *     targetEntity="OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantRangeConnection", cascade={"all"}, fetch="EAGER"
     * )
     * @Doctrine\ORM\Mapping\JoinTable(
     *     name="calendar_participant_range_connection_connection",
     *     joinColumns={@Doctrine\ORM\Mapping\JoinColumn(name="participant_id", referencedColumnName="id")},
     *     inverseJoinColumns={@Doctrine\ORM\Mapping\JoinColumn(name="participant_range_id", referencedColumnName="id", unique=true)}
     * )
     */
    protected ?Collection $rangeConnections = null;

    /**
     * @Doctrine\ORM\Mapping\ManyToMany(
     *     targetEntity="OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantContactConnection", cascade={"all"}, fetch="EAGER"
     * )
     * @Doctrine\ORM\Mapping\JoinTable(
     *     name="calendar_participant_contact_connection_connection",
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
     * @param ParticipantContactConnection|null $contactConnection
     * @param ParticipantRangeConnection|null   $rangeConnection
     * @param Collection|null                   $flagConnections
     * @param Collection|null                   $participantNotes
     * @param int|null                          $priority
     *
     * @throws OswisException
     */
    public function __construct(
        ParticipantContactConnection $contactConnection = null,
        ParticipantRangeConnection $rangeConnection = null,
        ?Collection $flagConnections = null,
        ?Collection $participantNotes = null,
        ?int $priority = null
    ) {
        $this->setContactConnection($contactConnection);
        $this->setRangeConnection($rangeConnection);
        $this->setNotes($participantNotes);
        $this->setPayments(new ArrayCollection());
        $this->setFlagRangeConnections($flagConnections);
        $this->setPriority($priority);
    }

    /**
     * @param ParticipantContactConnection|null $contactConnection
     *
     * @throws OswisException
     */
    public function setContactConnection(?ParticipantContactConnection $contactConnection): void
    {
        if ($this->getContactConnection() !== $contactConnection) {
            foreach ($this->getContactConnections(true) as $connection) {
                if ($connection instanceof ParticipantContactConnection) {
                    $connection->delete();
                    $this->contactConnections->remove($connection);
                }
            }
            if (!$this->contactConnections->contains($contactConnection)) {
                $this->contactConnections->add($contactConnection);
            }
            $this->updateCachedColumns();
        }
    }

    /**
     * @return ParticipantContactConnection
     * @throws OswisException
     */
    public function getContactConnection(): ?ParticipantContactConnection
    {
        $connections = $this->getContactConnections(true);
        if ($connections->count() > 1) {
            throw new OswisException('Účastník je přiřazen k více událostem najednou.');
        }

        return $connections->first() ?: null;
    }

    public function getContactConnections(bool $onlyActive = false, bool $onlyDeleted = false): Collection
    {
        $connections = $this->contactConnections ?? new ArrayCollection();
        if ($onlyActive) {
            $connections = $connections->filter(fn(ParticipantContactConnection $connection) => $connection->isActive());
        }
        if ($onlyDeleted) {
            $connections = $connections->filter(fn(ParticipantContactConnection $connection) => $connection->isDeleted());
        }

        return $connections;
    }

    public function updateCachedColumns(): void
    {
        try {
            $this->range = $this->getRangeConnection() ? $this->getRangeConnection()->getRange() : null;
            $this->contact = $this->getContactConnection() ? $this->getContactConnection()->getContact() : null;
        } catch (OswisException $e) {
        }
    }

    /**
     * @return ParticipantRangeConnection
     * @throws OswisException
     */
    public function getRangeConnection(): ?ParticipantRangeConnection
    {
        $connections = $this->getRangeConnections(true, false);
        if ($connections->count() > 1) {
            throw new OswisException('Účastník je přiřazen k více událostem najednou.');
        }

        return $connections->first();
    }

    public function getRangeConnections(bool $onlyActive = false, bool $onlyDeleted = false): Collection
    {
        $connections = $this->rangeConnections ?? new ArrayCollection();
        if ($onlyActive) {
            $connections = $connections->filter(fn(ParticipantRangeConnection $connection) => $connection->isActive());
        }
        if ($onlyDeleted) {
            $connections = $connections->filter(fn(ParticipantRangeConnection $connection) => $connection->isDeleted());
        }

        return $connections;
    }

    /**
     * @param ParticipantRangeConnection|null $newRangeConnection
     *
     * @throws OswisException
     */
    public function setRangeConnection(?ParticipantRangeConnection $newRangeConnection): void
    {
        $oldRangeConnection = $this->getRangeConnection();
        $newRange = $newRangeConnection ? $newRangeConnection->getRange() : null;
        if ($oldRangeConnection !== $newRangeConnection) {
            foreach ($this->getRangeConnections(true) as $connection) {
                if ($connection instanceof ParticipantRangeConnection) {
                    $connection->delete();
                    $this->rangeConnections->remove($connection);
                }
            }
            try {
                if ($newRange) {
                    $newRange->simulateAdd($this);
                }
                $this->rangeConnections->add($newRangeConnection);
            } catch (EventCapacityExceededException $e) {
                $this->rangeConnections->add($oldRangeConnection);
            }
        }
        $this->updateCachedColumns();
    }

    public static function filterCollection(Collection $participants, ?bool $includeNotActivated = true): Collection
    {
        $resultCollection = new ArrayCollection();
        foreach ($participants as $newParticipant) {
            assert($newParticipant instanceof self);
            if (!$includeNotActivated && !$newParticipant->hasActivatedContactUser()) {
                continue;
            }
            if (!$resultCollection->contains($newParticipant)) {
                $resultCollection->add($newParticipant);
            }
        }

        return $resultCollection;
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
        return $this->getContactPersons($dateTime)->count() > 0;
    }

    public function getContactPersons(DateTime $dateTime = null): Collection
    {
        return $this->getContact() ? $this->getContact()->getContactPersons($dateTime, true) : new ArrayCollection();
    }

    public function getContact(): ?AbstractContact
    {
        return $this->contact;
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
        return !($this->getRange() && $this->getEvent() && $this->getParticipantType());
    }

    public function getRange(): ?RegistrationsRange
    {
        return $this->range;
    }

    /**
     * @param RegistrationsRange|null $range
     *
     * @throws OswisException
     */
    public function setRange(?RegistrationsRange $range): void
    {
        if ($this->getRange() !== $range) {
            $this->setRangeConnection(new ParticipantRangeConnection($range));
        }
    }

    public function getEvent(): ?Event
    {
        return $this->getRange() ? $this->getRange()->getEvent() : null;
    }

    public function getParticipantType(): ?ParticipantType
    {
        return $this->getRange() ? $this->getRange()->getParticipantType() : null;
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

        return null !== $type ? in_array($type->getType(), ParticipantType::MANAGEMENT_TYPES, true) : false;
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
        return $this->notes ?? new ArrayCollection();
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
        if (null !== $note && !$this->notes->contains($note)) {
            $this->notes->add($note);
        }
    }

    public function addFlagRangeConnection(?ParticipantFlagRangeConnection $flagRangeConnection): void
    {
        if (null !== $flagRangeConnection) {
            foreach ($this->getFlagRangeConnections(true) as $connection) {
                $this->removeFlagRangeConnection($connection);
            }
            if (!$this->flagRangeConnections->contains($flagRangeConnection)) {
                $this->flagRangeConnections->add($flagRangeConnection);
            }
        }
    }

    public function getFlagRangeConnections(
        bool $onlyActive = false,
        bool $onlyDeleted = false,
        ?ParticipantFlagType $flagType = null,
        ParticipantFlag $flag = null,
        ?string $flagTypeString = null
    ): Collection {
        $connections = $this->flagRangeConnections ?? new ArrayCollection();
        if ($onlyActive) {
            $connections = $connections->filter(fn(ParticipantFlagRangeConnection $connection) => $connection->isActive());
        }
        if ($onlyDeleted) {
            $connections = $connections->filter(fn(ParticipantFlagRangeConnection $connection) => $connection->isDeleted());
        }
        if (null !== $flag) {
            $connections = $connections->filter(fn(ParticipantFlagRangeConnection $connection) => $connection->getFlag() === $flag);
        }
        if (null !== $flagType) {
            $connections = $connections->filter(fn(ParticipantFlagRangeConnection $connection) => $connection->getFlagType() === $flagType);
        }
        if (null !== $flagTypeString) {
            $connections = $connections->filter(fn(ParticipantFlagRangeConnection $connection) => $connection->getFlagTypeString() === $flagTypeString);
        }

        return $connections;
    }

    public function setFlagRangeConnections(?Collection $newConnections): void
    {
        $this->flagRangeConnections ??= new ArrayCollection();
        $newConnections ??= new ArrayCollection();
        foreach ($this->flagRangeConnections as $oldConnection) {
            if (!$newConnections->contains($oldConnection)) {
                $this->removeFlagRangeConnection($oldConnection);
            }
        }
        foreach ($newConnections as $newConnection) {
            if (!$this->flagRangeConnections->contains($newConnection)) {
                $this->addFlagRangeConnection($newConnection);
            }
        }
    }

    public function removeFlagRangeConnection(?ParticipantFlagRangeConnection $flagRangeConnection): void
    {
        if (null !== $flagRangeConnection) {
            try {
                $flagRangeConnection->delete();
            } catch (Exception $e) {
            }
            $this->flagRangeConnections->removeElement($flagRangeConnection);
        }
    }

    /**
     * @return int
     * @throws PriceInvalidArgumentException
     */
    public function getRemainingRest(): int
    {
        return $this->getPriceRest() - $this->getPaidPrice() + $this->getDepositValue();
    }

    /**
     * Gets part of price that is not marked as deposit.
     * @return int
     * @throws PriceInvalidArgumentException
     */
    public function getPriceRest(): int
    {
        return $this->getPrice() - $this->getDepositValue();
    }

    /**
     * Get whole price of event for this participant (including flags price).
     * @return int
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

    public function getFlagsPrice(?ParticipantFlagType $flagType = null, bool $onlyActive = true): int
    {
        $price = 0;
        foreach ($this->getFlagRangeConnections($onlyActive, false, $flagType) as $flagConnection) {
            $price += $flagConnection instanceof ParticipantFlagRangeConnection ? $flagConnection->getPrice() : 0;
        }

        return $price;
    }

    /**
     * Gets part of price that is marked as deposit.
     * @return int
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

    public function getFlagsDepositValue(?ParticipantFlagType $flagType = null, bool $onlyActive = true): int
    {
        $price = 0;
        foreach ($this->getFlagRangeConnections($onlyActive, false, $flagType) as $flagConnection) {
            $price += $flagConnection instanceof ParticipantFlagRangeConnection ? $flagConnection->getDepositValue() : 0;
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

    public function getPayments(): ?Collection
    {
        return $this->payments ?? new ArrayCollection();
    }

    public function setPayments(?Collection $newParticipantPayments): void
    {
        $this->payments = $this->payments ?? new ArrayCollection();
        $newParticipantPayments = $newParticipantPayments ?? new ArrayCollection();
        foreach ($this->payments as $oldPayment) {
            if (!$newParticipantPayments->contains($oldPayment)) {
                $this->removeParticipantPayment($oldPayment);
            }
        }
        foreach ($newParticipantPayments as $newPayment) {
            if (!$this->payments->contains($newPayment)) {
                $this->addParticipantPayment($newPayment);
            }
        }
    }

    /**
     * Checks if participant contains given flag.
     *
     * @param ParticipantFlag $flag
     * @param bool            $onlyActive
     *
     * @return bool
     */
    public function hasFlag(ParticipantFlag $flag, bool $onlyActive = true): bool
    {
        return $this->getFlags(null, $onlyActive)->exists(
            fn(ParticipantFlag $oneFlag) => $flag->getId() === $oneFlag->getId()
        );
    }

    public function getFlags(?ParticipantFlagType $flagType = null, bool $onlyActive = true): Collection
    {
        return $this->getFlagRanges($flagType, $onlyActive)->map(fn(ParticipantFlagRange $range) => $range->getFlag());
    }

    public function getFlagRanges(?ParticipantFlagType $flagType = null, bool $onlyActive = true): Collection
    {
        return $this->getFlagRangeConnections($onlyActive, false, $flagType)->map(
            fn(ParticipantFlagRangeConnection $connection) => $connection->getFlagRange()
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
    public function hasFlagOfTypeString(?string $flagType, bool $onlyActive = true): bool
    {
        return $this->getFlags(null, $onlyActive)->exists(
            fn(ParticipantFlag $f) => $flagType && $f->getTypeOfType() === $flagType
        );
    }

    /**
     * Gets price remains to be paid.
     * @return int
     * @throws PriceInvalidArgumentException
     */
    public function getRemainingPrice(): int
    {
        return $this->getPrice() - $this->getPaidPrice();
    }

    /**
     * Gets price deposit that remains to be paid.
     * @return int
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
     * @throws PriceInvalidArgumentException
     */
    public function getPaidPricePercentage(): float
    {
        return $this->getPaidPrice() / $this->getPrice();
    }

    public function removeParticipantPayment(?ParticipantPayment $participantPayment): void
    {
        if ($participantPayment && $this->payments->removeElement($participantPayment)) {
            $participantPayment->setParticipant(null);
        }
    }

    public function addParticipantPayment(?ParticipantPayment $participantPayment): void
    {
        if ($participantPayment && !$this->payments->contains($participantPayment)) {
            $this->payments->add($participantPayment);
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
        return FlagsAggregatedByType::getFlagsAggregatedByType($this->getFlags());
    }
}
