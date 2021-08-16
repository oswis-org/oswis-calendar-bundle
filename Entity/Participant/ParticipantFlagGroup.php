<?php
/**
 * @noinspection PropertyCanBePrivateInspection
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Entity\Participant;

use DateTime;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use OswisOrg\OswisCalendarBundle\Entity\Registration\RegistrationFlag;
use OswisOrg\OswisCalendarBundle\Entity\Registration\RegistrationFlagCategory;
use OswisOrg\OswisCalendarBundle\Entity\Registration\RegistrationFlagGroupOffer;
use OswisOrg\OswisCalendarBundle\Entity\Registration\RegistrationFlagOffer;
use OswisOrg\OswisCalendarBundle\Exception\FlagCapacityExceededException;
use OswisOrg\OswisCalendarBundle\Exception\FlagOutOfRangeException;
use OswisOrg\OswisCoreBundle\Exceptions\NotImplementedException;
use OswisOrg\OswisCoreBundle\Interfaces\Common\BasicInterface;
use OswisOrg\OswisCoreBundle\Interfaces\Common\DeletedInterface;
use OswisOrg\OswisCoreBundle\Interfaces\Common\TextValueInterface;
use OswisOrg\OswisCoreBundle\Traits\Common\BasicTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\DeletedMailConfirmationTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\DeletedTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\TextValueTrait;

/**
 * @Doctrine\ORM\Mapping\Entity()
 * @Doctrine\ORM\Mapping\Table(name="calendar_participant_flag_group")
 * @Doctrine\ORM\Mapping\Cache(usage="NONSTRICT_READ_WRITE", region="calendar_participant")
 * @ApiPlatform\Core\Annotation\ApiResource(
 *   attributes={
 *     "filters"={"search"},
 *     "security"="is_granted('ROLE_MANAGER')"
 *   },
 *   collectionOperations={
 *     "get"={
 *       "security"="is_granted('ROLE_MANAGER')",
 *       "normalization_context"={"groups"={"entities_get", "calendar_participant_flag_groups_get"}, "enable_max_depth"=true},
 *     },
 *     "post"={
 *       "security"="is_granted('ROLE_MANAGER')",
 *       "denormalization_context"={"groups"={"entities_post", "calendar_participant_flag_groups_post"}, "enable_max_depth"=true}
 *     }
 *   },
 *   itemOperations={
 *     "get"={
 *       "security"="is_granted('ROLE_MANAGER')",
 *       "normalization_context"={"groups"={"entity_get", "calendar_participant_flag_group_get"}, "enable_max_depth"=true},
 *     },
 *     "put"={
 *       "security"="is_granted('ROLE_MANAGER')",
 *       "denormalization_context"={"groups"={"entity_put", "calendar_participant_flag_group_put"}, "enable_max_depth"=true}
 *     }
 *   }
 * )
 */
class ParticipantFlagGroup implements BasicInterface, TextValueInterface, DeletedInterface
{
    use BasicTrait;
    use TextValueTrait;
    use DeletedTrait {
        delete as traitDelete;
    }

    public ?Collection $tempFlagRanges = null;

    /**
     * @Doctrine\ORM\Mapping\ManyToOne(
     *     targetEntity="OswisOrg\OswisCalendarBundle\Entity\Registration\RegistrationFlagGroupOffer",
     *     fetch="EAGER",
     * )
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     */
    protected ?RegistrationFlagGroupOffer $flagGroupOffer = null;

    /**
     * @var Collection|null
     * @Doctrine\ORM\Mapping\OneToMany(
     *     targetEntity="OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantFlag",
     *     mappedBy="participantFlagGroup",
     *     cascade={"all"},
     *     fetch="EAGER",
     * )
     * @Symfony\Component\Serializer\Annotation\MaxDepth(1)
     */
    protected ?Collection $participantFlags = null;

    public function __construct(?RegistrationFlagGroupOffer $flagGroupRange = null)
    {
        $this->participantFlags = new ArrayCollection();
        $this->tempFlagRanges = new ArrayCollection();
        $this->flagGroupOffer = $flagGroupRange;
    }

