<?php
/**
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Entity\Participant;

use DateTime;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use OswisOrg\OswisAddressBookBundle\Entity\AbstractClass\AbstractContact;
use OswisOrg\OswisCalendarBundle\Entity\Event\Event;
use OswisOrg\OswisCalendarBundle\Entity\NonPersistent\FlagsByType;
use OswisOrg\OswisCalendarBundle\Entity\Registration\Flag;
use OswisOrg\OswisCalendarBundle\Entity\Registration\FlagCategory;
use OswisOrg\OswisCalendarBundle\Entity\Registration\FlagGroupRange;
use OswisOrg\OswisCalendarBundle\Entity\Registration\RegRange;
use OswisOrg\OswisCalendarBundle\Exception\EventCapacityExceededException;
use OswisOrg\OswisCoreBundle\Entity\AppUser\AppUser;
use OswisOrg\OswisCoreBundle\Entity\Revisions\AbstractRevision;
use OswisOrg\OswisCoreBundle\Exceptions\NotImplementedException;
use OswisOrg\OswisCoreBundle\Exceptions\OswisException;
use OswisOrg\OswisCoreBundle\Exceptions\PriceInvalidArgumentException;
use OswisOrg\OswisCoreBundle\Interfaces\Common\BasicInterface;
use OswisOrg\OswisCoreBundle\Traits\Common\ActivatedTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\BasicMailConfirmationTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\BasicTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\DeletedTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\ManagerConfirmationTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\PriorityTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\UserConfirmationTrait;
use function assert;

/**
 * Participation of contact in event (attendee, sponsor, organizer, guest, partner...).
 *
 * @Doctrine\ORM\Mapping\Entity(repositoryClass="OswisOrg\OswisCalendarBundle\Repository\ParticipantRepository")
 * @Doctrine\ORM\Mapping\Table(name="calendar_participant")
 * @ApiPlatform\Core\Annotation\ApiResource(
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
 * @OswisOrg\OswisCoreBundle\Filter\SearchAnnotation({
 *     "id",
 *     "name",
 *     "shortName",
 *     "slug",
 *     "startDateTime",
 *     "endDateTime",
 *     "event.type.name",
 *     "event.type.shortName",
 *     "event.type.slug",
 *     "contact.contactName",
 *     "contact.contactDetails.content"
 * })
 * @Doctrine\ORM\Mapping\Cache(usage="NONSTRICT_READ_WRITE", region="calendar_participant")
 */
class Participant implements BasicInterface
{
    use BasicTrait;
    use PriorityTrait;
    use ActivatedTrait;
    use UserConfirmationTrait;
    use ManagerConfirmationTrait;
    use DeletedTrait;

    /**
     * @Doctrine\ORM\Mapping\OneToMany(
     *     targetEntity="OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantNote", cascade={"all"}, mappedBy="participant", fetch="EAGER"
     * )
     * @Symfony\Component\Serializer\Annotation\MaxDepth(1)
     */
    protected ?Collection $notes = null;

    /**
     * @Doctrine\ORM\Mapping\OneToMany(
     *     targetEntity="OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantPayment", cascade={"all"}, mappedBy="participant", fetch="EAGER"
     * )
     * @Symfony\Component\Serializer\Annotation\MaxDepth(1)
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
     * @Doctrine\ORM\Mapping\ManyToOne(targetEntity="OswisOrg\OswisCalendarBundle\Entity\Registration\RegRange", fetch="EAGER")
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     */
    protected ?RegRange $regRange = null;

    /**
     * @Doctrine\ORM\Mapping\ManyToOne(targetEntity="OswisOrg\OswisCalendarBundle\Entity\Event\Event", fetch="EAGER")
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     */
    protected ?Event $event = null;

    /**
     * @Doctrine\ORM\Mapping\ManyToOne(targetEntity="OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantCategory", fetch="EAGER")
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     */
    protected ?ParticipantCategory $participantCategory = null;

