<?php /** @noinspection PhpUnused */

namespace Zakjakub\OswisCalendarBundle\Entity\EventParticipant;

use ApiPlatform\Core\Annotation\ApiFilter;
use ApiPlatform\Core\Annotation\ApiResource;
use ApiPlatform\Core\Bridge\Doctrine\Orm\Filter\OrderFilter;
use Doctrine\ORM\Mapping as ORM;
use Zakjakub\OswisCoreBundle\Filter\SearchAnnotation as Searchable;
use Zakjakub\OswisCoreBundle\Traits\Entity\BasicEntityTrait;
use Zakjakub\OswisCoreBundle\Traits\Entity\TextValueTrait;

/**
 * @Doctrine\ORM\Mapping\Entity()
 * @Doctrine\ORM\Mapping\Table(name="calendar_event_participant_note")
 * @ApiResource(
 *   attributes={
 *     "filters"={"search"},
 *     "access_control"="is_granted('ROLE_MANAGER')"
 *   },
 *   collectionOperations={
 *     "get"={
 *       "access_control"="is_granted('ROLE_MANAGER')",
 *       "normalization_context"={"groups"={"calendar_event_participant_notes_get"}},
 *     },
 *     "post"={
 *       "access_control"="is_granted('ROLE_MANAGER')",
 *       "denormalization_context"={"groups"={"calendar_event_participant_notes_post"}}
 *     }
 *   },
 *   itemOperations={
 *     "get"={
 *       "access_control"="is_granted('ROLE_MANAGER')",
 *       "normalization_context"={"groups"={"calendar_event_participant_note_get"}},
 *     },
 *     "put"={
 *       "access_control"="is_granted('ROLE_MANAGER')",
 *       "denormalization_context"={"groups"={"calendar_event_participant_note_put"}}
 *     },
 *     "delete"={
 *       "access_control"="is_granted('ROLE_ADMIN')",
 *       "denormalization_context"={"groups"={"calendar_event_participant_note_delete"}}
 *     }
 *   }
 * )
 * @ApiFilter(OrderFilter::class)
 * @Searchable({
 *     "id",
 *     "textValue"
 * })
 * @Doctrine\ORM\Mapping\Cache(usage="NONSTRICT_READ_WRITE", region="calendar_event_participant")
 */
class EventParticipantNote
{
    use BasicEntityTrait;
    use TextValueTrait;

    /**
     * Note is public.
     * @var boolean|null
     * @ORM\Column(type="boolean", nullable=true)
     */
    protected ?bool $publicNote = null;

    /**
     * Event contact revision (connected to person or organization). // CHANGE
     * @var EventParticipant|null
     * @Doctrine\ORM\Mapping\ManyToOne(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\EventParticipant\EventParticipant",
     *     inversedBy="eventParticipantNotes"
     * )
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     */
    protected ?EventParticipant $eventParticipant = null;

    /**
     * @param EventParticipant|null $eventParticipant
     * @param string|null           $textValue
     */
    public function __construct(
        ?EventParticipant $eventParticipant = null,
        ?string $textValue = null
    ) {
        $this->setEventParticipant($eventParticipant);
        $this->setTextValue($textValue);
    }

    /**
     * @return bool
     */
    final public function isPublicNote(): bool
    {
        return $this->publicNote ?? false;
    }

    /**
     * @param bool $publicNote
     */
    final public function setPublicNote(?bool $publicNote): void
    {
        $this->publicNote = $publicNote ?? false;
    }

    final public function getEventParticipant(): ?EventParticipant
    {
        return $this->eventParticipant;
    }

    final public function setEventParticipant(?EventParticipant $eventParticipant): void
    {
        if ($this->eventParticipant && $eventParticipant !== $this->eventParticipant) {
            $this->eventParticipant->removeEventParticipantNote($this);
        }
        if ($eventParticipant && $this->eventParticipant !== $eventParticipant) {
            $this->eventParticipant = $eventParticipant;
            $eventParticipant->addEventParticipantNote($this);
        }
    }
}