    public function getFlagType(): ?string
    {
        return $this->getFlagGroupOffer()?->getType();
    }

    public function getFlagGroupOffer(): ?RegistrationFlagGroupOffer
    {
        return $this->flagGroupOffer;
    }

    /**
     * @param  RegistrationFlagGroupOffer|null  $flagGroupOffer
     *
     * @throws NotImplementedException
     */
    public function setFlagGroupOffer(?RegistrationFlagGroupOffer $flagGroupOffer): void
    {
        if ($this->flagGroupOffer === $flagGroupOffer || null === $this->flagGroupOffer) {
            $this->flagGroupOffer = $flagGroupOffer;

            return;
        }
        throw new NotImplementedException('změna kategorie příznaků', 'v přiřazení kategorie příznaků k účastníkovi');
    }

    public function getFlagCategory(): ?RegistrationFlagCategory
    {
        return $this->getFlagGroupOffer()?->getFlagCategory();
    }

    public function getName(): ?string
    {
        return $this->getFlagGroupOffer()?->getName();
    }

    public function isPublicOnWeb(): bool
    {
        return $this->getFlagGroupOffer()?->isPublicOnWeb() ?? false;
    }

    public function getPrice(): int
    {
        $price = 0;
        foreach ($this->getParticipantFlags() as $flagRange) {
            $price += $flagRange instanceof RegistrationFlagOffer ? $flagRange->getPrice() : 0;
        }

        return $price;
    }

    public function getParticipantFlags(bool $onlyActive = false, ?RegistrationFlag $flag = null): Collection
    {
        $participantFlags = $this->participantFlags ?? new ArrayCollection();
        if ($onlyActive) {
            $participantFlags->filter(fn(ParticipantFlag $pFlag) => $pFlag->isActive());
        }
        if (null !== $flag) {
            $participantFlags->filter(fn(ParticipantFlag $pFlag) => $pFlag->getFlag() === $flag);
        }

        return $participantFlags;
    }

    /**
     * @param  Collection|null  $newParticipantFlags
     * @param  bool|false  $admin
     * @param  bool  $onlySimulate
     *
     * @throws FlagCapacityExceededException
     * @throws FlagOutOfRangeException
     */
    public function setParticipantFlags(?Collection $newParticipantFlags, bool $admin = false, bool $onlySimulate = false): void
    {
        $oldParticipantFlags = $this->getParticipantFlags();
        $newParticipantFlags ??= new ArrayCollection();
        // 1. All flags are of allowed category.
//        if (!$newParticipantFlags->forAll(fn(ParticipantFlag $newPFlag) => $this->getAvailableFlagRanges()->contains($newPFlag->getFlagRange()))) {
//            throw new FlagOutOfRangeException('Příznak není kompatibilní s příslušnou skupinou příznaků.');
//        }
        // 2. Number of flags is in range of minInParticipant and maxInParticipant of RegistrationFlagGroupOffer. OK
        $this->getFlagGroupOffer()?->checkInRange($newParticipantFlags->count());
        // 3. minInParticipant and maxInParticipant of each RegistrationFlagOffer from RegistrationFlagGroupOffer. + 4. There is remaining capacity of each flag.
        foreach ($this->getAvailableFlagOffers() as $availFlagRange) {
            $this->checkAvailableFlagRange($availFlagRange, $newParticipantFlags, $admin);
        }
        if (!$onlySimulate) {
            $removedFlags = $oldParticipantFlags->filter(static fn(ParticipantFlag $flag) => !$newParticipantFlags->contains($flag));
            $addedFlags = $newParticipantFlags->filter(static fn(ParticipantFlag $flag) => !$oldParticipantFlags->contains($flag));
            foreach ($removedFlags as $removedFlag) {
                assert($removedFlag instanceof ParticipantFlag);
                $removedFlag->delete();
            }
            foreach ($addedFlags as $addedFlag) {
                assert($addedFlag instanceof ParticipantFlag);
                $addedFlag->activate();
                try {
                    $addedFlag->setParticipantFlagGroup($this);
                } catch (NotImplementedException) {
                }
            }
            $this->participantFlags = $newParticipantFlags;
        }
    }

