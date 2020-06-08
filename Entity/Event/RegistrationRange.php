<?php
/**
 * @noinspection PhpUnused
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Entity\Event;

use DateTime;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Exception;
use OswisOrg\OswisCalendarBundle\Entity\NonPersistent\Capacity;
use OswisOrg\OswisCalendarBundle\Entity\NonPersistent\Price;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantCategory;
use OswisOrg\OswisCalendarBundle\Exception\EventCapacityExceededException;
use OswisOrg\OswisCalendarBundle\Traits\Entity\CapacityTrait;
use OswisOrg\OswisCalendarBundle\Traits\Entity\CapacityUsageTrait;
use OswisOrg\OswisCalendarBundle\Traits\Entity\PriceTrait;
use OswisOrg\OswisCoreBundle\Entity\NonPersistent\DateTimeRange;
use OswisOrg\OswisCoreBundle\Entity\NonPersistent\Nameable;
use OswisOrg\OswisCoreBundle\Entity\NonPersistent\Publicity;
use OswisOrg\OswisCoreBundle\Exceptions\OswisNotImplementedException;
use OswisOrg\OswisCoreBundle\Interfaces\Common\NameableInterface;
use OswisOrg\OswisCoreBundle\Traits\Common\DateRangeTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\EntityPublicTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\NameableTrait;

/**
 * Time range available for registrations of participants of some type to some event (with some price, capacity...).
 * @Doctrine\ORM\Mapping\Entity(repositoryClass="OswisOrg\OswisCalendarBundle\Repository\RegistrationsRangeRepository")
 * @Doctrine\ORM\Mapping\Table(name="calendar_registrations_range")
 * @Doctrine\ORM\Mapping\Cache(usage="NONSTRICT_READ_WRITE", region="calendar_event")
 * @todo Implement: Check capacity of required "super" ranges (add somehow participant to them?).
 */
class RegistrationRange implements NameableInterface
{
    use NameableTrait;
    use PriceTrait {
        getPrice as protected traitGetPrice;
        getDepositValue as protected traitGetDeposit;
    }
    use CapacityTrait;
    use CapacityUsageTrait;
    use DateRangeTrait { // Range when registrations are allowed with this price.
        setEndDateTime as protected traitSetEnd;
    }
    use EntityPublicTrait;

    /**
     * @Doctrine\ORM\Mapping\ManyToOne(targetEntity="RegistrationRange", fetch="EAGER")
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     */
    protected ?RegistrationRange $requiredRange;

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
     * @Doctrine\ORM\Mapping\ManyToMany(targetEntity="OswisOrg\OswisCalendarBundle\Entity\Event\RegistrationFlagCategoryRange", cascade={"all"})
     * @Doctrine\ORM\Mapping\JoinTable(
     *      name="registration_range_flag_category_range",
     *      joinColumns={@Doctrine\ORM\Mapping\JoinColumn(name="registration_range_id", referencedColumnName="id")},
     *      inverseJoinColumns={@Doctrine\ORM\Mapping\JoinColumn(name="registration_flag_category_range_id", referencedColumnName="id")}
     * )
     */
    protected ?Collection $flagCategoryRanges = null;

    /**
     * Indicates that price is relative to required range.
     * @Doctrine\ORM\Mapping\Column(type="boolean", nullable=true)
     * @todo Implement: Indicates that capacity is relative to required range too.
     */
    protected ?bool $relative = null;

    /**
     * Indicates that participation on super event is required.
     * @Doctrine\ORM\Mapping\Column(type="boolean", nullable=true)
     */
    protected ?bool $superEventRequired = null;

