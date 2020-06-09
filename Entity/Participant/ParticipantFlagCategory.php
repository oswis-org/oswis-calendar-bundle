<?php
/**
 * @noinspection MethodShouldBeFinalInspection
 * @noinspection PhpUnused
 */

namespace OswisOrg\OswisCalendarBundle\Entity\Participant;

use DateTime;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use OswisOrg\OswisCalendarBundle\Entity\Event\RegistrationFlag;
use OswisOrg\OswisCalendarBundle\Entity\Event\RegistrationFlagCategory;
use OswisOrg\OswisCalendarBundle\Entity\Event\RegistrationFlagCategoryRange;
use OswisOrg\OswisCalendarBundle\Entity\Event\RegistrationFlagRange;
use OswisOrg\OswisCoreBundle\Exceptions\InvalidTypeException;
use OswisOrg\OswisCoreBundle\Exceptions\OswisNotImplementedException;
use OswisOrg\OswisCoreBundle\Interfaces\Common\BasicInterface;
use OswisOrg\OswisCoreBundle\Traits\Common\BasicTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\DeletedMailConfirmationTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\TextValueTrait;

/**
 * Flag assigned to event participant (ie. special food requirement...) through some "flag range".
 * @Doctrine\ORM\Mapping\Entity(repositoryClass="OswisOrg\OswisCalendarBundle\Repository\ParticipantFlagRangeConnectionRepository")
 * @Doctrine\ORM\Mapping\Table(name="calendar_participant_category_connection")
 * @Doctrine\ORM\Mapping\Cache(usage="NONSTRICT_READ_WRITE", region="calendar_participant")
 */
class ParticipantFlagCategory implements BasicInterface
{
    use BasicTrait;
    use TextValueTrait;
    use DeletedMailConfirmationTrait;

    /**
     * Event contact flag.
     * @Doctrine\ORM\Mapping\ManyToOne(targetEntity="OswisOrg\OswisCalendarBundle\Entity\Event\RegistrationFlagCategoryRange", fetch="EAGER")
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     */
    protected ?RegistrationFlagCategoryRange $flagCategoryRange = null;

    /**
     * @Doctrine\ORM\Mapping\OneToMany(
     *     targetEntity="OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantFlag", mappedBy="participantFlagCategory"
     * )
     */
    protected ?Collection $participantFlags = null;

    /**
     * ParticipantFlagCategory constructor.
     *
     * @param RegistrationFlagCategoryRange|null $flagCategoryRange
     * @param Collection|null                    $participantFlags
     *
     * @throws InvalidTypeException
     */
    public function __construct(?RegistrationFlagCategoryRange $flagCategoryRange = null, ?Collection $participantFlags = null)
    {
        $this->setFlagCategoryRange($flagCategoryRange);
        $this->setParticipantFlags($participantFlags);
    }

    /**
     * @param Collection|null $newParticipantFlags
     *
     * @throws InvalidTypeException
     */
    public function setParticipantFlags(?Collection $newParticipantFlags): void
    {
        $this->participantFlags ??= new ArrayCollection();
        $newParticipantFlags ??= new ArrayCollection();
        foreach ($this->getParticipantFlags() as $oldParticipantFlag) {
            if (!$newParticipantFlags->contains($oldParticipantFlag)) {
                $this->removeParticipantFlag($oldParticipantFlag);
            }
        }
        foreach ($newParticipantFlags as $newParticipantFlag) {
            if (!$this->getParticipantFlags()->contains($newParticipantFlag)) {
                $this->addParticipantFlag($newParticipantFlag);
            }
        }
    }

    public function getFlagType(): ?string
    {
        return $this->getFlagCategoryRange() ? $this->getFlagCategoryRange()->getType() : null;
    }

    public function getFlagCategory(): ?RegistrationFlagCategory
    {
        return $this->getFlagCategoryRange() ? $this->getFlagCategoryRange()->getCategory() : null;
    }

    public function isPublicOnWeb(): bool {
        return $this->getFlagCategoryRange() ? $this->getFlagCategoryRange()->isPublicOnWeb() : false;
    }

    public function getFlagCategoryRange(): ?RegistrationFlagCategoryRange
    {
        return $this->flagCategoryRange;
    }

    public function setFlagCategoryRange(?RegistrationFlagCategoryRange $flagCategoryRange): void
    {
        if ($this->flagCategoryRange === $flagCategoryRange || null === $this->flagCategoryRange) {
            $this->flagCategoryRange = $flagCategoryRange;

            return;
        }
        throw new OswisNotImplementedException('změna kategorie příznaků', 'v přiřazení kategorie příznaků k účastníkovi');
    }

    public function getPrice(): int
    {
        $price = 0;
        foreach ($this->getParticipantFlags() as $flagRange) {
            $price += $flagRange instanceof RegistrationFlagRange ? $flagRange->getPrice() : 0;
        }

        return $price;
    }

    public function isActive(?DateTime $referenceDateTime = null): bool
    {
        return !$this->isDeleted($referenceDateTime);
    }

    public function getDepositValue(): int
    {
        $price = 0;
        foreach ($this->getParticipantFlags() as $flagRange) {
            $price += $flagRange instanceof RegistrationFlagRange ? $flagRange->getPrice() : 0;
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
     * @param ParticipantFlag|null $participantFlag
     *
     * @throws InvalidTypeException
     */
    public function addParticipantFlag(?ParticipantFlag $participantFlag): void
    {
        // TODO: Do some checking.
        if (null !== $participantFlag && !$this->getParticipantFlags()->contains($participantFlag)) {
            if ($this->getFlagCategory() !== $participantFlag->getFlagCategory()) {
                throw new InvalidTypeException('příznaku', 'v kategorii');
            }
            $this->getParticipantFlags()->add($participantFlag);
            $participantFlag->setParticipantFlagCategory($this);
        }
    }

    public function removeParticipantFlag(?ParticipantFlag $event): void
    {
        if (null !== $event && $this->getParticipantFlags()->removeElement($event)) {
            try {
                $event->setParticipantFlagCategory(null);
            } catch (InvalidTypeException $e) {
            }
        }
    }

    public function getAvailableFlagRanges(bool $onlyPublic = false): Collection
    {
        return $this->getFlagCategoryRange() ? $this->getFlagCategoryRange()->getFlagRanges($onlyPublic) : new ArrayCollection();
    }

}