    public function getAvailableFlagOffers(bool $onlyPublic = false): Collection
    {
        return $this->getFlagGroupOffer()?->getFlagOffers($onlyPublic) ?? new ArrayCollection();
    }

    /**
     * @param  RegistrationFlagOffer  $flagRange
     * @param  Collection  $newPartiFlags
     * @param  bool|false  $admin
     *
     * @throws FlagOutOfRangeException|FlagCapacityExceededException
     */
    private function checkAvailableFlagRange(RegistrationFlagOffer $flagRange, Collection $newPartiFlags, bool $admin = false): void
    {
        $newFlagRangeCount = $newPartiFlags->filter(fn(ParticipantFlag $pFlag) => $pFlag->getFlagOffer() === $flagRange)->count();
        $flagRange->checkInRange($newFlagRangeCount);
        $oldFlagRangeCount = $this->getParticipantFlags()->filter(fn(ParticipantFlag $pFlag) => $pFlag->getFlagOffer() === $flagRange)->count();
        $differenceCount = $newFlagRangeCount - $oldFlagRangeCount;
        if ($differenceCount > 0 && $flagRange->getRemainingCapacity($admin) < $differenceCount) {
            throw new FlagCapacityExceededException($flagRange->getName());
        }
    }

    public function getActiveParticipantFlags(?RegistrationFlag $flag = null): Collection
    {
        return $this->getParticipantFlags(true, $flag);
    }

    public function addParticipantFlag(ParticipantFlag $participantFlag): void
    {
        $participantFlag->activate();
        if ($participantFlag->getParticipantFlagGroup() !== $this) {
            try {
                $participantFlag->setParticipantFlagGroup($this);
            } catch (NotImplementedException) {
            }
        }
    }

    public function removeParticipantFlag(ParticipantFlag $participantFlag): void
    {
        if ($participantFlag->getParticipantFlagGroup() === $this) {
            $participantFlag->delete();
        }
    }

    /**
     * @param  ParticipantFlag  $oldParticipantFlag
     * @param  ParticipantFlag  $newParticipantFlag
     * @param  bool  $admin
     *
     * @throws FlagCapacityExceededException
     * @throws FlagOutOfRangeException
     * @throws NotImplementedException
     */
    public function replaceParticipantFlag(
        ParticipantFlag $oldParticipantFlag,
        ParticipantFlag $newParticipantFlag,
        bool $admin = false,
    ): void {
        $oldFlagRange = $oldParticipantFlag->getFlagOffer();
        $newFlagRange = $newParticipantFlag->getFlagOffer();
        if ($oldFlagRange !== $newFlagRange) {
            if (!($newFlagRange instanceof RegistrationFlagOffer)) {
                throw new FlagOutOfRangeException("Příznak musí být nahrazen, nesmí být smazán.");
            }
            if (0 === $newFlagRange->getRemainingCapacity($admin)) {
                throw new FlagCapacityExceededException($newFlagRange->getName());
            }
            $oldParticipantFlag->delete();
            $newParticipantFlag->activate();
            $newParticipantFlag->setParticipantFlagGroup($this);
        }
    }

    public function getDepositValue(): int
    {
        $price = 0;
        foreach ($this->getParticipantFlags() as $flagRange) {
            $price += $flagRange instanceof RegistrationFlagOffer ? $flagRange->getPrice() : 0;
        }

        return $price;
    }

    public function delete(?DateTime $dateTime = null): void
    {
        foreach ($this->getParticipantFlags() as $participantFlag) {
            if ($participantFlag instanceof ParticipantFlag) {
                $participantFlag->delete($dateTime);
            }
        }
        $this->traitDelete($dateTime);
    }

    public function isActive(): bool
    {
        return !$this->isDeleted();
    }

    public function getMin(): ?int
    {
        return $this->getFlagGroupOffer()?->getMin() ?? 0;
    }

    public function getMax(): ?int
    {
        return $this->getFlagGroupOffer()?->getMax();
    }
}