    /**
     * RegistrationsRange constructor.
     *
     * @param Nameable|null            $nameable
     * @param Event|null               $event
     * @param ParticipantCategory|null $participantType
     * @param Price|null               $eventPrice
     * @param DateTimeRange|null       $dateTimeRange
     * @param bool|null                $isRelative
     * @param RegistrationRange|null   $requiredRange
     * @param Capacity|null            $eventCapacity
     * @param Publicity|null           $publicity
     * @param bool|null                $superEventRequired
     *
     * @throws OswisNotImplementedException
     */
    public function __construct(
        ?Nameable $nameable = null,
        ?Event $event = null,
        ?ParticipantCategory $participantType = null,
        ?Price $eventPrice = null,
        ?DateTimeRange $dateTimeRange = null,
        ?bool $isRelative = null,
        ?RegistrationRange $requiredRange = null,
        ?Capacity $eventCapacity = null,
        Publicity $publicity = null,
        ?bool $superEventRequired = null
    ) {
        $this->flagCategoryRanges = new ArrayCollection();
        $this->setFieldsFromNameable($nameable);
        $this->setEvent($event);
        $this->setParticipantType($participantType);
        $this->setEventPrice($eventPrice);
        $this->setStartDateTime($dateTimeRange ? $dateTimeRange->startDateTime : null);
        $this->setEndDateTime($dateTimeRange ? $dateTimeRange->endDateTime : null, true);
        $this->setCapacity($eventCapacity);
        $this->setRelative($isRelative);
        $this->setRequiredRange($requiredRange);
        $this->setFieldsFromPublicity($publicity);
        $this->setSuperEventRequired($superEventRequired);
    }

    /**
     * Sets the end of registration range (can't be set to past).
     *
     * @param DateTime|null $endDateTime
     * @param bool|null     $force
     */
    public function setEndDateTime(?DateTime $endDateTime, ?bool $force = null): void
    {
        try {
            $now = new DateTime();
            if ($endDateTime !== $this->getEndDate()) {
                $this->traitSetEnd(!$force && $endDateTime && $endDateTime < $now ? $now : $endDateTime);
            }
        } catch (Exception $e) {
        }
    }

    public function setRelative(?bool $relative): void
    {
        $this->relative = $relative;
    }

    public function setSuperEventRequired(?bool $superEventRequired): void
    {
        $this->superEventRequired = $superEventRequired;
    }

    public function getRequiredRangePrice(?ParticipantCategory $participantType = null): int
    {
        return (!$this->isRelative() || null === $this->getRequiredRange()) ? 0 : $this->getRequiredRange()->getPrice($participantType);
    }

    public function isRelative(): bool
    {
        return $this->relative ?? false;
    }

    public function getRequiredRange(): ?RegistrationRange
    {
        return $this->requiredRange;
    }

    public function setRequiredRange(?RegistrationRange $requiredRange): void
    {
        $this->requiredRange = $requiredRange;
    }

    public function getPrice(?ParticipantCategory $participantType = null): int
    {
        if (null === $participantType) {
            return $this->traitGetPrice();
        }
        $price = $this->getParticipantType() === $participantType ? $this->traitGetPrice() : 0;
        $price += $this->getRequiredRangePrice($participantType);

        return null !== $price && $price <= 0 ? 0 : $price;
    }

    public function getParticipantType(): ?ParticipantCategory
    {
        return $this->participantType;
    }

    /**
     * @param ParticipantCategory|null $participantType
     *
     * @throws OswisNotImplementedException
     */
    public function setParticipantType(?ParticipantCategory $participantType): void
    {
        if (null === $this->participantType || $this->participantType === $participantType) {
            $this->participantType = $participantType;

            return;
        }
        throw new OswisNotImplementedException('změna typu účastníka', 'v rozsahu registrací');
    }

    public function getRequiredRangeDeposit(?ParticipantCategory $participantType = null): int
    {
        return (!$this->isRelative() || null === $this->getRequiredRange()) ? 0 : $this->getRequiredRange()->getDepositValue($participantType);
    }

    public function getDepositValue(?ParticipantCategory $participantType = null, bool $recursive = true): int
    {
        if (false === $recursive || null === $participantType) {
            return $this->traitGetDeposit();
        }
        $price = $this->getParticipantType() === $participantType ? $this->traitGetDeposit() : 0;
        $price += $this->getRequiredRangeDeposit($participantType);

        return null !== $price && $price <= 0 ? 0 : $price;
    }

