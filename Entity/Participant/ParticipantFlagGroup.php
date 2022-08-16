<?php
/**
 * @noinspection PhpUnused
 * @noinspection PropertyCanBePrivateInspection
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Entity\Participant;

use DateTime;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping\Cache;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\OneToMany;
use Doctrine\ORM\Mapping\Table;
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
use Symfony\Component\Serializer\Annotation\MaxDepth;

/**
 * @ApiPlatform\Core\Annotation\ApiResource(
 *   attributes={
 *     "filters"={"search"},
 *     "security"="is_granted('ROLE_MANAGER')"
 *   },
 *   collectionOperations={
 *     "get"={
 *       "security"="is_granted('ROLE_MANAGER')",
 *       "normalization_context"={"groups"={"entities_get", "calendar_participant_flag_groups_get"},
 *     "enable_max_depth"=true},
 *     },
 *     "post"={
 *       "security"="is_granted('ROLE_MANAGER')",
 *       "denormalization_context"={"groups"={"entities_post", "calendar_participant_flag_groups_post"},
 *     "enable_max_depth"=true}
 *     }
 *   },
 *   itemOperations={
 *     "get"={
 *       "security"="is_granted('ROLE_MANAGER')",
 *       "normalization_context"={"groups"={"entity_get", "calendar_participant_flag_group_get"},
 *     "enable_max_depth"=true},
 *     },
 *     "put"={
 *       "security"="is_granted('ROLE_MANAGER')",
 *       "denormalization_context"={"groups"={"entity_put", "calendar_participant_flag_group_put"},
 *     "enable_max_depth"=true}
 *     }
 *   }
 * )
 */
#[Entity]
#[Table(name: 'calendar_participant_flag_group')]
#[Cache(usage: 'NONSTRICT_READ_WRITE', region: 'calendar_participant')]
class ParticipantFlagGroup implements BasicInterface, TextValueInterface, DeletedInterface
{
    use BasicTrait;
    use TextValueTrait;
    use DeletedTrait {
        delete as traitDelete;
    }

    public Collection $tempFlagRanges;
    #[ManyToOne(targetEntity: RegistrationFlagGroupOffer::class, fetch: 'EAGER')]
    #[JoinColumn(nullable: true)]
    protected ?RegistrationFlagGroupOffer $flagGroupOffer = null;

    /**
     * @var Collection<ParticipantFlag>
     */
    #[OneToMany(mappedBy: 'participantFlagGroup', targetEntity: ParticipantFlag::class, cascade: ['all'], fetch: 'EAGER')]
    #[MaxDepth(1)]
    protected Collection $participantFlags;

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
     * @param RegistrationFlagGroupOffer|null $flagGroupOffer
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

    public function isPublicOnWeb(): bool
    {
        return $this->getFlagGroupOffer()?->isPublicOnWeb() ?? false;
    }

    public function getActiveParticipantFlags(?RegistrationFlag $flag = null): Collection
    {
        return $this->getParticipantFlags(true, $flag);
    }

    /**
     * @param bool                  $onlyActive
     * @param RegistrationFlag|null $flag
     *
     * @return Collection<ParticipantFlag>
     */
    public function getParticipantFlags(bool $onlyActive = false, ?RegistrationFlag $flag = null): Collection
    {
        $participantFlags = $this->participantFlags;
        if ($onlyActive) {
            $participantFlags = $participantFlags->filter(fn(mixed $pFlag) => ($pFlag instanceof ParticipantFlag)
                                                                              && $pFlag->isActive(),);
        }
        if (null !== $flag) {
            $participantFlags = $participantFlags->filter(fn(mixed $pFlag) => $pFlag instanceof ParticipantFlag
                                                                              && ($pFlag->getFlag() === $flag),);
        }

        /** @var Collection<ParticipantFlag> $participantFlags */
        return $participantFlags;
    }

