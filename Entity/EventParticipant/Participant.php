<?php
/**
 * @noinspection RedundantDocCommentTagInspection
 * @noinspection PhpUnused
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Entity\EventParticipant;

use ApiPlatform\Core\Annotation\ApiFilter;
use ApiPlatform\Core\Annotation\ApiResource;
use ApiPlatform\Core\Bridge\Doctrine\Orm\Filter\ExistsFilter;
use ApiPlatform\Core\Bridge\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Core\Bridge\Doctrine\Orm\Filter\SearchFilter;
use DateTime;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Exception;
use OswisOrg\OswisAddressBookBundle\Entity\AbstractClass\AbstractContact;
use OswisOrg\OswisCalendarBundle\Entity\Event\Event;
use OswisOrg\OswisCalendarBundle\Entity\Event\RegistrationsRange;
use OswisOrg\OswisCalendarBundle\Exception\EventCapacityExceededException;
use OswisOrg\OswisCoreBundle\Entity\Revisions\AbstractRevision;
use OswisOrg\OswisCoreBundle\Exceptions\PriceInvalidArgumentException;
use OswisOrg\OswisCoreBundle\Filter\SearchAnnotation as Searchable;
use OswisOrg\OswisCoreBundle\Interfaces\Common\BasicInterface;
use OswisOrg\OswisCoreBundle\Traits\Common\BasicMailConfirmationTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\BasicTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\DeletedTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\InfoMailSentTrait;
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
 * @ApiFilter(OrderFilter::class, properties={
 *     "id": "ASC",
 *     "createdDateTime",
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
 *     "contact.sortableName",
 *     "contact.contactDetails.content",
 *     "eventParticipantFlagConnections.eventParticipantFlag.name",
 *     "eventParticipantFlagConnections.eventParticipantFlag.shortName",
 *     "eventParticipantFlagConnections.eventParticipantFlag.slug"
 * })
 * @ApiFilter(SearchFilter::class, properties={
 *     "id": "iexact",
 *     "id": "iexact",
 *     "name": "ipartial",
 *     "shortName": "ipartial",
 *     "slug": "ipartial",
 *     "color": "ipartial",
 *     "startDateTime": "ipartial",
 *     "endDateTime": "ipartial",
 *     "event.type.id": "iexact",
 *     "event.type.name": "ipartial",
 *     "event.type.shortName": "ipartial",
 *     "event.type.slug": "ipartial",
 *     "event.type.color": "ipartial",
 *     "contact.id": "iexact",
 *     "contact.contactName": "ipartial",
 *     "contact.contactDetails.content": "ipartial",
 *     "eventParticipantFlagConnections.eventParticipantFlag.name": "ipartial",
 *     "eventParticipantFlagConnections.eventParticipantFlag.shortName": "ipartial",
 *     "eventParticipantFlagConnections.eventParticipantFlag.slug": "ipartial"
 * })
 * @ApiFilter(ExistsFilter::class, properties={"deleted"})
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
    use DeletedTrait;
    use BasicMailConfirmationTrait;
    use InfoMailSentTrait;
    use PriorityTrait;

    /**
     * @Doctrine\ORM\Mapping\ManyToMany(
     *     targetEntity="OswisOrg\OswisCalendarBundle\Entity\EventParticipant\ParticipantNote",
     *     cascade={"all"},
     *     fetch="EAGER"
     * )
     * @Doctrine\ORM\Mapping\JoinTable(
     *     name="calendar_participant_note_connection",
     *     joinColumns={@Doctrine\ORM\Mapping\JoinColumn(name="participant_id", referencedColumnName="id")},
     *     inverseJoinColumns={@Doctrine\ORM\Mapping\JoinColumn(name="participant_note_id", referencedColumnName="id", unique=true)}
     * )
     */
    protected ?Collection $participantNotes = null;

    /**
     * @Doctrine\ORM\Mapping\OneToMany(
     *     targetEntity="OswisOrg\OswisCalendarBundle\Entity\EventParticipant\ParticipantPayment",
     *     cascade={"all"},
     *     mappedBy="participant",
     *     fetch="EAGER"
     * )
     * @MaxDepth(1)
     */
    protected ?Collection $participantPayments = null;

    /**
     * @Doctrine\ORM\Mapping\OneToMany(
     *     targetEntity="OswisOrg\OswisCalendarBundle\Entity\EventParticipant\ParticipantFlagConnection",
     *     cascade={"all"},
     *     mappedBy="participant",
     *     fetch="EAGER"
     * )
     */
    protected ?Collection $participantFlagConnections = null;

    /**
     * Related contact (person or organization).
     * @Doctrine\ORM\Mapping\ManyToOne(
     *     targetEntity="OswisOrg\OswisAddressBookBundle\Entity\AbstractClass\AbstractContact",
     *     cascade={"all"},
     *     fetch="EAGER"
     * )
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     */
    protected ?AbstractContact $contact = null;

    /**
     * @Doctrine\ORM\Mapping\ManyToOne(
     *     targetEntity="OswisOrg\OswisCalendarBundle\Entity\Event\RegistrationsRange",
     *     fetch="EAGER"
     * )
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     */
    protected ?RegistrationsRange $registrationsRange = null;

    /**
     * @Doctrine\ORM\Mapping\Column(type="string", nullable=true)
     */
    protected ?bool $formal = null;

    /**
     * @param AbstractContact|null    $contact
     * @param RegistrationsRange|null $registrationsRange
     * @param Collection|null         $participantFlagConnections
     * @param Collection|null         $participantNotes
     * @param DateTime|null           $deleted
     * @param int|null                $priority
     *
     * @throws EventCapacityExceededException
     */
    public function __construct(
        ?AbstractContact $contact = null,
        ?RegistrationsRange $registrationsRange = null,
        ?Collection $participantFlagConnections = null,
        ?Collection $participantNotes = null,
        ?DateTime $deleted = null,
        ?int $priority = null
    ) {
        $this->setContact($contact);
        $this->setRegistrationsRange($registrationsRange);
        $this->setParticipantNotes($participantNotes);
        $this->setParticipantPayments(new ArrayCollection());
        $this->setParticipantFlagConnections($participantFlagConnections);
        $this->setDeleted($deleted);
        $this->setPriority($priority);
    }

    public static function filterCollection(Collection $participants, ?bool $includeNotActivated = true): Collection
    {
        $resultCollection = new ArrayCollection();
        foreach ($participants as $newEventParticipant) {
            assert($newEventParticipant instanceof self);
            if (!$includeNotActivated && !$newEventParticipant->hasActivatedContactUser()) {
                continue;
            }
            if (!$resultCollection->contains($newEventParticipant)) {
                $resultCollection->add($newEventParticipant);
            }
        }

        return $resultCollection;
    }

    /**
     * Checks if there is some activated user assigned to this participant.
     *
     * @param DateTime|null $referenceDateTime
     *
     * @return bool
     */
    public function hasActivatedContactUser(?DateTime $referenceDateTime = null): bool
    {
        try {
            return $this->getContact() && $this->getContact()->getContactPersons($referenceDateTime, true)->count() > 0;
        } catch (Exception $e) {
            return false;
        }
    }

    public function getContact(): ?AbstractContact
    {
        return $this->contact;
    }

    public function setContact(?AbstractContact $contact): void
    {
        $this->contact = $contact;
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

    public function getParticipantType(): ?ParticipantType
    {
        return $this->getRegistrationsRange() ? $this->getRegistrationsRange()->getParticipantType() : null;
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

    /**
     * Get participants name from assigned contact.
     * @return string|null
     */
    public function getName(): ?string
    {
        return null !== $this->getContact() ? $this->getContact()->getName() : null;
    }

    /**
     * @param ParticipantFlagConnection|null $newConnection
     *
     * @throws EventCapacityExceededException
     */
    public function addParticipantFlagConnection(?ParticipantFlagConnection $newConnection): void
    {
        if (null !== $newConnection && !$this->participantFlagConnections->contains($newConnection)) {
            $this->participantFlagConnections->add($newConnection);
            $newConnection->setParticipant($this);
        }
    }

    /**
     * @param ParticipantFlagConnection|null $participantFlagConnection
     *
     * @throws EventCapacityExceededException
     */
    public function removeParticipantFlagConnection(?ParticipantFlagConnection $participantFlagConnection): void
    {
        if ($participantFlagConnection && $this->participantFlagConnections->removeElement($participantFlagConnection)) {
            $participantFlagConnection->setParticipant(null);
        }
    }

    public function removeEmptyParticipantNotes(): void
    {
        $this->setParticipantNotes(
            $this->getParticipantNotes()->filter(fn(ParticipantNote $note): bool => !empty($note->getTextValue()))
        );
    }

    public function getParticipantNotes(): Collection
    {
        return $this->participantNotes ?? new ArrayCollection();
    }

    public function setParticipantNotes(?Collection $newEventParticipantNotes): void
    {
        $this->participantNotes = $newEventParticipantNotes ?? new ArrayCollection();
    }

    public function removeParticipantNote(?ParticipantNote $participantNote): void
    {
        if ($participantNote) {
            $this->participantNotes->removeElement($participantNote);
        }
    }

    public function addParticipantNote(?ParticipantNote $participantNote): void
    {
        if ($participantNote && !$this->participantNotes->contains($participantNote)) {
            $this->participantNotes->add($participantNote);
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
        if (null === $this->getRegistrationsRange()) {
            throw new PriceInvalidArgumentException(' (událost nezadána)');
        }
        if (null === $this->getParticipantType()) {
            throw new PriceInvalidArgumentException(' (typ uživatele nezadán)');
        }
        $price = $this->getRegistrationsRange()->getPrice() + $this->getFlagsPrice();

        return $price < 0 ? 0 : $price;
    }

    public function getRegistrationsRange(): ?RegistrationsRange
    {
        return $this->registrationsRange;
    }

    public function setRegistrationsRange(?RegistrationsRange $registrationsRange): void
    {
        $this->registrationsRange = $registrationsRange;
    }

    public function getEvent(): ?Event
    {
        return $this->getRegistrationsRange() ? $this->getRegistrationsRange()->getEvent() : null;
    }

    public function getFlagsPrice(?ParticipantFlagType $eventParticipantFlagType = null, bool $onlyActive = true): int
    {
        $price = 0;
        foreach ($this->getParticipantFlagConnections($eventParticipantFlagType, $onlyActive) as $flagConnection) {
            $price += $flagConnection instanceof ParticipantFlagConnection ? $flagConnection->getPrice() : 0;
        }

        return $price;
    }

    public function getFlagsDepositValue(?ParticipantFlagType $eventParticipantFlagType = null, bool $onlyActive = true): int
    {
        $price = 0;
        foreach ($this->getParticipantFlagConnections($eventParticipantFlagType, $onlyActive) as $flagConnection) {
            $price += $flagConnection instanceof ParticipantFlagConnection ? $flagConnection->getDepositValue() : 0;
        }

        return $price;
    }

    public function getParticipantFlags(?ParticipantFlagType $participantFlagType = null, bool $onlyActive = false): Collection
    {
        return $this->getParticipantFlagConnections($participantFlagType, $onlyActive)->map(
            fn(ParticipantFlagConnection $connection) => $connection->getParticipantFlagRange()
        );
    }

    public function getParticipantFlagConnections(?ParticipantFlagType $participantFlagType = null, ?bool $onlyActive = false): Collection
    {
        $connections = $this->participantFlagConnections ?? new ArrayCollection();
        if (null !== $participantFlagType) {
            $connections = $connections->filter(
                static function (ParticipantFlagConnection $connection) use ($participantFlagType) {
                    $flagRange = $connection->getParticipantFlagRange();
                    $flag = $flagRange ? $flagRange->getFlag() : null;
                    $type = $flag ? $flag->getFlagType() : null;

                    return null !== $type && $type->getId() === $participantFlagType->getId();
                }
            );
        }
        if ($onlyActive) {
            $connections = $connections->filter(
                fn(ParticipantFlagConnection $conn) => $conn instanceof ParticipantFlagConnection && $conn->isActive()
            );
        }

        return $connections;
    }

    /**
     * @param Collection|null $newConnections
     *
     * @throws EventCapacityExceededException
     */
    public function setParticipantFlagConnections(?Collection $newConnections): void
    {
        $this->participantFlagConnections ??= new ArrayCollection();
        $newConnections ??= new ArrayCollection();
        foreach ($this->participantFlagConnections as $oldConnection) {
            if (!$newConnections->contains($oldConnection)) {
                $this->removeParticipantFlagConnection($oldConnection);
            }
        }
        foreach ($newConnections as $newConnection) {
            if (!$this->participantFlagConnections->contains($newConnection)) {
                $this->addParticipantFlagConnection($newConnection);
            }
        }
    }

    /**
     * Gets part of price that is marked as deposit.
     * @return int
     * @throws PriceInvalidArgumentException
     */
    public function getDepositValue(): ?int
    {
        if (!$this->getRegistrationsRange() || !$this->getParticipantType()) {
            throw new PriceInvalidArgumentException();
        }
        $price = $this->getRegistrationsRange()->getDepositValue() + $this->getFlagsDepositValue();

        return $price < 0 ? 0 : $price;
    }

    /**
     * Gets part of price that was already paid.
     * @return int
     */
    public function getPaidPrice(): int
    {
        $paid = 0;
        foreach ($this->getParticipantPayments() as $eventParticipantPayment) {
            $paid += $eventParticipantPayment instanceof ParticipantPayment ? $eventParticipantPayment->getNumericValue() : 0;
        }

        return $paid;
    }

    public function getParticipantPayments(): ?Collection
    {
        return $this->participantPayments ?? new ArrayCollection();
    }

    public function setParticipantPayments(?Collection $newEventParticipantPayments): void
    {
        $this->participantPayments = $this->participantPayments ?? new ArrayCollection();
        $newEventParticipantPayments = $newEventParticipantPayments ?? new ArrayCollection();
        foreach ($this->participantPayments as $oldPayment) {
            if (!$newEventParticipantPayments->contains($oldPayment)) {
                $this->removeParticipantPayment($oldPayment);
            }
        }
        foreach ($newEventParticipantPayments as $newPayment) {
            if (!$this->participantPayments->contains($newPayment)) {
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
        return $this->getParticipantFlags(null, $onlyActive)->exists(
            fn(ParticipantFlag $oneFlag) => $flag->getId() === $oneFlag->getId()
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
    public function hasFlagOfTypeOfType(?string $flagType, bool $onlyActive = true): bool
    {
        return $this->getParticipantFlags(null, $onlyActive)->exists(
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
        if ($participantPayment && $this->participantPayments->removeElement($participantPayment)) {
            $participantPayment->setParticipant(null);
        }
    }

    public function addParticipantPayment(?ParticipantPayment $participantPayment): void
    {
        if ($participantPayment && !$this->participantPayments->contains($participantPayment)) {
            $this->participantPayments->add($participantPayment);
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
        $flags = [];
        foreach ($this->getParticipantFlags() as $flag) {
            if ($flag instanceof ParticipantFlag) {
                $flagTypeSlug = $flag->getFlagType() ? $flag->getFlagType()->getSlug() : '0';
                $flags[$flagTypeSlug] ??= [];
                $flags[$flagTypeSlug][] = $flag;
            }
        }

        return $flags;
    }

    public function destroyRevisions(): void
    {
    }
}