    /**
     * Checks capacity of range and flag ranges.
     *
     * @param Participant $participant
     * @param bool        $onlyPublic
     * @param bool|false  $max
     *
     * @throws EventCapacityExceededException
     */
    public function simulateAdd(Participant $participant = null, bool $onlyPublic = true, bool $max = false): void
    {
        $this->simulateParticipantAdd($max);
        if (null !== $participant) {
            $this->simulateFlagsAdd($participant->getFlagsAggregatedByType(), $onlyPublic, $max);
        }
    }

    /**
     * Checks capacity of range.
     *
     * @param bool|false $max
     *
     * @throws EventCapacityExceededException
     */
    public function simulateParticipantAdd(bool $max = false): void
    {
        if (!$this->containsDateTimeInRange()) {
            $rangeText = $this->getRangeAsText();
            throw new EventCapacityExceededException("Přihlášky v tomto rozsahu aktuálně nejsou povoleny ($rangeText).");
        }
        $remainingCapacity = $this->getRemainingCapacity($max);
        if ((null !== $remainingCapacity && 1 > $remainingCapacity)) {
            throw new EventCapacityExceededException();
        }
    }

    public function getRemainingCapacity(bool $full = false): ?int
    {
        $capacity = $this->getCapacityInt($full);

        return null === $capacity ? null : ($capacity - $this->getUsageInt($full));
    }

    public function getBaseCapacity(bool $max = false): int
    {
        return $max ? $this->getFullCapacity() : $this->baseUsage;
    }

    public function setBaseCapacity(int $baseUsage): void
    {
        $this->baseUsage = $baseUsage;
    }

    public function getFullCapacity(): int
    {
        return $this->fullUsage;
    }

    public function setFullCapacity(int $fulUsage): void
    {
        $this->fullUsage = $fulUsage;
    }

    /**
     * Checks capacity of flag ranges.
     *
     * @param array|null $flagsAggregatedByType
     * @param bool       $onlyPublic
     * @param bool|false $max
     *
     * @throws EventCapacityExceededException
     */
    public function simulateFlagsAdd(?array $flagsAggregatedByType = null, bool $onlyPublic = true, bool $max = false): void
    {
        $this->checkFlagsExistence($flagsAggregatedByType, $this->getFlagsAggregatedByType());
        $this->checkFlagsRanges($flagsAggregatedByType, $this->getFlagsAggregatedByType());
        foreach ($flagsAggregatedByType as $typeSlug => $flagsOfType) {
            foreach ($flagsOfType as $flagSlug => $aggregatedFlag) {
                if ($flag = ($aggregatedFlag['flag'] instanceof RegistrationFlag ? $aggregatedFlag['flag'] : null)) {
                    $flagRemainingCapacity = $this->getFlagRemainingCapacity($flag, $onlyPublic, $max);
                    if (($aggregatedFlag['count'] ?? 0) > $flagRemainingCapacity) {
                        $flagName = $flag->getName();
                        throw new EventCapacityExceededException("Kapacita příznaku \"$flagName\" byla vyčerpána.");
                    }
                }
            }
        }
    }

    /**
     * Checks if type of each used flag can be used in event.
     *
     * @param array $flagsByTypes
     * @param array $allowedFlagsByTypes
     *
     * @throws EventCapacityExceededException
     */
    private function checkFlagsExistence(array $flagsByTypes, array $allowedFlagsByTypes): void
    {
        foreach ($flagsByTypes as $flagTypeSlug => $flagsOfType) {
            $firstFlagOfType = $flagsOfType['flag'] ?? null;
            if ($firstFlagOfType instanceof RegistrationFlag) {
                $flagType = $firstFlagOfType->getCategory();
                if ($flagType && !array_key_exists($flagType->getSlug(), $allowedFlagsByTypes)) {
                    $flagTypeName = $flagType->getName();
                    $message = "Příznak typu $flagTypeName není u této přihlášky povolen.";
                    throw new EventCapacityExceededException($message);
                }
            }
        }
    }

