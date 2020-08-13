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
use OswisOrg\OswisCalendarBundle\Exception\FlagCapacityExceededException;
use OswisOrg\OswisCalendarBundle\Exception\FlagOutOfRangeException;
use OswisOrg\OswisCalendarBundle\Interfaces\Participant\ParticipantInterface;
use OswisOrg\OswisCoreBundle\Entity\AppUser\AppUser;
use OswisOrg\OswisCoreBundle\Entity\Revisions\AbstractRevision;
use OswisOrg\OswisCoreBundle\Exceptions\NotImplementedException;
use OswisOrg\OswisCoreBundle\Exceptions\OswisException;
use OswisOrg\OswisCoreBundle\Exceptions\PriceInvalidArgumentException;
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
 *       "normalization_context"={"groups"={"entities_get", "calendar_participants_get"}, "enable_max_depth"=true},
 *     },
 *     "post"={
 *       "access_control"="is_granted('ROLE_MANAGER')",
 *       "denormalization_context"={"groups"={"entities_post", "calendar_participants_post"}, "enable_max_depth"=true}
 *     }
 *   },
 *   itemOperations={
 *     "get"={
 *       "access_control"="is_granted('ROLE_MANAGER')",
 *       "normalization_context"={"groups"={"entity_get", "calendar_participant_get"}, "enable_max_depth"=true},
 *     },
 *     "put"={
 *       "access_control"="is_granted('ROLE_MANAGER')",
 *       "denormalization_context"={"groups"={"entity_put", "calendar_participant_put"}, "enable_max_depth"=true}
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
class Participant implements ParticipantInterface
{
    use BasicTrait;
    use PriorityTrait;
    use ActivatedTrait;
    use UserConfirmationTrait;
    use ManagerConfirmationTrait;
    use DeletedTrait {
        setDeletedAt as traitSetDeletedAt;
    }

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
     *     name="calendar_participant_flag_group_connection",
     *     joinColumns={@Doctrine\ORM\Mapping\JoinColumn(name="participant_id", referencedColumnName="id")},
     *     inverseJoinColumns={@Doctrine\ORM\Mapping\JoinColumn(name="participant_flag_group_id", referencedColumnName="id", unique=true)}
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
     *     name="calendar_participant_contact_connection",
     *     joinColumns={@Doctrine\ORM\Mapping\JoinColumn(name="participant_id", referencedColumnName="id")},
     *     inverseJoinColumns={@Doctrine\ORM\Mapping\JoinColumn(name="participant_contact_id", referencedColumnName="id", unique=true)}
     * )
     */
    protected ?Collection $participantContacts = null;

    /**
     * @Doctrine\ORM\Mapping\Column(type="boolean", nullable=true)
     */
    protected ?bool $formal = null;

    /**
     * @Doctrine\ORM\Mapping\Column(type="string", nullable=true)
     */
    protected ?string $variableSymbol = null;

    /**
     * @param RegRange|null        $regRange
     * @param AbstractContact|null $contact
     * @param Collection|null      $participantNotes
     *
     * @throws EventCapacityExceededException
     * @throws FlagCapacityExceededException
     * @throws FlagOutOfRangeException
     * @throws NotImplementedException
     * @throws OswisException
     */
    public function __construct(RegRange $regRange = null, AbstractContact $contact = null, ?Collection $participantNotes = null)
    {
        error_log("Participant::__construct()");
        $this->participantContacts = new ArrayCollection();
        $this->participantRanges = new ArrayCollection();
        $this->notes = new ArrayCollection();
        $this->flagGroups = new ArrayCollection();
        $this->payments = new ArrayCollection();
        $participantContact = new ParticipantContact($contact);
        $participantContact->activate(new DateTime());
        $this->setParticipantContact($participantContact);
        if ($regRange) {
            $participantRange = new ParticipantRange($regRange);
            $participantRange->activate(new DateTime());
            $this->setParticipantRange($participantRange);
        }
        $this->setNotes($participantNotes);
        error_log("Participant::__construct() FINISHED");
    }

    /**
     * @param ParticipantContact|null $participantContact
     *
     * @throws OswisException
     */
    public function setParticipantContact(?ParticipantContact $participantContact): void
    {
        error_log("Participant->setParticipantContact()");
        if ($this->getParticipantContact() === $participantContact) {
            return;
        }
        $participantsContacts = $this->getParticipantContacts();
        if (null !== $participantContact && $participantsContacts->isEmpty()) {
            $participantsContacts->add($participantContact);
            error_log("Participant->setParticipantContact()...will update");
            $this->updateCachedColumns();
            error_log("Participant->setParticipantContact()...finished");

            return;
        }
        throw new NotImplementedException('změna kontaktu', 'u přihlášky');
    }

    /**
     * @param bool $onlyActive
     *
     * @return ParticipantContact
     * @throws OswisException
     */
    public function getParticipantContact(bool $onlyActive = true): ?ParticipantContact
    {
        $connections = $this->getParticipantContacts($onlyActive);
        if ($connections->count() > 1) {
            throw new OswisException('Účastník je přiřazen k více kontaktům najednou.');
        }

        return $connections->first() ?: null;
    }

    public function getParticipantContacts(bool $onlyActive = false, bool $onlyDeleted = false): Collection
    {
        $connections = $this->participantContacts ??= new ArrayCollection();
        if (true === $onlyActive) {
            $connections = $connections->filter(fn(ParticipantContact $connection) => $connection->isActive());
        }
        if (true === $onlyDeleted) {
            $connections = $connections->filter(fn(ParticipantContact $connection) => $connection->isDeleted());
        }

        return $connections;
    }

    public function updateCachedColumns(): void
    {
        try {
            error_log("Will update participant cached columns...");
            $this->regRange = $this->getRegRange(true);
            error_log("Will update participant cached columns... will get contact");
            $this->contact = $this->getContact();
            error_log("Will update participant cached columns... will get event");
            $this->event = $this->regRange ? $this->regRange->getEvent() : null;
            error_log("Will update participant cached columns... will get participantCategory");
            $this->participantCategory = $this->regRange ? $this->regRange->getParticipantCategory() : null;
            error_log("Will update participant cached columns... will update VS");
            $this->updateVariableSymbol();
            error_log("Participant cached columns updated.");
        } catch (OswisException $e) {
        }
    }

    /**
     * @param bool $onlyActive
     *
     * @return RegRange|null
     * @throws OswisException
     */
    public function getRegRange(bool $onlyActive = true): ?RegRange
    {
        error_log("Getting RegRange of participant...");
        $participantRange = $this->getParticipantRange($onlyActive);
        error_log("Getting RegRange of participant...returning");

        return $participantRange ? $participantRange->getRange() : null;
    }

    /**
     * @param RegRange|null $regRange
     *
     * @throws EventCapacityExceededException
     * @throws FlagCapacityExceededException
     * @throws FlagOutOfRangeException
     * @throws NotImplementedException
     * @throws OswisException
     */
    public function setRegRange(?RegRange $regRange): void
    {
        error_log("Set RegRange in participant...");
        if ($this->getRegRange(true) !== $regRange) {
            $this->setParticipantRange(new ParticipantRange($regRange));
        }
        error_log("OK, setted RegRange.");
    }

    /**
     * @param bool $onlyActive
     *
     * @return ParticipantRange
     * @throws OswisException
     */
    public function getParticipantRange(bool $onlyActive = true): ?ParticipantRange
    {
        error_log("Getting ParticipantRange of Participant...");
        $participantRanges = $this->getParticipantRanges($onlyActive);
        if ($participantRanges->count() > 1) {
            throw new OswisException('Účastník je přiřazen k více událostem najednou.');
        }
        error_log("Getting ParticipantRange of Participant...returning");

        return $participantRanges->first() ?: null;
    }

    public function getParticipantRanges(bool $onlyActive = false, bool $onlyDeleted = false): Collection
    {
        error_log("Getting ParticipantRanges...");
        $connections = $this->participantRanges ?? new ArrayCollection();
        if ($onlyActive) {
            $connections = $connections->filter(fn(ParticipantRange $connection) => $connection->isActive());
        }
        if ($onlyDeleted) {
            $connections = $connections->filter(fn(ParticipantRange $connection) => $connection->isDeleted());
        }

        return $connections;
    }

    public function getContact(bool $onlyActive = true): ?AbstractContact
    {
        error_log("Participant->getContact($onlyActive)");
        try {
            $participantContact = $this->getParticipantContact($onlyActive);

            error_log("Participant->getContact($onlyActive)...RETURNING");
            return $participantContact ? $participantContact->getContact() : null;
        } catch (OswisException $e) {
            error_log("Participant->getContact($onlyActive)...FAILED");
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
        if ($this->getContact(true) !== $contact) {
            $this->setParticipantContact(new ParticipantContact($contact));
        }
        $this->updateVariableSymbol();
    }

    public function getEvent(): ?Event
    {
        error_log("Participant::getEvent()");
        try {
            $participantRange = $this->getParticipantRange(true);
            error_log("getEvent()...returninig");

            return $participantRange ? $participantRange->getEvent() : null;
        } catch (OswisException $e) {
            error_log("getEvent()...NULL");

            return null;
        }
    }

    public function getParticipantCategory(): ?ParticipantCategory
    {
        error_log("getParticipanticipantCategory()");
        try {
            $participantRange = $this->getParticipantRange(true);
            error_log("getParticipanticipantCategory()...returning");

            return $participantRange ? $participantRange->getParticipantCategory() : null;
        } catch (OswisException $e) {
            error_log("getParticipanticipantCategory()...NULL");

            return null;
        }
    }

    public function updateVariableSymbol(): ?string
    {
        return $this->variableSymbol = self::vsStringFix($this->getContact() ? $this->getContact()->getPhone() : null) ?? ''.$this->getId();
    }

    public static function vsStringFix(?string $variableSymbol): ?string
    {
        return empty($variableSymbol) ? null : substr(trim(preg_replace('/\s/', '', $variableSymbol)), -9);
    }

    /**
     * @param ParticipantRange|null $newParticipantRange
     * @param bool                  $admin
     *
     * @throws EventCapacityExceededException
     * @throws FlagCapacityExceededException
     * @throws FlagOutOfRangeException
     * @throws NotImplementedException
     * @throws OswisException
     */
    public function setParticipantRange(?ParticipantRange $newParticipantRange, bool $admin = false): void
    {
        error_log("Setting new ParticipantRange...");
        $oldParticipantRange = $this->getParticipantRange(true);
        $oldRegRange = $oldParticipantRange ? $oldParticipantRange->getRange() : null;
        $newRegRange = $newParticipantRange ? $newParticipantRange->getRange() : null;
        //
        // CASE 1: RegRange is same. Do nothing.
        if ($oldRegRange === $newRegRange) {
            return;
        }
        //
        // CASE 2: New RegRange is not set.
        //   --> Set participant as deleted.
        //   --> Set participant flags as deleted.
        if (null === $newRegRange) {
            if ($oldParticipantRange) {
                $this->deleteParticipantFlags();
                $oldParticipantRange->delete();
            }

            return;
        }
        //
        // Check capacity of new range.
        $remainingCapacity = $newRegRange->getRemainingCapacity($admin);
        if (null !== $remainingCapacity && (0 === $remainingCapacity || -1 >= $remainingCapacity)) {
            throw new EventCapacityExceededException($newParticipantRange->getEventName());
        }
        //
        // CASE 3: RegRange is not set yet, set initial RegRange and set new flags by range.
        if (null === $oldRegRange) {
            $this->setFlagGroupsFromRegRange($newRegRange);
        }
        //
        // CASE 4: RegRange is already set, change it and change flags by new range.
        if (null !== $oldRegRange) {
            $this->changeFlagsByNewRegRange($newRegRange, true);
            $this->changeFlagsByNewRegRange($newRegRange, false);
        }
        // Finally, add participant range.
        foreach ($this->getParticipantRanges(false) as $participantRange) {
            if ($participantRange instanceof ParticipantRange) {
                $participantRange->delete();
            }
        }
        $newParticipantRange->activate();
        $this->getParticipantRanges()->add($newParticipantRange);
        error_log("Setted new ParticipantRange and will update...");
        $this->updateCachedColumns();
        error_log("Setted new ParticipantRange and updated...");
    }

    public function deleteParticipantFlags(): void
    {
        foreach ($this->getParticipantFlags() as $participantFlag) {
            if ($participantFlag instanceof ParticipantFlag) {
                $participantFlag->delete();
            }
        }
    }

    public function getParticipantFlags(?FlagCategory $flagCategory = null, ?string $flagType = null, bool $onlyActive = true, ?Flag $flag = null): Collection
    {
        error_log("getParticipanticipantFlags()");
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
        error_log("getParticipanticipantFlags()...returning");

        return $participantFlags;
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

    public function setFlagGroups(?Collection $newFlagGroups): void
    {
        $newFlagGroups ??= new ArrayCollection();
        // TODO: FIX IT:
        //        if (!$this->getFlagGroups()->forAll(fn(ParticipantFlagGroup $oldFlagGroup) => $newFlagGroups->contains($oldFlagGroup))) {
        //            throw new OswisException('Nový seznam skupiny příznaků není nadmnožinou původního seznamu u účastníka.');
        //        }
        $this->flagGroups = $newFlagGroups;
    }

    /**
     * @param RegRange $regRange
     *
     * @throws NotImplementedException
     */
    public function setFlagGroupsFromRegRange(RegRange $regRange): void
    {
        if (!$this->getFlagGroups()->isEmpty()) {
            throw new NotImplementedException('změna rozsahu registrací a příznaků', 'u účastníků');
        }
        foreach ($regRange->getFlagGroupRanges(null, null, true, true) as $flagGroupRange) {
            if ($flagGroupRange instanceof FlagGroupRange) {
                $this->addFlagGroupRange($flagGroupRange);
            }
        }
    }

    public function addFlagGroupRange(FlagGroupRange $flagGroupRange): void
    {
        if (!$this->getFlagGroupRanges()->contains($flagGroupRange)) {
            $this->getFlagGroups()->add(new ParticipantFlagGroup($flagGroupRange));
        }
    }

    public function getFlagGroupRanges(?FlagCategory $flagCategory = null, ?string $flagType = null): Collection
    {
        return $this->getFlagGroups($flagCategory, $flagType)->map(
            fn(ParticipantFlagGroup $connection) => $connection->getFlagGroupRange()
        );
    }

    /**
     * @param RegRange $newRange
     * @param bool     $onlySimulate
     * @param bool     $admin
     *
     * @throws FlagCapacityExceededException
     * @throws FlagOutOfRangeException
     */
    private function changeFlagsByNewRegRange(RegRange $newRange, bool $onlySimulate = false, bool $admin = false): void
    {
        error_log("changeFlagsByNewRegRange()");
        foreach ($this->getFlagGroups() as $oldParticipantFlagGroup) {
            if (!($oldParticipantFlagGroup instanceof ParticipantFlagGroup)) {
                continue;
            }
            $newParticipantFlagGroup = $newRange->getCompatibleParticipantFlagGroup($oldParticipantFlagGroup, $admin);
            if (false === $onlySimulate && $oldParticipantFlagGroup !== $newParticipantFlagGroup) {
                $oldParticipantFlagGroup->delete();
                $this->getFlagGroups()->add($newParticipantFlagGroup);
            }
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

    public static function sortCollection(Collection $participants): Collection
    {
        $participantsArray = $participants->toArray();
        self::sortArray($participantsArray);

        return new ArrayCollection($participantsArray);
    }

    public static function sortArray(array &$participants): array
    {
        usort($participants, fn(Participant $participant1, Participant $participant2) => self::cmp($participant1, $participant2));

        return $participants;
    }

    public static function cmp(Participant $participant1, Participant $participant2): int
    {
        $cmpResult = (!$participant1->getContact() || !$participant2->getContact())
            ? 0
            : strcmp(
                $participant1->getContact()->getSortableName(),
                $participant2->getContact()->getSortableName()
            );

        return $cmpResult === 0 ? AbstractRevision::cmpId($participant2->getId(), $participant1->getId()) : $cmpResult;
    }

    public function setDeletedAt(?DateTime $deleted = null): void
    {
        $this->traitSetDeletedAt($deleted);
        if ($this->isDeleted()) {
            foreach ($this->getParticipantRanges() as $participantRange) {
                if ($participantRange instanceof ParticipantRange) {
                    $participantRange->delete();
                }
            }
            foreach ($this->getParticipantFlags() as $participantFlag) {
                if ($participantFlag instanceof ParticipantFlag) {
                    $participantFlag->delete();
                }
            }
        }
    }

    public function getFlagRanges(?FlagCategory $flagCategory = null, ?string $flagType = null, bool $onlyActive = false, Flag $flag = null): Collection
    {
        $flagRanges = new ArrayCollection();
        foreach ($this->getParticipantFlags($flagCategory, $flagType, $onlyActive, $flag) as $participantFlag) {
            if ($participantFlag instanceof ParticipantFlag) {
                $flagRanges->add($participantFlag->getFlagRange());
            }
        }

        return $flagRanges;
    }

    public function differenceFromPayment(?int $value): ?int
    {
        try {
            $priceRest = $this->getPriceRest();
            $diff = abs($priceRest - $value);
        } catch (OswisException|PriceInvalidArgumentException $e) {
            $diff = PHP_INT_MAX;
        }
        try {
            $remainingDeposit = $this->getRemainingDeposit();
            $depositDiff = abs($remainingDeposit - $value);
            $diff = $depositDiff < $diff ? $depositDiff : $diff;
        } catch (OswisException|PriceInvalidArgumentException $e) {
        }

        return $diff;
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
     * Get whole price of event for this participant (including flags price).
     * @return int
     * @throws OswisException|PriceInvalidArgumentException
     */
    public function getPrice(): int
    {
        error_log("getPrice()");
        if (null === ($participantRange = $this->getParticipantRange(true))) {
            throw new PriceInvalidArgumentException(' (nelze vypočítat cenu kvůli chybějícím údajům u přihlášky)');
        }
        $price = $participantRange->getPrice($this->getParticipantCategory()) + $this->getFlagsPrice();

        return $price < 0 ? 0 : $price;
    }

    public function getFlagsPrice(?FlagCategory $flagCategory = null, ?string $flagType = null, ?Flag $flag = null): int
    {
        $price = 0;
        foreach ($this->getParticipantFlags($flagCategory, $flagType, true, $flag) as $participantFlag) {
            $price += $participantFlag instanceof ParticipantFlag ? $participantFlag->getPrice() : 0;
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
        error_log("getDepositValue()");
        if (null === ($participantRange = $this->getParticipantRange())) {
            throw new PriceInvalidArgumentException(' (nelze vypočítat cenu kvůli chybějícím údajům u přihlášky)');
        }
        $price = $participantRange->getDepositValue($this->getParticipantCategory()) + $this->getFlagsDepositValue();

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
     * Gets price deposit that remains to be paid.
     * @return int
     * @throws OswisException
     * @throws PriceInvalidArgumentException
     */
    public function getRemainingDeposit(): int
    {
        $deposit = $this->getDepositValue();
        $remaining = null !== $deposit ? $deposit - $this->getPaidPrice() : 0;

        return $remaining > 0 ? $remaining : 0;
    }

    /**
     * Get part of price that was already paid.
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

    public function getAppUser(): ?AppUser
    {
        return $this->getContact() ? $this->getContact()->getAppUser() : null;
    }

    public function getQrPng(bool $deposit = true, bool $rest = true, string $qrComment = ''): ?string
    {
        error_log("getQrPng()");
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

            error_log("getQrPng()...FINISHED");
            return $bankAccount->getQrImage($value, $this->getVariableSymbol(), $qrComment);
        } catch (OswisException|PriceInvalidArgumentException $exception) {
            error_log("getQrPng()...FAILED");
            return null;
        }
    }

    /**
     * Get variable symbol of this eventParticipant.
     */
    public function getVariableSymbol(): ?string
    {
        return $this->updateVariableSymbol();
    }

    public function setVariableSymbol(?string $variableSymbol): void
    {
        $this->variableSymbol = $variableSymbol;
    }

    public function isActive(?DateTime $referenceDateTime = null): bool
    {
        return $this->isActivated($referenceDateTime) && !$this->isDeleted($referenceDateTime);
    }

    public function isRangeActivated(): bool
    {
        error_log("isRangeActivated()");
        try {
            $participantRange = $this->getParticipantRange();

            return $participantRange ? $participantRange->isActivated() : false;
        } catch (OswisException $e) {
            return false;
        }
    }

    public function isRangeDeleted(): bool // TODO: What's this?
    {
        error_log("I am in some tricky method (isRangeDeleted())...");
        try {
            return !($this->getRegRange(true) && $this->getEvent() && $this->getParticipantCategory());
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
        error_log("isFormal()");
        if ($recursive && null === $this->formal) {
            $participantCategory = $this->getParticipantCategory();

            return $participantCategory ? $participantCategory->isFormal() : true;
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
        error_log("isManager()");
        $type = $this->getParticipantCategory();

        return null !== $type ? in_array($type->getType(), ParticipantCategory::MANAGEMENT_TYPES, true) : false;
    }

    public function getName(): ?string
    {
        return null !== $this->getContact() ? $this->getContact()->getName() : null;
    }

    /**
     * @param ParticipantNote|null $note
     *
     * @throws NotImplementedException
     */
    public function removeNote(?ParticipantNote $note): void
    {
        if (null !== $note) {
            $this->getNotes()->removeElement($note);
            $note->setParticipant(null);
        }
    }

    public function getNotes(): Collection
    {
        return $this->notes ??= new ArrayCollection();
    }

    /**
     * @param Collection|null $newNotes
     *
     * @throws NotImplementedException
     */
    public function setNotes(?Collection $newNotes): void
    {
        $this->notes ??= new ArrayCollection();
        $newNotes ??= new ArrayCollection();
        foreach ($this->notes as $oldNote) {
            if (!$newNotes->contains($oldNote)) {
                $this->removeNote($oldNote);
            }
        }
        foreach ($newNotes as $newNote) {
            if (!$this->notes->contains($newNote)) {
                $this->addNote($newNote);
            }
        }
    }

    /**
     * @param ParticipantNote|null $note
     *
     * @throws NotImplementedException
     */
    public function addNote(?ParticipantNote $note): void
    {
        if (null !== $note && !$this->getNotes()->contains($note)) {
            $this->getNotes()->add($note);
            $note->setParticipant($this);
        }
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

    /**
     * @return int
     * @throws OswisException|PriceInvalidArgumentException
     */
    public function getRemainingRest(): int
    {
        return $this->getPriceRest() - $this->getPaidPrice() + $this->getDepositValue();
    }

    public function hasFlag(?Flag $flag = null, bool $onlyActive = true, ?FlagCategory $flagCategory = null, ?string $flagType = null): bool
    {
        return $this->getParticipantFlags($flagCategory, $flagType, $onlyActive, $flag)->count() > 0;
    }

    public function getFlags(?FlagCategory $flagCategory = null, ?string $flagType = null, bool $onlyActive = true, Flag $flag = null): Collection
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

    /**
     * @throws NotImplementedException
     */
    public function removeEmptyNotesAndDetails(): void
    {
        $this->removeEmptyParticipantNotes();
        if (null !== $contact = $this->getContact()) {
            $contact->removeEmptyDetails();
            $contact->removeEmptyNotes();
        }
        foreach ($this->getContactPersons() as $contactPerson) {
            if ($contactPerson instanceof AbstractContact) {
                $contactPerson->removeEmptyDetails();
                $contactPerson->removeEmptyNotes();
            }
        }
    }

    /**
     * @throws NotImplementedException
     */
    public function removeEmptyParticipantNotes(): void
    {
        $this->setNotes($this->getNotes()->filter(fn(ParticipantNote $note): bool => !empty($note->getTextValue())));
    }
}
