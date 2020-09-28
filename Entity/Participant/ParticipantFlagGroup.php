<?php
/**
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Entity\Participant;

use DateTime;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use OswisOrg\OswisCalendarBundle\Entity\Registration\Flag;
use OswisOrg\OswisCalendarBundle\Entity\Registration\FlagCategory;
use OswisOrg\OswisCalendarBundle\Entity\Registration\FlagGroupRange;
use OswisOrg\OswisCalendarBundle\Entity\Registration\FlagRange;
use OswisOrg\OswisCalendarBundle\Exception\FlagCapacityExceededException;
use OswisOrg\OswisCalendarBundle\Exception\FlagOutOfRangeException;
use OswisOrg\OswisCoreBundle\Exceptions\NotImplementedException;
use OswisOrg\OswisCoreBundle\Exceptions\OswisException;
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
     * @Doctrine\ORM\Mapping\ManyToOne(targetEntity="OswisOrg\OswisCalendarBundle\Entity\Registration\FlagGroupRange", fetch="EAGER")
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     */
    protected ?FlagGroupRange $flagGroupRange = null;

    /**
     * @var Collection|null
     * @Doctrine\ORM\Mapping\OneToMany(
     *     targetEntity="OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantFlag", mappedBy="participantFlagGroup", cascade={"all"}
     * )
     * @Symfony\Component\Serializer\Annotation\MaxDepth(1)
     */
    protected ?Collection $participantFlags = null;

    public function __construct(?FlagGroupRange $flagGroupRange = null)
    {
        $this->participantFlags = new ArrayCollection();
        $this->tempFlagRanges = new ArrayCollection();
        $this->flagGroupRange = $flagGroupRange;
    }

    public function getFlagType(): ?string
    {
        return $this->getFlagGroupRange() ? $this->getFlagGroupRange()->getType() : null;
    }

    public function getFlagGroupRange(): ?FlagGroupRange
    {
        return $this->flagGroupRange;
    }

    /**
     * @param FlagGroupRange|null $flagGroupRange
     *
     * @throws NotImplementedException
     */
    public function setFlagGroupRange(?FlagGroupRange $flagGroupRange): void
    {
        if ($this->flagGroupRange === $flagGroupRange || null === $this->flagGroupRange) {
            $this->flagGroupRange = $flagGroupRange;

            return;
        }
        throw new NotImplementedException('změna kategorie příznaků', 'v přiřazení kategorie příznaků k účastníkovi');
    }

    public function getFlagCategory(): ?FlagCategory
    {
        return $this->getFlagGroupRange() ? $this->getFlagGroupRange()->getFlagCategory() : null;
    }

    public function isPublicOnWeb(): bool
    {
        return $this->getFlagGroupRange() ? $this->getFlagGroupRange()->isPublicOnWeb() : false;
    }

    public function getPrice(): int
    {
        $price = 0;
        foreach ($this->getParticipantFlags() as $flagRange) {
            $price += $flagRange instanceof FlagRange ? $flagRange->getVariableSymbol() : 0;
        }

        return $price;
    }

    /**
     * @param bool      $onlyActive
     * @param Flag|null $flag
     *
     * @return Collection
     */
    public function getParticipantFlags(bool $onlyActive = false, ?Flag $flag = null): Collection
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
     * @param Collection|null $newParticipantFlags
     * @param bool|false      $admin
     * @param bool            $onlySimulate
     *
     * @throws FlagCapacityExceededException
     * @throws FlagOutOfRangeException
     * @throws OswisException
     */
    public function setParticipantFlags(?Collection $newParticipantFlags, bool $admin = false, bool $onlySimulate = false): void
    {
        $newParticipantFlags ??= new ArrayCollection();
        if ($this->getParticipantFlags() === $newParticipantFlags) {
            return; // Lists are same.
        }
        // 1. All flags are of allowed category.
//        if (!$newParticipantFlags->forAll(fn(ParticipantFlag $newPFlag) => $this->getAvailableFlagRanges()->contains($newPFlag->getFlagRange()))) {
//            throw new FlagOutOfRangeException('Příznak není kompatibilní s příslušnou skupinou příznaků.');
//        }
        // 2. Number of flags is in range of minInParticipant and maxInParticipant of FlagGroupRange. OK
        if (null !== $this->getFlagGroupRange()) {
            $this->getFlagGroupRange()->checkInRange($newParticipantFlags->count());
        }
        // 3. minInParticipant and maxInParticipant of each FlagRange from FlagGroupRange. + 4. There is remaining capacity of each flag.
        foreach ($this->getAvailableFlagRanges() as $availFlagRange) {
            $this->checkAvailableFlagRange($availFlagRange, $newParticipantFlags, $admin);
        }
        if (!$onlySimulate) {
            foreach ($newParticipantFlags as $newParticipantFlag) {
                if (!($newParticipantFlag instanceof ParticipantFlag)) {
                    throw new OswisException("Příznak není typu ParticipantFlag (je typu '".gettype($newParticipantFlag).", ".get_class($newParticipantFlag)."'). ");
                }
                $newParticipantFlag->activate();
                try {
                    $newParticipantFlag->setParticipantFlagGroup($this);
                } catch (NotImplementedException $e) {
                }
            }
            $this->participantFlags = $newParticipantFlags;
        }
    }

    public function getAvailableFlagRanges(bool $onlyPublic = false): Collection
    {
        return $this->getFlagGroupRange() ? $this->getFlagGroupRange()->getFlagRanges($onlyPublic) : new ArrayCollection();
    }

    /**
     * @param FlagRange  $flagRange
     * @param Collection $newPartiFlags
     * @param bool|false $admin
     *
     * @throws FlagOutOfRangeException|FlagCapacityExceededException
     */
    private function checkAvailableFlagRange(FlagRange $flagRange, Collection $newPartiFlags, bool $admin = false): void
    {
        $newFlagRangeCount = $newPartiFlags->filter(fn(ParticipantFlag $pFlag) => $pFlag->getFlagRange() === $flagRange)->count();
        $flagRange->checkInRange($newFlagRangeCount);
        $oldFlagRangeCount = $this->getParticipantFlags()->filter(fn(ParticipantFlag $pFlag) => $pFlag->getFlagRange() === $flagRange)->count();
        $differenceCount = $newFlagRangeCount - $oldFlagRangeCount;
        if ($differenceCount > 0 && $flagRange->getRemainingCapacity($admin) < $differenceCount) {
            throw new FlagCapacityExceededException($flagRange->getName());
        }
    }

    /**
     * @param ParticipantFlag $oldParticipantFlag
     * @param ParticipantFlag $newParticipantFlag
     * @param bool            $admin
     *
     * @throws FlagCapacityExceededException
     * @throws FlagOutOfRangeException
     * @throws NotImplementedException
     */
    public function replaceParticipantFlag(ParticipantFlag $oldParticipantFlag, ParticipantFlag $newParticipantFlag, bool $admin = false): void
    {
        $oldFlagRange = $oldParticipantFlag->getFlagRange();
        $newFlagRange = $newParticipantFlag->getFlagRange();
        if ($oldFlagRange !== $newFlagRange) {
            if (null === $newFlagRange || !($newFlagRange instanceof FlagRange)) {
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
            $price += $flagRange instanceof FlagRange ? $flagRange->getVariableSymbol() : 0;
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
}