    public function getFlagsAggregatedByType(
        ?RegistrationFlagCategory $flagType = null,
        ?RegistrationFlag $flag = null,
        bool $onlyPublic = true,
        bool $full = false
    ): array {
        $out = [];
        foreach ($this->getFlagCategoryRanges($flagType, $flag, $onlyPublic) as $flagRange) {
            if (!($flagRange instanceof RegistrationFlagRange) || !($flagRange->getFlag() instanceof RegistrationFlag)) {
                continue;
            }
            $flagTypeSlug = $flagRange->getCategory() ? $flagRange->getCategory()->getSlug() : '0';
            $flagSlug = $flagRange->getFlag()->getSlug();
            $out[$flagTypeSlug] ??= [];
            $out[$flagTypeSlug][$flagSlug]['flagType'] = $flagRange->getCategory();
            $out[$flagTypeSlug][$flagSlug]['flag'] = $flagRange->getFlag();
            $capacity = $flagRange->getCapacityInt($full);
            $count = (true === array_key_exists('count', $out[$flagTypeSlug][$flagSlug])) ? $out[$flagTypeSlug][$flagSlug]['count'] : 0;
            $count = null === $capacity || null === $count ? null : $count + 1;
            $out[$flagTypeSlug][$flagSlug]['count'] = $count;
        }

        return $out;
    }

    public function getFlagCategoryRanges(?RegistrationFlagCategory $flagCategory = null, ?string $flagType = null, ?bool $onlyPublic = false): Collection {
        $flagCategoryRanges = $this->flagCategoryRanges ??= new ArrayCollection();
        if (true === $onlyPublic) {
            $flagCategoryRanges = $flagCategoryRanges->filter(fn(RegistrationFlagCategoryRange $range) => $range->isPublicOnWeb());
        }
        if (null !== $flagCategory) {
            $flagCategoryRanges = $flagCategoryRanges->filter(fn(RegistrationFlagCategoryRange $range) => $range->isCategory($flagCategory));
        }
        if (null !== $flagType) {
            $flagCategoryRanges = $flagCategoryRanges->filter(fn(RegistrationFlagCategoryRange $range) => $range->isType($flagCategory));
        }

        return $flagCategoryRanges;
    }

    public function checkParticipant(Participant $participant, bool $onlyPublic = false): void {
        $participantCategories = $participant->getFlagCategories();

    }

    /**
     * Checks if the amount of flags of each type meets range of that type.
     *
     * @param array $flagsByTypes
     * @param array $allowedFlagsByTypes
     *
     * @throws EventCapacityExceededException
     */
    private function checkFlagsRanges(array $flagsByTypes, array $allowedFlagsByTypes): void
    {
        foreach ($allowedFlagsByTypes as $flagTypeSlug => $flagsOfType) {
            $flagType = !empty($flagsOfType['flagType']) && $flagsOfType['flagType'] instanceof RegistrationFlagCategory ? $flagsOfType['flagType'] : null;
            $flagsOfTypeAmount = $flagsByTypes[$flagTypeSlug]['count'] ?? 0;
            $min = $flagType ? $flagType->getMinInParticipant() ?? 0 : 0;
            $max = $flagType ? $flagType->getMaxInParticipant() : null;
            if (null !== $flagType && ($min > $flagsOfTypeAmount || (null !== $max && $max < $flagsOfTypeAmount))) {
                $maxMessage = null === $max ? '' : "až $max";
                throw new EventCapacityExceededException("Musí být vybráno $min $maxMessage příznaků typu ".$flagType->getName().".");
            }
        }
    }

