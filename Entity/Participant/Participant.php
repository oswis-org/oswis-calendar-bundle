<?php
/**
 * @noinspection PhpUnused
 * @noinspection PropertyCanBePrivateInspection
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Entity\Participant;

use ApiPlatform\Core\Annotation\ApiFilter;
use ApiPlatform\Core\Bridge\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Core\Bridge\Doctrine\Orm\Filter\SearchFilter;
use DateTime;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping\Cache;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\InverseJoinColumn;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\JoinTable;
use Doctrine\ORM\Mapping\ManyToMany;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\OneToMany;
use Doctrine\ORM\Mapping\Table;
use OswisOrg\OswisAddressBookBundle\Entity\AbstractClass\AbstractContact;
use OswisOrg\OswisCalendarBundle\Entity\Event\Event;
use OswisOrg\OswisCalendarBundle\Entity\NonPersistent\FlagsByType;
use OswisOrg\OswisCalendarBundle\Entity\ParticipantMail\ParticipantMail;
use OswisOrg\OswisCalendarBundle\Entity\Registration\RegistrationFlag;
use OswisOrg\OswisCalendarBundle\Entity\Registration\RegistrationFlagCategory;
use OswisOrg\OswisCalendarBundle\Entity\Registration\RegistrationFlagGroupOffer;
use OswisOrg\OswisCalendarBundle\Entity\Registration\RegistrationOffer;
use OswisOrg\OswisCalendarBundle\Exception\EventCapacityExceededException;
use OswisOrg\OswisCalendarBundle\Exception\FlagCapacityExceededException;
use OswisOrg\OswisCalendarBundle\Exception\FlagOutOfRangeException;
use OswisOrg\OswisCalendarBundle\Filter\ParentEventFilter;
use OswisOrg\OswisCalendarBundle\Interfaces\Participant\ParticipantInterface;
use OswisOrg\OswisCalendarBundle\Repository\Participant\ParticipantRepository;
use OswisOrg\OswisCoreBundle\Entity\AppUser\AppUser;
use OswisOrg\OswisCoreBundle\Exceptions\NotImplementedException;
use OswisOrg\OswisCoreBundle\Exceptions\OswisException;
use OswisOrg\OswisCoreBundle\Traits\Common\ActivatedTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\BasicTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\DeletedTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\ManagerConfirmationTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\PriorityTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\UserConfirmationTrait;
use Symfony\Component\Serializer\Annotation\MaxDepth;

/**
 * Participation of contact in event (attendee, sponsor, organizer, guest, partner...).
 *
 * @OswisOrg\OswisCoreBundle\Filter\SearchAnnotation({
 *     "id",
 *     "variableSymbol",
 *     "contact.name",
 * })
 * @ApiPlatform\Core\Annotation\ApiResource(
 *   attributes={
 *     "filters"={"search"},
 *     "security"="is_granted('ROLE_MANAGER')"
 *   },
 *   collectionOperations={
 *     "get"={
 *       "security"="is_granted('ROLE_CUSTOMER')",
 *       "normalization_context"={"groups"={"entities_get", "calendar_participants_get"}, "enable_max_depth"=true},
 *     },
 *     "post"={
 *       "security"="is_granted('ROLE_MANAGER')",
 *       "denormalization_context"={"groups"={"entities_post", "calendar_participants_post"}, "enable_max_depth"=true}
 *     }
 *   },
 *   itemOperations={
 *     "get"={
 *       "security"="is_granted('ROLE_CUSTOMER')",
 *       "normalization_context"={"groups"={"entity_get", "calendar_participant_get"}, "enable_max_depth"=true},
 *     },
 *     "put"={
 *       "security"="is_granted('ROLE_MANAGER')",
 *       "denormalization_context"={"groups"={"entity_put", "calendar_participant_put"}, "enable_max_depth"=true}
 *     }
 *   }
 * )
 */