    /**
     * @param Collection|null $newParticipantFlags
     * @param bool|false      $admin
     * @param bool            $onlySimulate
     *
     * @throws FlagCapacityExceededException
     * @throws FlagOutOfRangeException
     */
    public function setParticipantFlags(
        ?Collection $newParticipantFlags,
        bool $admin = false,
        bool $onlySimulate = false
    ): void
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
            /** @var RegistrationFlagOffer $availFlagRange */
            $this->checkAvailableFlagRange($availFlagRange, $newParticipantFlags, $admin);
        }
        if (!$onlySimulate) {
            $removedFlags = $oldParticipantFlags->filter(static fn(mixed $flag) => $flag instanceof ParticipantFlag
                                                                                   && !$newParticipantFlags->contains(
                    $flag
                ),);
            $addedFlags = $newParticipantFlags->filter(static fn(mixed $flag) => $flag instanceof ParticipantFlag
                                                                                 && !$oldParticipantFlags->contains(
                    $flag
                ),);
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

    public function isActive(): bool
    {
        return !$this->isDeleted();
    }

    public function getAvailableFlagOffers(bool $onlyPublic = false): Collection
    {
        return $this->getFlagGroupOffer()?->getFlagOffers($onlyPublic) ?? new ArrayCollection();
    }

    /**
     * @param RegistrationFlagOffer $flagRange
     * @param Collection            $newPartiFlags
     * @param bool|false            $admin
     *
     * @throws FlagOutOfRangeException|FlagCapacityExceededException
     */
    private function checkAvailableFlagRange(
        RegistrationFlagOffer $flagRange,
        Collection $newPartiFlags,
        bool $admin = false,
    ): void {
        $newFlagRangeCount = $newPartiFlags->filter(fn(mixed $pFlag) => $pFlag instanceof ParticipantFlag
                                                                        && $pFlag->getFlagOffer() === $flagRange,)
                                           ->count();
        $flagRange->checkInRange($newFlagRangeCount);
        $oldFlagRangeCount = $this->getParticipantFlags()->filter(fn(mixed $pFlag) => $pFlag instanceof ParticipantFlag
                                                                                      && $pFlag->getFlagOffer()
                                                                                         === $flagRange,)->count();
        $differenceCount = $newFlagRangeCount - $oldFlagRangeCount;
        if ($differenceCount > 0 && $flagRange->getRemainingCapacity($admin) < $differenceCount) {
            throw new FlagCapacityExceededException($flagRange->getName());
        }
    }

    public function getName(): ?string
    {
        return $this->getFlagGroupOffer()?->getName();
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
     * @param ParticipantFlag $oldParticipantFlag
     * @param ParticipantFlag $newParticipantFlag
     * @param bool            $admin
     * @param bool            $onlySimulate
     *
     * @throws \OswisOrg\OswisCalendarBundle\Exception\FlagCapacityExceededException
     * @throws \OswisOrg\OswisCalendarBundle\Exception\FlagOutOfRangeException
     * @throws \OswisOrg\OswisCoreBundle\Exceptions\NotImplementedException
     */
    public function replaceParticipantFlag(
        ParticipantFlag $oldParticipantFlag,
        ParticipantFlag $newParticipantFlag,
        bool $admin = false,
        bool $onlySimulate = false,
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
            if (!$onlySimulate) {
                $oldParticipantFlag->delete();
                $newParticipantFlag->activate();
                $newParticipantFlag->setParticipantFlagGroup($this);
            }
        }
    }

    public function getDepositValue(): int
    {
        $price = 0;
        foreach ($this->getParticipantFlags() as $flagRange) {
            /** @var RegistrationFlagOffer $flagRange */
            $price += $flagRange->getDepositValue();
        }

        return $price;
    }

    public function getPrice(): int
    {
        $price = 0;
        foreach ($this->getParticipantFlags() as $flagRange) {
            /** @var RegistrationFlagOffer $flagRange */
            $price += $flagRange->getPrice();
        }

        return $price;
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