    /**
     * @Doctrine\ORM\Mapping\ManyToMany(
     *     targetEntity="OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantFlagGroup", cascade={"all"}, fetch="EAGER"
     * )
     * @Doctrine\ORM\Mapping\JoinTable(
     *     name="calendar_participant_flag_range_connection",
     *     joinColumns={@Doctrine\ORM\Mapping\JoinColumn(name="participant_id", referencedColumnName="id")},
     *     inverseJoinColumns={@Doctrine\ORM\Mapping\JoinColumn(name="participant_flag_category_id", referencedColumnName="id", unique=true)}
     * )
     */
    protected ?Collection $flagGroups = null;

    /**
     * @Doctrine\ORM\Mapping\ManyToMany(
     *     targetEntity="OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantRange", cascade={"all"}, fetch="EAGER"
     * )
     * @Doctrine\ORM\Mapping\JoinTable(
     *     name="calendar_participant_reg_range_connection",
     *     joinColumns={@Doctrine\ORM\Mapping\JoinColumn(name="participant_id", referencedColumnName="id")},
     *     inverseJoinColumns={@Doctrine\ORM\Mapping\JoinColumn(name="reg_range_id", referencedColumnName="id", unique=true)}
     * )
     */
    protected ?Collection $participantRanges = null;

    /**
     * @Doctrine\ORM\Mapping\ManyToMany(
     *     targetEntity="OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantContact", cascade={"all"}, fetch="EAGER"
     * )
     * @Doctrine\ORM\Mapping\JoinTable(
     *     name="calendar_participant_contact_connection"
     *     joinColumns={@Doctrine\ORM\Mapping\JoinColumn(name="participant_id", referencedColumnName="id")},
     *     inverseJoinColumns={@Doctrine\ORM\Mapping\JoinColumn(name="participant_contact_id", referencedColumnName="id", unique=true)}
     * )
     */
    protected ?Collection $participantContacts = null;

    /**
     * @Doctrine\ORM\Mapping\Column(type="string", nullable=true)
     */
    protected ?bool $formal = null;

    /**
     * @param RegRange|null        $regRange
     * @param AbstractContact|null $contact
     * @param Collection|null      $participantNotes
     * @param int|null             $priority
     *
     * @throws OswisException|EventCapacityExceededException
     */
    public function __construct(RegRange $regRange = null, AbstractContact $contact = null, ?Collection $participantNotes = null, ?int $priority = null)
    {
        $this->participantContacts = new ArrayCollection();
        $this->participantRanges = new ArrayCollection();
        $this->notes = new ArrayCollection();
        $this->flagGroups = new ArrayCollection();
        $this->payments = new ArrayCollection();
        $this->setRegRange($regRange);
        $this->setParticipantContact(new ParticipantContact($contact));
        $this->setNotes($participantNotes);
        $this->setPriority($priority);
    }

    /**
     * @param ParticipantContact|null $participantContact
     *
     * @throws OswisException
     */
    public function setParticipantContact(?ParticipantContact $participantContact): void
    {
        if ($this->getParticipantContact() === $participantContact) {
            return;
        }
        if ($this->getParticipantContacts()->isEmpty()) {
            $this->getParticipantContacts()->add($participantContact);
            $this->updateCachedColumns();

            return;
        }
        throw new NotImplementedException('změna kontaktu', 'u přihlášky');
    }

    /**
     * @return ParticipantContact
     * @throws OswisException
     */
    public function getParticipantContact(): ?ParticipantContact
    {
        $connections = $this->getParticipantContacts(true);
        if ($connections->count() > 1) {
            throw new OswisException('Účastník je přiřazen k více kontaktům najednou.');
        }

        return $connections->first() ?: null;
    }

