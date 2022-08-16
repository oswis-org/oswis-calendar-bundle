<?php
/**
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
use Doctrine\ORM\Mapping\Table;
use OswisOrg\OswisCalendarBundle\Entity\Event\Event;
use OswisOrg\OswisCalendarBundle\Entity\Registration\RegistrationOffer;
use OswisOrg\OswisCalendarBundle\Repository\Participant\ParticipantRegistrationRepository;
use OswisOrg\OswisCoreBundle\Exceptions\NotImplementedException;
use OswisOrg\OswisCoreBundle\Interfaces\Common\BasicInterface;
use OswisOrg\OswisCoreBundle\Traits\Common\ActivatedTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\BasicTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\DeletedTrait;
use OswisOrg\OswisCoreBundle\Utils\DateTimeUtils;

/**
 * @ApiPlatform\Core\Annotation\ApiResource(
 *   attributes={
 *     "filters"={"search"},
 *     "security"="is_granted('ROLE_MANAGER')"
 *   },
 *   collectionOperations={
 *     "get"={
 *       "security"="is_granted('ROLE_MANAGER')",
 *       "normalization_context"={"groups"={"entities_get", "calendar_participant_ranges_get"},
 *     "enable_max_depth"=true},
 *     },
 *     "post"={
 *       "security"="is_granted('ROLE_MANAGER')",
 *       "denormalization_context"={"groups"={"entities_post", "calendar_participant_ranges_post"},
 *     "enable_max_depth"=true}
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
 */
#[Entity(repositoryClass: ParticipantRegistrationRepository::class)]
#[Table(name: 'calendar_participant_range')]
#[Cache(usage: 'NONSTRICT_READ_WRITE', region: 'calendar_participant')]
class ParticipantRegistration implements BasicInterface
{
    use BasicTrait;
    use ActivatedTrait;
    use DeletedTrait;

    #[ManyToOne(targetEntity: Participant::class, fetch: 'EXTRA_LAZY', inversedBy: 'participantRegistrations')]
    #[JoinColumn(nullable: true)]
    protected ?Participant $participant = null;

    #[ManyToOne(targetEntity: RegistrationOffer::class, fetch: 'EAGER')]
    #[JoinColumn(nullable: true)]
    protected ?RegistrationOffer $offer = null;

    public function __construct(?RegistrationOffer $range = null)
    {
        try {
            $this->setOffer($range);
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
        usort(
            $items,
            static fn(ParticipantRegistration $range1, ParticipantRegistration $range2) => self::cmp($range1, $range2)
        );

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
        return $this->getOffer()?->getEvent();
    }

    public function getOffer(): ?RegistrationOffer
    {
        return $this->offer;
    }

    /**
     * @param RegistrationOffer|null $offer
     *
     * @throws NotImplementedException
     */
    public function setOffer(?RegistrationOffer $offer): void
    {
        if ($this->offer === $offer) {
            return;
        }
        if (null !== $this->offer) {
            throw new NotImplementedException('změna rozsahu', 'v přiřazení rozsahu k účastníkovi');
        }
        $this->offer = $offer;
    }

    public function getPrice(?ParticipantCategory $participantCategory = null): ?int
    {
        return $this->getOffer()?->getPrice($participantCategory);
    }

    public function getDepositValue(?ParticipantCategory $participantCategory = null): ?int
    {
        return $this->getOffer()?->getDepositValue($participantCategory);
    }

    public function getParticipantCategory(): ?ParticipantCategory
    {
        return $this->getOffer()?->getParticipantCategory();
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