#[ApiFilter(ParentEventFilter::class)]
#[ApiFilter(OrderFilter::class, properties : [
    'id',
    'createdAt',
    'contact.sortableName',
    'event.startDateTime',
    'event.id',
])]
#[ApiFilter(SearchFilter::class, strategy : 'exact', properties : [
    'event.id',
    'event.superEvent.id',
    'offer.event.id',
    'offer.event.superEvent.id',
])]
#[Entity(repositoryClass : ParticipantRepository::class)]
#[Table(name : 'calendar_participant')]
#[Cache(usage : 'NONSTRICT_READ_WRITE', region : 'calendar_participant')]
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
     * @var Collection<ParticipantNote> $notes
     */
    #[OneToMany(mappedBy : 'participant', targetEntity : ParticipantNote::class, cascade : ['all'], fetch : 'EAGER')]
    #[MaxDepth(1)]
    protected Collection $notes;

    /**
     * @var Collection<ParticipantPayment>
     */
    #[OneToMany(mappedBy : 'participant', targetEntity : ParticipantPayment::class, cascade : ['all'], fetch : 'EAGER')]
    #[MaxDepth(1)]
    protected Collection $payments;

    /**
     * @var Collection<ParticipantMail> $eMails
     */
    #[OneToMany(mappedBy : 'participant', targetEntity : ParticipantMail::class, cascade : ['all'], fetch : 'EAGER')]
    #[MaxDepth(1)]
    protected Collection $eMails;

    /**
     * Related contact (person or organization).
     */
    #[ManyToOne(targetEntity : AbstractContact::class, cascade : ['all'], fetch : 'EAGER')]
    #[JoinColumn(nullable : true)]
    protected ?AbstractContact $contact = null;

    #[ManyToOne(targetEntity : RegistrationOffer::class, fetch : 'EAGER')]
    #[JoinColumn(nullable : true)]
    protected ?RegistrationOffer $offer = null;

    #[ManyToOne(targetEntity : Event::class, fetch : 'EAGER')]
    #[JoinColumn(nullable : true)]
    protected ?Event $event = null;

    #[ManyToOne(targetEntity : ParticipantCategory::class, fetch : 'EAGER')]
    #[JoinColumn(nullable : true)]
    protected ?ParticipantCategory $participantCategory = null;

    /**
     * @var Collection<ParticipantFlagGroup>
     */
    #[ManyToMany(targetEntity : ParticipantFlagGroup::class, cascade : ['all'], fetch : 'EAGER')]
    #[JoinTable(name : 'calendar_participant_flag_group_connection')]
    #[JoinColumn(name : "participant_id", referencedColumnName : "id")]
    #[InverseJoinColumn(name : "participant_flag_group_id", referencedColumnName : "id", unique : true)]
    protected Collection $flagGroups;

    /**
     * @var Collection<ParticipantRegistration>
     */
    #[OneToMany(mappedBy : 'participant', targetEntity : ParticipantRegistration::class, cascade : ['all'], fetch : 'EAGER')]
    protected Collection $participantRegistrations;

    /**
     * @var Collection<ParticipantContact>
     */
    #[OneToMany(mappedBy : 'participant', targetEntity : ParticipantContact::class, cascade : ['all'], fetch : 'EAGER')]
    protected Collection $participantContacts;

    #[Column(type : 'boolean', nullable : true)]
    protected ?bool $formal = null;

    #[Column(type : 'string', nullable : true)]
    protected ?string $variableSymbol = null;

    /**
     * @param  RegistrationOffer|null  $regRange
     * @param  AbstractContact|null  $contact
     * @param  Collection|null  $participantNotes
     *
     * @throws EventCapacityExceededException
     * @throws FlagCapacityExceededException
     * @throws FlagOutOfRangeException
     * @throws NotImplementedException
     * @throws OswisException
     */
    public function __construct(
        RegistrationOffer $regRange = null,
        AbstractContact $contact = null,
        ?Collection $participantNotes = null,
    ) {
        $this->participantContacts      = new ArrayCollection();
        $this->participantRegistrations = new ArrayCollection();
        $this->notes                    = new ArrayCollection();
        $this->flagGroups               = new ArrayCollection();
        $this->payments                 = new ArrayCollection();
        $this->eMails                   = new ArrayCollection();
        $participantContact             = new ParticipantContact($contact);
        $participantContact->activate(new DateTime());
        $this->setParticipantContact($participantContact);
        $this->setNotes($participantNotes);
        if ($regRange) {
            $this->setParticipantRegistration(new ParticipantRegistration($regRange));
        }
    }

    /**
     * @param  ParticipantContact|null  $participantContact
     *
     * @throws OswisException
     */
    public function setParticipantContact(?ParticipantContact $participantContact): void
    {
        if ($this->getParticipantContact() === $participantContact) {
            return;
        }
        $participantsContacts = $this->getParticipantContacts();
        if (null !== $participantContact && $participantsContacts->isEmpty()) {
            $participantsContacts->add($participantContact);
            $participantContact->setParticipant($this);
            $this->updateCachedColumns();

            return;
        }
        throw new NotImplementedException('změna kontaktu', 'u přihlášky');
    }

    /**
     * @param  bool  $onlyActive
     *
     * @return \OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantContact|null
     * @throws \OswisOrg\OswisCoreBundle\Exceptions\OswisException
     */
    public function getParticipantContact(bool $onlyActive = false): ?ParticipantContact
    {
        $connections = $this->getParticipantContacts($onlyActive);
        if ($onlyActive && $connections->count() > 1) {
            throw new OswisException('Účastník je přiřazen k více kontaktům najednou.');
        }

        return ($participant = $connections->first()) instanceof ParticipantContact ? $participant : null;
    }

    public function getParticipantContacts(bool $onlyActive = false, bool $onlyDeleted = false): Collection
    {
        $connections = $this->participantContacts ??= new ArrayCollection();
        if (true === $onlyActive) {
            $connections = $connections->filter(fn(mixed $connection) => $connection instanceof ParticipantContact && $connection->isActive(),);
        }
        if (true === $onlyDeleted) {
            $connections = $connections->filter(fn(mixed $connection) => $connection instanceof ParticipantContact && $connection->isDeleted(),);
        }

        return $connections;
    }

    public function isActive(?DateTime $referenceDateTime = null): bool
    {
        return $this->hasActivatedContactUser() && !$this->isDeleted($referenceDateTime);
    }

    public function hasActivatedContactUser(): bool
    {
        return $this->getContactPersons(true)->count() > 0;
    }

    public function getContactPersons(bool $onlyActivated = false): Collection
    {
        return $this->getContact()?->getContactPersons($onlyActivated) ?? new ArrayCollection();
    }

    /**
     * @param  bool  $onlyActive
     *
     * @return \OswisOrg\OswisAddressBookBundle\Entity\AbstractClass\AbstractContact|null
     */
    public function getContact(bool $onlyActive = false): ?AbstractContact
    {
        try {
            return null !== ($participantContact = $this->getParticipantContact($onlyActive)) ? $participantContact->getContact() : null;
        } catch (OswisException) {
            return null;
        }
    }

    /**
     * @param  AbstractContact  $contact
     *
     * @throws OswisException
     */
    public function setContact(AbstractContact $contact): void
    {
        if ($this->getContact(true) !== $contact) {
            $participantContact = new ParticipantContact($contact);
            $this->setParticipantContact($participantContact);
            $participantContact->setParticipant($this);
        }
        $this->updateVariableSymbol();
    }

    public function updateCachedColumns(): void
    {
        $this->offer               = $this->getOffer();
        $this->contact             = $this->getContact();
        $this->event               = $this->offer?->getEvent();
        $this->participantCategory = $this->offer?->getParticipantCategory();
        $this->updateVariableSymbol();
        // $this->removeEmptyNotesAndDetails();
    }

    public function getOffer(bool $onlyActive = false): ?RegistrationOffer
    {
        return $this->getParticipantRegistration($onlyActive)?->getOffer();
    }

    /**
     * @param  RegistrationOffer|null  $offer
     *
     * @throws EventCapacityExceededException
     * @throws FlagCapacityExceededException
     * @throws FlagOutOfRangeException
     * @throws NotImplementedException
     * @throws OswisException
     */
    public function setOffer(?RegistrationOffer $offer): void
    {
        if ($this->getOffer(true) !== $offer) {
            $participantRange = new ParticipantRegistration($offer);
            $this->setParticipantRegistration($participantRange);
            $participantRange->setParticipant($this);
        }
    }

    public function getParticipantRegistration(bool $onlyActive = false): ?ParticipantRegistration
    {
        $participantRanges = self::sortCollection($this->getParticipantRegistrations($onlyActive), true);
        if ($onlyActive && $participantRanges->count() > 1) {
            $this->deleteParticipantRegistrations(true);
            $participantRanges = self::sortCollection($this->getParticipantRegistrations($onlyActive), true);
        }

        return ($registration = $participantRanges->first()) instanceof ParticipantRegistration ? $registration : null;
    }

    public function getParticipantRegistrations(bool $onlyActive = false, bool $onlyDeleted = false): Collection
    {
        $connections = $this->participantRegistrations ?? new ArrayCollection();
        if ($onlyActive) {
            $connections = $connections->filter(fn(mixed $connection) => $connection instanceof ParticipantRegistration && $connection->isActive(),);
        }
        if ($onlyDeleted) {
            $connections = $connections->filter(fn(mixed $connection) => $connection instanceof ParticipantRegistration && $connection->isDeleted(),);
        }

        return $connections;
    }

    public function deleteParticipantRegistrations(bool $exceptLatest = false): void
    {
        foreach ($ranges = $this->getParticipantRegistrations() as $range) {
            if (!($range instanceof ParticipantRegistration) || ($exceptLatest && $ranges->first() === $range)) {
                continue;
            }
            $range->delete();
        }
    }

    public function getEvent(bool $onlyActive = false): ?Event
    {
        return null !== ($participantRange = $this->getParticipantRegistration($onlyActive)) ? $participantRange->getEvent() : null;
    }

    public function getParticipantCategory(bool $onlyActive = false): ?ParticipantCategory
    {
        return $this->getParticipantRegistration($onlyActive)?->getParticipantCategory();
    }

    public function updateVariableSymbol(): ?string
    {
        return $this->variableSymbol = self::vsStringFix($this->getContact()?->getPhone()) ?? ''.$this->getId();
    }

    public static function vsStringFix(?string $variableSymbol): ?string
    {
        $variableSymbol = preg_replace('/\s/', '', ''.$variableSymbol);

        return empty($variableSymbol) ? null : substr(trim(''.$variableSymbol), -9);
    }

    /**
     * @param  ParticipantRegistration|null  $newParticipantRegistration
     * @param  bool  $admin
     *
     * @throws EventCapacityExceededException
     * @throws FlagCapacityExceededException
     * @throws FlagOutOfRangeException
     * @throws NotImplementedException
     * @throws OswisException
     */
    public function setParticipantRegistration(?ParticipantRegistration $newParticipantRegistration, bool $admin = false): void
    {
        $oldParticipantRange = $this->getParticipantRegistration();
        $oldRegRange         = $oldParticipantRange?->getOffer();
        $newRegRange         = $newParticipantRegistration?->getOffer();
        //
        // CASE 1: RegistrationOffer is same. Do nothing.
        if ($oldRegRange === $newRegRange) {
            return;
        }
        //
        // CASE 2: New RegistrationOffer is not set.
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
            /** @noinspection NullPointerExceptionInspection */
            throw new EventCapacityExceededException($newParticipantRegistration->getEventName());
        }
        //
        // CASE 3: RegistrationOffer is not set yet, set initial RegistrationOffer and set new flags by range.
        if (null === $oldRegRange) {
            $this->setFlagGroupsByOffer($newRegRange);
        }
        //
        // CASE 4: RegistrationOffer is already set, change it and change flags by new range.
        if (null !== $oldParticipantRange) {
            $oldParticipantRange->delete();
            $this->changeFlagsByNewOffer($newRegRange, true);
            $this->changeFlagsByNewOffer($newRegRange);
        }
        // Finally, add participant range.
        foreach ($this->getParticipantRegistrations() as $participantRange) {
            if ($participantRange instanceof ParticipantRegistration) {
                $participantRange->delete();
            }
        }
        if (null !== $newParticipantRegistration) {
            $newParticipantRegistration->activate();
            $this->getParticipantRegistrations()->add($newParticipantRegistration);
            $newParticipantRegistration->setParticipant($this);
        }
        $this->updateCachedColumns();
    }

    public function deleteParticipantFlags(): void
    {
        foreach ($this->getParticipantFlags() as $participantFlag) {
            if ($participantFlag instanceof ParticipantFlag) {
                $participantFlag->delete();
            }
        }
    }

    public function getParticipantFlags(
        ?RegistrationFlagCategory $flagCategory = null,
        ?string $flagType = null,
        bool $onlyActive = false,
        ?RegistrationFlag $flag = null
    ): Collection {
        $participantFlags = new ArrayCollection();
        foreach ($this->getFlagGroups($flagCategory, $flagType, $onlyActive) as $flagGroup) {
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

    /**
     * @param  \OswisOrg\OswisCalendarBundle\Entity\Registration\RegistrationFlagCategory|null  $flagCategory
     * @param  string|null  $flagType
     * @param  bool  $onlyActive
     *
     * @return \Doctrine\Common\Collections\Collection<ParticipantFlagGroup>
     */
    public function getFlagGroups(
        ?RegistrationFlagCategory $flagCategory = null,
        ?string $flagType = null,
        bool $onlyActive = false,
    ): Collection {
        $flagGroups = $this->flagGroups;
        if (null !== $flagCategory) {
            $flagGroups = $flagGroups->filter(fn(mixed $connection) => $connection instanceof ParticipantFlagGroup
                                                                       && $connection->getFlagCategory() === $flagCategory,);
        }
        if (null !== $flagType) {
            $flagGroups = $flagGroups->filter(fn(mixed $connection) => $connection instanceof ParticipantFlagGroup
                                                                       && $connection->getFlagType() === $flagType,);
        }
        if ($onlyActive) {
            $flagGroups = $flagGroups->filter(fn(mixed $connection) => ($connection instanceof ParticipantFlagGroup) && (!$connection->isDeleted()),);
        }

        /** @var Collection<ParticipantFlagGroup> $flagGroups */
        return $flagGroups;
    }

    /**
     * @param  Collection|null  $newFlagGroups
     *
     * @throws FlagCapacityExceededException
     * @throws FlagOutOfRangeException
     * @throws OswisException
     */
    public function setFlagGroups(?Collection $newFlagGroups): void
    {
        $this->changeFlagsByNewOffer($this->getOffer(), false, false, $newFlagGroups ?? new ArrayCollection(),);
    }

    /**
     * @param  RegistrationOffer  $registrationOffer
     *
     * @throws NotImplementedException
     */
    public function setFlagGroupsByOffer(RegistrationOffer $registrationOffer): void
    {
        if (!$this->getFlagGroups(null, null, true)->isEmpty()) {
            throw new NotImplementedException('změna rozsahu registrací a příznaků', 'u účastníků');
        }
        $flagGroupRanges = $registrationOffer->getFlagGroupRanges(null, null, true, true,);
        foreach ($flagGroupRanges as $flagGroupRange) {
            if ($flagGroupRange instanceof RegistrationFlagGroupOffer) {
                $this->addFlagGroupOffer($flagGroupRange);
            }
        }
    }

    public function addFlagGroupOffer(RegistrationFlagGroupOffer $flagGroupRange): void
    {
        if (!$this->getFlagGroupOffers()->contains($flagGroupRange)) {
            $this->getFlagGroups()->add(new ParticipantFlagGroup($flagGroupRange));
        }
    }

    public function getFlagGroupOffers(
        ?RegistrationFlagCategory $flagCategory = null,
        ?string $flagType = null,
        bool $onlyActive = false,
    ): Collection {
        return $this->getFlagGroups($flagCategory, $flagType, $onlyActive)->map(fn(mixed $connection) => $connection instanceof ParticipantFlagGroup
            ? $connection->getFlagGroupOffer() : null,);
    }

    /**
     * @param  \OswisOrg\OswisCalendarBundle\Entity\Registration\RegistrationOffer|null  $newRange
     * @param  bool  $onlySimulate
     * @param  bool  $admin
     * @param  Collection<ParticipantFlagGroup>|null  $newFlagGroups
     *
     * @throws \OswisOrg\OswisCalendarBundle\Exception\FlagCapacityExceededException
     * @throws \OswisOrg\OswisCalendarBundle\Exception\FlagOutOfRangeException
     * @throws \OswisOrg\OswisCoreBundle\Exceptions\NotImplementedException
     */
    private function changeFlagsByNewOffer(
        ?RegistrationOffer $newRange,
        bool $onlySimulate = false,
        bool $admin = false,
        ?Collection $newFlagGroups = null,
    ): void {
        if (null === $newRange) {
            throw new NotImplementedException();
        }
        /** @var Collection<ParticipantFlagGroup>|null $newFlagGroups */
        $newFlagGroups ??= $this->getFlagGroups(null, null, true);
        foreach ($newFlagGroups as $oldParticipantFlagGroup) {
            /** @var ParticipantFlagGroup $oldParticipantFlagGroup */
            $newParticipantFlagGroup = $newRange->makeCompatibleParticipantFlagGroup($oldParticipantFlagGroup, $admin, $onlySimulate);
            if (false === $onlySimulate && $oldParticipantFlagGroup !== $newParticipantFlagGroup) {
                $this->replaceParticipantFlagGroup($oldParticipantFlagGroup, $newParticipantFlagGroup);
            }
        }
    }

    public function replaceParticipantFlagGroup(
        ParticipantFlagGroup $oldParticipantFlagGroup,
        ParticipantFlagGroup $newParticipantFlagGroup,
    ): void {
        $oldParticipantFlagGroup->delete();
        $this->getFlagGroups()->add($newParticipantFlagGroup);
    }

    public static function filterCollection(Collection $participants, ?bool $includeNotActivated = true): Collection
    {
        $filtered = new ArrayCollection();
        foreach ($participants as $newParticipant) {
            if (!($newParticipant instanceof self)
                || (!$includeNotActivated && !$newParticipant->hasActivatedContactUser())) {
                continue;
            }
            if (!$filtered->contains($newParticipant)) {
                $filtered->add($newParticipant);
            }
        }

        return $filtered;
    }

    public static function sortParticipantsCollection(Collection $participants): Collection
    {
        $participantsArray = $participants->toArray();
        self::sortParticipantsArray($participantsArray);

        return new ArrayCollection($participantsArray);
    }

    public static function sortParticipantsArray(array &$participants): array
    {
        usort($participants, static fn(mixed $p1, mixed $p2) => self::compareParticipants($p1, $p2),);

        return $participants;
    }

    /**
     * @param  Participant  $participant1
     * @param  Participant  $participant2
     *
     * @return int
     */
    public static function compareParticipants(mixed $participant1, mixed $participant2): int
    {
        $cmpResult = (!$participant1->getContact() || !$participant2->getContact())
            ? 0
            : strcmp($participant1->getContact()->getSortableName(),
                $participant2->getContact()->getSortableName(),);

        return $cmpResult === 0 ? self::compare($participant1, $participant2) : $cmpResult;
    }

    public function getSortableName(): string
    {
        return null !== ($contact = $this->getContact()) ? $contact->getSortableName() : '';
    }

    public function removeEmptyNotesAndDetails(): void
    {
        $this->removeEmptyParticipantNotes();
        if (null !== ($contact = $this->getContact())) {
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

    public function removeEmptyParticipantNotes(): void
    {
        $this->setNotes($this->getNotes()->filter(fn(mixed $note): bool => $note instanceof ParticipantNote && !empty($note->getTextValue()),),);
    }

    /**
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getNotes(): Collection
    {
        return $this->notes;
    }

    public function setNotes(?Collection $newNotes): void
    {
        /** @var Collection<ParticipantNote>|null $newNotes */
        $newNotes ??= new ArrayCollection();
        foreach ($this->getNotes() as $oldNote) {
            /** @var ParticipantNote $oldNote */
            if (!$newNotes->contains($oldNote)) {
                $this->removeNote($oldNote);
            }
        }
        foreach ($newNotes as $newNote) {
            if (!$this->getNotes()->contains($newNote)) {
                $this->addNote($newNote);
            }
        }
    }

    public function isParticipantFlagGroupCompatible(?ParticipantFlagGroup $participantFlagGroup = null): bool
    {
        if ($participantFlagGroup) {
            return ($regRange = $this->getOffer())
                   && $regRange->isFlagGroupRangeCompatible($participantFlagGroup->getFlagGroupOffer());
        }

        return false;
    }

    public function setDeletedAt(?DateTime $deletedAt = null): void
    {
        $this->traitSetDeletedAt($deletedAt);
        if ($this->isDeleted()) {
            foreach ($this->getParticipantFlags() as $participantFlagGroup) {
                if ($participantFlagGroup instanceof ParticipantFlag) {
                    $participantFlagGroup->delete($deletedAt);
                }
            }
            foreach ($this->getFlagGroups() as $participantFlagGroup) {
                if ($participantFlagGroup instanceof ParticipantFlagGroup) {
                    $participantFlagGroup->delete($deletedAt);
                }
            }
            foreach ($this->getParticipantRegistrations() as $participantRange) {
                if ($participantRange instanceof ParticipantRegistration) {
                    $participantRange->delete($deletedAt);
                }
            }
        }
    }

    public function getFlagOffers(
        ?RegistrationFlagCategory $flagCategory = null,
        ?string $flagType = null,
        bool $onlyActive = false,
        RegistrationFlag $flag = null,
    ): Collection {
        $flagRanges = new ArrayCollection();
        foreach ($this->getParticipantFlags($flagCategory, $flagType, $onlyActive, $flag) as $participantFlag) {
            if ($participantFlag instanceof ParticipantFlag) {
                $flagRanges->add($participantFlag->getFlagOffer());
            }
        }

        return $flagRanges;
    }

    public function differenceFromPayment(?int $value): ?int
    {
        $priceRest        = $this->getPriceRest();
        $diff             = abs($priceRest - $value);
        $remainingDeposit = $this->getRemainingDeposit();
        $depositDiff      = abs($remainingDeposit - $value);

        return min($depositDiff, $diff);
    }

    /**
     * Gets part of price that is not marked as deposit.
     * @return int
     */
    public function getPriceRest(): int
    {
        return $this->getPrice() - $this->getDepositValue();
    }

    /**
     * Get whole price of event for this participant (including flags price).
     * @return int
     */
    public function getPrice(): int
    {
        $range = $this->getParticipantRegistration(true);
        $price = $range ? $range->getPrice($this->getParticipantCategory(true)) : 0;
        $price += $this->getFlagsPrice();

        return $price < 0 ? 0 : $price;
    }

    public function getFlagsPrice(
        ?RegistrationFlagCategory $flagCategory = null,
        ?string $flagType = null,
        ?RegistrationFlag $flag = null,
    ): int {
        $price = 0;
        foreach ($this->getParticipantFlags($flagCategory, $flagType, true, $flag) as $participantFlag) {
            $price += $participantFlag instanceof ParticipantFlag ? $participantFlag->getPrice() : 0;
        }

        return $price;
    }

    /**
     * Gets part of price that is marked as deposit.
     * @return int|null
     */
    public function getDepositValue(): ?int
    {
        $range = $this->getParticipantRegistration(true);
        $price = $range ? $range->getDepositValue($range->getParticipantCategory()) : 0;
        $price += $this->getFlagsDepositValue();

        return $price < 0 ? 0 : $price;
    }

    public function getFlagsDepositValue(?RegistrationFlagCategory $flagCategory = null, ?string $flagType = null): int
    {
        $price = 0;
        foreach ($this->getFlagGroups($flagCategory, $flagType, true) as $category) {
            $price += $category->getDepositValue();
        }

        return $price;
    }

    /**
     * Gets price deposit that remains to be paid.
     * @return int
     */
    public function getRemainingDeposit(): int
    {
        $deposit   = $this->getDepositValue();
        $remaining = null !== $deposit ? $deposit - $this->getPaidPrice() : 0;

        return max($remaining, 0);
    }

    /**
     * Get part of price that was already paid.
     * @return int
     */
    public function getPaidPrice(): int
    {
        $paid = 0;
        foreach ($this->getPayments() as $eventParticipantPayment) {
            /** @var ParticipantPayment $eventParticipantPayment */
            $paid += $eventParticipantPayment->getNumericValue();
        }

        return $paid;
    }

    public function getPayments(): Collection
    {
        return $this->payments ??= new ArrayCollection();
    }

    public function hasEMailOfType(?string $type = null): bool
    {
        return $this->getEMails()->filter(fn(mixed $mail) => $mail instanceof ParticipantMail && $mail->isSent() && $mail->getType() === $type,)->count() > 0;
    }

    public function getEMails(): Collection
    {
        return $this->eMails;
    }

    public function getAppUser(): ?AppUser
    {
        return $this->getContact()?->getAppUser();
    }

    public function generateQrPng(bool $deposit = true, bool $rest = true, string $qrComment = ''): ?string
    {
        if (null === ($event = $this->getEvent()) || null === ($bankAccount = $event->getBankAccount(true))) {
            return null;
        }
        $value = null;
        if ($deposit && $rest) {
            $qrComment = (empty($qrComment) ? '' : "$qrComment, ").'celá částka';
            $value     = $this->getPrice();
        }
        if ($deposit && !$rest) {
            $qrComment = (empty($qrComment) ? '' : "$qrComment, ").'záloha';
            $value     = $this->getDepositValue();
        }
        if (!$deposit && $rest) {
            $qrComment = (empty($qrComment) ? '' : "$qrComment, ").'doplatek';
            $value     = $this->getPriceRest();
        }

        return $bankAccount->getQrImage($value, $this->getVariableSymbol(), $qrComment);
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

    public function getTShirt(): string
    {
        $tShirts = $this->getFlags(null, RegistrationFlagCategory::TYPE_T_SHIRT_SIZE);
        if ($tShirts->count() < 1 || !(($tShirt = $tShirts->first()) instanceof RegistrationFlag)) {
            return '';
        }

        return ''.$tShirt->getShortName();
    }

    public function getFlags(
        ?RegistrationFlagCategory $flagCategory = null,
        ?string $flagType = null,
        bool $onlyActive = true,
        RegistrationFlag $flag = null
    ): Collection {
        return $this->getParticipantFlags($flagCategory, $flagType, $onlyActive, $flag)->map(fn(mixed $participantFlag) => $participantFlag
                                                                                                                           instanceof
                                                                                                                           ParticipantFlag
            ? $participantFlag->getFlag() : null,);
    }

    public function isRangeActivated(): bool
    {
        return $this->getParticipantRegistration(true)?->isActivated() ?? false;
    }

    /**
     * Recognizes if participant must be addressed in a formal way.
     *
     * @param  bool  $recursive
     *
     * @return bool|null Participant must be addressed in a formal way.
     */
    public function isFormal(bool $recursive = false): ?bool
    {
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

    public function isManager(): bool
    {
        return null !== ($type = $this->getParticipantCategory())
               && in_array($type->getType(), ParticipantCategory::MANAGEMENT_TYPES, true);
    }

    public function getName(): ?string
    {
        return null !== $this->getContact() ? $this->getContact()->getName() : null;
    }

    public function removeNote(?ParticipantNote $note): void
    {
        if (null !== $note && $this->getNotes()->removeElement($note)) {
            $note->setParticipant(null);
        }
    }

    public function addNote(?ParticipantNote $note): void
    {
        if (null !== $note && !$this->getNotes()->contains($note)) {
            $this->getNotes()->add($note);
            $note->setParticipant($this);
        }
    }

    /**
     * @param  Collection|null  $newRanges
     *
     * @throws NotImplementedException
     */
    public function setFlagGroupOffers(?Collection $newRanges): void
    {
        $newRanges ??= new ArrayCollection();
        if ($this->getFlagGroupOffers() !== $newRanges) {
            throw new NotImplementedException('změna skupin příznaků', 'u účastníka');
        }
    }

    public function getRemainingRest(): int
    {
        return $this->getPriceRest() - $this->getPaidPrice() + $this->getDepositValue();
    }

    public function hasFlag(
        ?RegistrationFlag $flag = null,
        bool $onlyActive = true,
        ?RegistrationFlagCategory $flagCategory = null,
        ?string $flagType = null
    ): bool {
        return $this->getParticipantFlags($flagCategory, $flagType, $onlyActive, $flag)->count() > 0;
    }

    /**
     * Gets price remains to be paid.
     * @return int
     */
    public function getRemainingPrice(): int
    {
        return $this->getPrice() - $this->getPaidPrice();
    }

    /**
     * Gets percentage of price paid (as float).
     * @return float
     */
    public function getPaidPricePercentage(): float
    {
        return empty($price = $this->getPrice()) ? 1.0 : $this->getPaidPrice() / $price;
    }

    /**
     * @param  ParticipantPayment|null  $participantPayment
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
     * @param  ParticipantMail|null  $participantMail
     *
     * @throws NotImplementedException
     */
    public function removeEMail(?ParticipantMail $participantMail): void
    {
        if (null !== $participantMail) {
            throw new NotImplementedException('odebrání zprávy', 'u přihlášek');
        }
    }

    /**
     * @param  ParticipantPayment|null  $participantPayment
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

    public function addEMail(?ParticipantMail $participantMail): void
    {
        if ($participantMail && !$this->getEMails()->contains($participantMail)) {
            $this->getEMails()->add($participantMail);
            try {
                $participantMail->setParticipant($this);
            } catch (NotImplementedException) {
            }
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

    public function isContainedInEvent(?Event $event = null): bool
    {
        if (null === $event || null === $this->getEvent()) {
            return false;
        }
        if ($this->getEvent() === $event || $this->getEvent()->isEventSuperEvent($event, true)) {
            return true;
        }

        return false;
    }
}