    public function getParticipantContacts(bool $onlyActive = false, bool $onlyDeleted = false): Collection
    {
        $connections = $this->participantContacts ??= new ArrayCollection();
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
            $this->regRange = $this->getRegRange();
            $this->contact = $this->getContact();
            $this->event = $this->getEvent();
            $this->participantCategory = $this->getParticipantCategory();
        } catch (OswisException $e) {
        }
    }

    /**
     * @return RegRange|null
     * @throws OswisException
     */
    public function getRegRange(): ?RegRange
    {
        return $this->getParticipantRange() ? $this->getParticipantRange()->getRange() : null;
    }

    /**
     * @param RegRange|null $regRange
     *
     * @throws OswisException|NotImplementedException|EventCapacityExceededException
     */
    public function setRegRange(?RegRange $regRange): void
    {
        if ($this->getRegRange() !== $regRange) {
            $this->setParticipantRange(new ParticipantRange($regRange));
        }
    }

    /**
     * @return ParticipantRange
     * @throws OswisException
     */
    public function getParticipantRange(): ?ParticipantRange
    {
        $connections = $this->getParticipantRanges(true);
        if ($connections->count() > 1) {
            throw new OswisException('Účastník je přiřazen k více událostem najednou.');
        }

        return $connections->first() ?: null;
    }

    public function getParticipantRanges(bool $onlyActive = false, bool $onlyDeleted = false): Collection
    {
        $connections = $this->participantRanges ?? new ArrayCollection();
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
            return $this->getParticipantContact() ? $this->getParticipantContact()->getContact() : null;
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
        $this->setParticipantContact(new ParticipantContact($contact));
    }

    public function getEvent(): ?Event
    {
        try {
            return $this->getRegRange() ? $this->getRegRange()->getEvent() : null;
        } catch (OswisException $e) {
            return null;
        }
    }

    public function getParticipantCategory(): ?ParticipantCategory
    {
        try {
            return $this->getRegRange() ? $this->getRegRange()->getParticipantCategory() : null;
        } catch (OswisException $e) {
            return null;
        }
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

    public function hasActivatedContactUser(): bool
    {
        return $this->getContactPersons(true)->count() > 0;
    }

    public function getContactPersons(bool $onlyActivated = false): Collection
    {
        return $this->getContact() ? $this->getContact()->getContactPersons($onlyActivated) : new ArrayCollection();
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

    public function getAppUser(): ?AppUser
    {
        return $this->getContact() ? $this->getContact()->getAppUser() : null;
    }

    public function getQrPng(bool $deposit = true, bool $rest = true, string $qrComment = ''): ?string
    {
        if (null === ($event = $this->getEvent()) || null === ($bankAccount = $event->getBankAccount(true))) {
            return null;
        }
        $value = null;
        $typeString = null;
        try {
            if ($deposit && $rest) {
                $qrComment = (empty($qrComment) ? '' : "$qrComment, ").'celá částka';
                $value = $this->getPrice();
            }
            if ($deposit && !$rest) {
                $qrComment = (empty($qrComment) ? '' : "$qrComment, ").'záloha';
                $value = $this->getDepositValue();
            }
            if (!$deposit && $rest) {
                $qrComment = (empty($qrComment) ? '' : "$qrComment, ").'doplatek';
                $value = $this->getPriceRest();
            }

            return $bankAccount->getQrImage($value, $this->getVariableSymbol(), $qrComment);
        } catch (OswisException|PriceInvalidArgumentException $exception) {
            return null;
        }
    }

    /**
     * Get whole price of event for this participant (including flags price).
     * @return int
     * @throws OswisException|PriceInvalidArgumentException
     */
    public function getPrice(): int
    {
        if (null === $this->getRegRange() || null === $this->getParticipantCategory()) {
            throw new PriceInvalidArgumentException(' (nelze vypočítat cenu kvůli chybějícím údajům u přihlášky)');
        }
        $price = $this->getRegRange()->getPrice($this->getParticipantCategory()) + $this->getFlagsPrice();

        return $price < 0 ? 0 : $price;
    }

    public function getFlagsPrice(?FlagCategory $flagCategory = null, ?string $flagType = null): int
    {
        $price = 0;
        foreach ($this->getFlagGroups($flagCategory, $flagType) as $category) {
            $price += $category instanceof ParticipantFlagGroup ? $category->getPrice() : 0;
        }

        return $price;
    }

    public function getFlagGroups(?FlagCategory $flagCategory = null, ?string $flagType = null): Collection
    {
        $connections = $this->flagGroups ??= new ArrayCollection();
        if (null !== $flagCategory) {
            $connections = $connections->filter(fn(ParticipantFlagGroup $connection) => $connection->getFlagCategory() === $flagCategory);
        }
        if (null !== $flagType) {
            $connections = $connections->filter(fn(ParticipantFlagGroup $connection) => $connection->getFlagType() === $flagType);
        }

        return $connections;
    }

    /**
     * @param Collection|null $newFlagGroups
     *
     * @throws OswisException
     */
    public function setFlagGroups(?Collection $newFlagGroups): void
    {
        $newFlagGroups ??= new ArrayCollection();
        if (!$this->getFlagGroups()->forAll(fn(ParticipantFlagGroup $oldFlagGroup) => $newFlagGroups->contains($oldFlagGroup))) {
            throw new OswisException('Nový seznam skupiny příznaků není nadmnožinou původního seznamu u účastníka.');
        }
        $this->flagGroups = $newFlagGroups;
    }

    /**
     * Gets part of price that is marked as deposit.
     * @return int
     * @throws OswisException
     * @throws PriceInvalidArgumentException
     */
    public function getDepositValue(): ?int
    {
        if (null === $this->getRegRange() || null === $this->getParticipantCategory()) {
            throw new PriceInvalidArgumentException(' (nelze vypočítat cenu kvůli chybějícím údajům u přihlášky)');
        }
        $price = $this->getRegRange()->getDepositValue($this->getParticipantCategory()) + $this->getFlagsDepositValue();

        return $price < 0 ? 0 : $price;
    }

    public function getFlagsDepositValue(?FlagCategory $flagCategory = null, ?string $flagType = null): int
    {
        $price = 0;
        foreach ($this->getFlagGroups($flagCategory, $flagType) as $category) {
            $price += $category instanceof ParticipantFlagGroup ? $category->getDepositValue() : 0;
        }

        return $price;
    }

    /**
     * Gets part of price that is not marked as deposit.
     * @return int
     * @throws OswisException|PriceInvalidArgumentException
     */
    public function getPriceRest(): int
    {
        return $this->getPrice() - $this->getDepositValue();
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

    public function isActive(?DateTime $referenceDateTime = null): bool
    {
        return $this->isActivated($referenceDateTime) && !$this->isDeleted($referenceDateTime);
    }

    public function isRangeActivated(): bool
    {
        try {
            return $this->getParticipantRange() ? $this->getParticipantRange()->isActivated() : false;
        } catch (OswisException $e) {
            return false;
        }
    }

    /**
     * @param ParticipantRange|null $participantRange
     * @param bool                  $admin
     *
     * @throws OswisException|NotImplementedException|EventCapacityExceededException
     */
    public function setParticipantRange(?ParticipantRange $participantRange, bool $admin = false): void
    {
        if ($this->getParticipantRange() === $participantRange) {
            return;
        }
        if (null !== $participantRange && null !== $participantRange->getRange() && $this->getParticipantRanges()->isEmpty()) {
            if ($participantRange->getRange()->getRemainingCapacity($admin) === 0) {
                throw new EventCapacityExceededException($participantRange->getEventName());
            }
            $this->getParticipantRanges()->add($participantRange);
            $this->setFlagGroupsFromRegRange();
            $this->updateCachedColumns();

            return;
        }
        throw new NotImplementedException('změna události', 'u přihlášky');
    }

    /**
     * @throws NotImplementedException
     * @throws OswisException
     */
    public function setFlagGroupsFromRegRange(): void
    {
        // TODO: This is only temporary implementation.
        if (null === $this->getRegRange()) {
            return;
        }
        if (!$this->getFlagGroups()->isEmpty()) {
            throw new NotImplementedException('změna rozsahu registrací a příznaků', 'u účastníků');
        }
        $this->getRegRange()->getFlagGroupRanges(null, null, true)->map(
            fn(FlagGroupRange $flagGroupRange) => $this->getFlagGroupRanges()->add($flagGroupRange)
        );
    }

    public function isRangeDeleted(): bool
    {
        try {
            return !($this->getRegRange() && $this->getEvent() && $this->getParticipantCategory());
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
            return $this->getParticipantCategory() ? $this->getParticipantCategory()->isFormal() : true;
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
        $type = $this->getParticipantCategory();

        return null !== $type ? in_array($type->getType(), ParticipantCategory::MANAGEMENT_TYPES, true) : false;
    }

    public function getName(): ?string
    {
        return null !== $this->getContact() ? $this->getContact()->getName() : null;
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

    public function getNotes(): Collection
    {
        return $this->notes ??= new ArrayCollection();
    }

    public function setNotes(?Collection $notes): void
    {
        $this->notes = $notes ?? new ArrayCollection();
    }

    /**
     * @param Collection|null $newRanges
     *
     * @throws NotImplementedException
     */
    public function setFlagGroupRanges(?Collection $newRanges): void
    {
        $newRanges ??= new ArrayCollection();
        if ($this->getFlagGroupRanges() !== $newRanges) {
            throw new NotImplementedException('změna skupin příznaků', 'u účastníka');
        }
    }

    public function getFlagGroupRanges(?FlagCategory $flagCategory = null, ?string $flagType = null): Collection
    {
        return $this->getFlagGroups($flagCategory, $flagType)->map(
            fn(ParticipantFlagGroup $connection) => $connection->getFlagGroupRange()
        );
    }

    /**
     * @return int
     * @throws OswisException|PriceInvalidArgumentException
     */
    public function getRemainingRest(): int
    {
        return $this->getPriceRest() - $this->getPaidPrice() + $this->getDepositValue();
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

    public function hasFlag(?Flag $flag = null, bool $onlyActive = true, ?FlagCategory $flagCategory = null, ?string $flagType = null): bool
    {
        return $this->getParticipantFlags($flagCategory, $flagType, $onlyActive, $flag)->count() > 0;
    }

    public function getParticipantFlags(?FlagCategory $flagCategory = null, ?string $flagType = null, bool $onlyActive = true, ?Flag $flag = null): Collection
    {
        $participantFlags = new ArrayCollection();
        foreach ($this->getFlagGroups($flagCategory, $flagType) as $flagGroup) {
            if ($flagGroup instanceof ParticipantFlagGroup) {
                foreach ($flagGroup->getParticipantFlags($onlyActive, $flag) as $participantFlag) {
                    if ($participantFlag instanceof ParticipantFlag && (!$onlyActive || $participantFlag->isActive())) {
                        $participantFlags->add($participantFlag);
                    }
                }
            }
        }

        return $participantFlags;
    }

    public function getFlagRanges(?FlagCategory $flagCategory = null, ?string $flagType = null, bool $onlyActive = true, Flag $flag = null): Collection
    {
        return $this->getParticipantFlags($flagCategory, $flagType, $onlyActive, $flag)->map(
            fn(ParticipantFlag $participantFlag) => $participantFlag->getFlag()
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

    /**
     * @param ParticipantPayment|null $participantPayment
     *
     * @throws NotImplementedException
     */
    public function removePayment(?ParticipantPayment $participantPayment): void
    {
        if (null !== $participantPayment) {
            throw new NotImplementedException('odebrání platby', 'u přihlášek');
        }
    }

    /**
     * @param ParticipantPayment|null $participantPayment
     *
     * @throws NotImplementedException
     */
    public function addPayment(?ParticipantPayment $participantPayment): void
    {
        if ($participantPayment && !$this->getPayments()->contains($participantPayment)) {
            $this->getPayments()->add($participantPayment);
            $participantPayment->setParticipant($this);
        }
    }

    /**
     * Gets array of flags aggregated by their types.
     * @return array
     */
    public function getFlagsAggregatedByType(): array
    {
        return FlagsByType::getFlagsAggregatedByType($this->getParticipantFlags());
    }

    public function removeEmptyNotesAndDetails(): void
    {
        $this->removeEmptyParticipantNotes();
        if (null !== $this->getContact()) {
            $this->getContact()->removeEmptyDetails();
            $this->getContact()->removeEmptyNotes();
        }
        foreach ($this->getContactPersons() as $contactPerson) {
            if ($contactPerson instanceof AbstractContact) {
                $contactPerson->removeEmptyDetails();
                $contactPerson->removeEmptyNotes();
            }
        }
    }

    public function removeEmptyParticipantNotes(): void
    {
        $this->setNotes(
            $this->getNotes()->filter(fn(ParticipantNote $note): bool => !empty($note->getTextValue()))
        );
    }

}
