<?php
/**
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Entity\Participant;

use DateTime;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use OswisOrg\OswisCalendarBundle\Entity\Event\Event;
use OswisOrg\OswisCalendarBundle\Entity\Registration\ParticipantOffer;
use OswisOrg\OswisCoreBundle\Exceptions\NotImplementedException;
use OswisOrg\OswisCoreBundle\Interfaces\Common\BasicInterface;
use OswisOrg\OswisCoreBundle\Traits\Common\ActivatedTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\BasicTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\DeletedTrait;
use OswisOrg\OswisCoreBundle\Utils\DateTimeUtils;

/**
 * @Doctrine\ORM\Mapping\Entity()
 * @Doctrine\ORM\Mapping\Table(name="calendar_participant_range")
 * @ApiPlatform\Core\Annotation\ApiResource(
 *   attributes={
 *     "filters"={"search"},
 *     "security"="is_granted('ROLE_MANAGER')"
 *   },
 *   collectionOperations={
 *     "get"={
 *       "security"="is_granted('ROLE_MANAGER')",
 *       "normalization_context"={"groups"={"entities_get", "calendar_participant_ranges_get"}, "enable_max_depth"=true},
 *     },
 *     "post"={
 *       "security"="is_granted('ROLE_MANAGER')",
 *       "denormalization_context"={"groups"={"entities_post", "calendar_participant_ranges_post"}, "enable_max_depth"=true}
 *     }
 *   },
 *   itemOperations={
 *     "get"={
 *       "security"="is_granted('ROLE_MANAGER')",
 *       "normalization_context"={"groups"={"entity_get", "calendar_participant_range_get"}, "enable_max_depth"=true},
 *     },
 *     "put"={
 *       "security"="is_granted('ROLE_MANAGER')",
 *       "denormalization_context"={"groups"={"entity_put", "calendar_participant_range_put"}, "enable_max_depth"=true}
 *     }
 *   }
 * )
 * @Doctrine\ORM\Mapping\Cache(usage="NONSTRICT_READ_WRITE", region="calendar_participant")
 */
class OfferOfParticipant implements BasicInterface
{
    use BasicTrait;
    use ActivatedTrait;
    use DeletedTrait;

    /**
     * @Doctrine\ORM\Mapping\ManyToOne(
     *     targetEntity="OswisOrg\OswisCalendarBundle\Entity\Participant\Participant",
     *     fetch="EXTRA_LAZY",
     *     inversedBy="participantRanges",
     * )
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     */
    protected ?Participant $participant = null;

    /**
     * @Doctrine\ORM\Mapping\ManyToOne(targetEntity="RegistrationOffer", fetch="EAGER")
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     */
    protected ?ParticipantOffer $range = null;

    public function __construct(?ParticipantOffer $range = null)
    {
        try {
            $this->setRange($range);
        } catch (NotImplementedException) {
        }
    }

    public static function sortCollection(Collection $items): Collection
    {
        $rangesArray = $items->toArray();
        self::sortArray($rangesArray);

        return new ArrayCollection($rangesArray);
    }

    public static function sortArray(array &$items): array
    {
        usort($items, static fn(OfferOfParticipant $range1, OfferOfParticipant $range2) => self::cmp($range1, $range2));

        return $items;
    }

    public static function cmp(self $range1, self $range2): int
    {
        $cmpResult = DateTimeUtils::cmpDate($range2->getCreatedAt(), $range1->getCreatedAt());

        return 0 === $cmpResult ? self::cmpId($range2->getId(), $range1->getId()) : $cmpResult;
    }

    public static function cmpId(?int $a, ?int $b): int
    {
        if ($a === $b) {
            return 0;
        }

        return $a < $b ? -1 : 1;
    }

    public function isActive(?DateTime $referenceDateTime = null): bool
    {
        return $this->isActivated($referenceDateTime) && !$this->isDeleted($referenceDateTime);
    }

    public function getEventName(): ?string
    {
        return $this->getEvent()?->getName();
    }

    public function getEvent(): ?Event
    {
        return $this->getRange()?->getEvent();
    }

    public function getRange(): ?ParticipantOffer
    {
        return $this->range;
    }

    /**
     * @param  ParticipantOffer|null  $range
     *
     * @throws NotImplementedException
     */
    public function setRange(?ParticipantOffer $range): void
    {
        if ($this->range === $range) {
            return;
        }
        if (null !== $this->range) {
            throw new NotImplementedException('změna rozsahu', 'v přiřazení rozsahu k účastníkovi');
        }
        $this->range = $range;
    }

    public function getPrice(?ParticipantCategory $participantCategory = null): ?int
    {
        return $this->getRange()?->getPrice($participantCategory);
    }

    public function getDepositValue(?ParticipantCategory $participantCategory = null): ?int
    {
        return $this->getRange()?->getDepositValue($participantCategory);
    }

    public function getParticipantCategory(): ?ParticipantCategory
    {
        return $this->getRange()?->getParticipantCategory();
    }

    public function getParticipant(): ?Participant
    {
        return $this->participant;
    }

    public function setParticipant(?Participant $participant): void
    {
        $this->participant = $participant;
    }
}