    public function getFlagRemainingCapacity(RegistrationFlag $flag, bool $onlyPublic = false, bool $max = false): ?int
    {
        $remaining = null;
        foreach ($this->getFlagCategoryRanges(null, $flag, $onlyPublic) as $flagRange) {
            if (!($flagRange instanceof RegistrationsFlagRange)) {
                continue;
            }
            $remainingInRange = $flagRange->getRemainingCapacity($max);
            if (null !== $remainingInRange) {
                $remaining += $remainingInRange;
            }
        }

        return $remaining;
    }

    public function getFlags(
        ?RegistrationFlagCategory $flagType = null,
        ?RegistrationFlag $flag = null,
        bool $onlyPublic = true,
        bool $max = false
    ): Collection {
        $out = new ArrayCollection();
        foreach ($this->getFlagCategoryRanges($flagType, $flag, $onlyPublic) as $flagRange) {
            if ($flagRange instanceof RegistrationsFlagRange) {
                $capacity = $flagRange->getCapacity($max);
                if (null === $capacity) { // TODO: Null capacity.
                    $out->add($flagRange->getFlag());
                }
                for ($i = 0; $i < $flagRange->getCapacity($max); $i++) {
                    $out->add($flagRange->getFlag());
                }
            }
        }

        return $out;
    }

    public function isApplicableByTypeOfType(?string $participantType = null, ?DateTime $dateTime = null): bool
    {
        if (null !== $participantType && (null === $this->getParticipantType() || $this->getParticipantType()->getType() !== $participantType)) {
            return false;
        }

        return null === $dateTime || $this->containsDateTimeInRange($dateTime);
    }

    /**
     * Participation in super event is required before registration to this range.
     *
     * Must be checked in service/controller.
     *
     * @return bool
     */
    public function isSuperEventRequired(): bool
    {
        return $this->superEventRequired ?? false;
    }

    public function isRangeActive(bool $max = false): bool
    {
        return $this->containsDateTimeInRange() && (null === $this->getCapacity($max) || 0 < $this->getCapacity($max));
    }

    public function getAllowedFlagRangesByTypeString(?string $flagType = null, ?RegistrationFlag $flag = null, ?bool $onlyPublic = false): Collection
    {
        return $this->getFlagCategoryRanges(null, $flag, $onlyPublic)->filter(
            fn(RegistrationsFlagRange $range) => $range->containsFlagOfTypeString($flagType)
        );
    }

    public function addFlagRange(?RegistrationsFlagRange $flagRange): void
    {
        if (null !== $flagRange && !$this->getFlagCategoryRanges()->contains($flagRange)) {
            $this->getFlagCategoryRanges()->add($flagRange);
        }
    }

    public function getFlagRange(RegistrationFlag $flag, bool $max = false, bool $onlyPublic = false): ?RegistrationsFlagRange
    {
        foreach ($this->getFlagCategoryRanges(null, $flag, $onlyPublic) as $range) {
            if ($range instanceof RegistrationsFlagRange && $range->getRemainingCapacity($max) !== 0) {
                return $range;
            }
        }

        return null;
    }

    /**
     * @param RegistrationFlagRange|null $flagRange
     *
     * @throws OswisNotImplementedException
     */
    public function removeFlagRange(?RegistrationsFlagRange $flagRange): void
    {
        if (null === $flagRange) {
            return;
        }
        if ($flagRange->getBaseCapacity() > 0) {
            throw new OswisNotImplementedException('Nelze odebrat již využitý rozsah.');
        }
        $this->getFlagCategoryRanges()->remove($flagRange);
    }

    public function isParticipantInSuperEvent(Participant $participant): bool
    {
        return $this->getEvent() && $this->getEvent()->isEventSuperEvent($participant->getEvent());
    }

    public function getEvent(): ?Event
    {
        return $this->event;
    }

    /**
     * @param Event|null $event
     *
     * @throws OswisNotImplementedException
     */
    public function setEvent(?Event $event): void
    {
        if (null === $this->event || $this->event === $event) {
            $this->event = $event;

            return;
        }
        throw new OswisNotImplementedException('změna události', 'v rozsahu registrací');
    }
}
