<?php

namespace Zakjakub\OswisCalendarBundle\Entity\EventParticipant;

use Zakjakub\OswisAddressBookBundle\Entity\AbstractClass\AbstractContact;
use Zakjakub\OswisCalendarBundle\Entity\Event\Event;
use Zakjakub\OswisCalendarBundle\Exceptions\EventCapacityExceededException;
use Zakjakub\OswisCoreBundle\Entity\AbstractClass\AbstractRevision;
use Zakjakub\OswisCoreBundle\Entity\AbstractClass\AbstractRevisionContainer;
use Zakjakub\OswisCoreBundle\Traits\Entity\BasicEntityTrait;
use Zakjakub\OswisCoreBundle\Traits\Entity\DeletedTrait;
use function assert;

/**
 * @Doctrine\ORM\Mapping\Entity()
 * @Doctrine\ORM\Mapping\Table(name="calendar_event_participant_revision")
 * @Doctrine\ORM\Mapping\Cache(usage="NONSTRICT_READ_WRITE", region="calendar_event_participant")
 */
class EventParticipantRevision extends AbstractRevision
{
    use BasicEntityTrait;
    use DeletedTrait;

    /**
     * @var AbstractRevisionContainer|null
     * @Doctrine\ORM\Mapping\ManyToOne(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\EventParticipant\EventParticipant",
     *     inversedBy="revisions"
     * )
     * @Doctrine\ORM\Mapping\JoinColumn(name="container_id", referencedColumnName="id")
     */
    protected ?AbstractRevisionContainer $container = null;

    /**
     * Related contact (person or organization). MOVE?????
     * @var AbstractContact|null
     * @Doctrine\ORM\Mapping\ManyToOne(
     *     targetEntity="Zakjakub\OswisAddressBookBundle\Entity\AbstractClass\AbstractContact",
     *     cascade={"all"},
     *     fetch="EAGER"
     * )
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     */
    protected ?AbstractContact $contact = null;

    /**
     * Related event. MOVE???????
     * @var Event|null
     * @Doctrine\ORM\Mapping\ManyToOne(
     *     targetEntity="Zakjakub\OswisCalendarBundle\Entity\Event\Event",
     *     inversedBy="eventParticipantRevisions",
     *     cascade={"all"},
     *     fetch="EAGER"
     * )
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     */
    protected ?Event $event = null;

    /**
     * EventAttendee constructor.
     *
     * @param AbstractContact|null $contact
     * @param Event|null           $event
     *
     * @throws EventCapacityExceededException
     */
    public function __construct(
        ?AbstractContact $contact = null,
        ?Event $event = null
    ) {
        $this->setContact($contact);
        $this->setEvent($event);
    }

    /**
     * @return string
     */
    public static function getRevisionContainerClassName(): string
    {
        return EventParticipant::class;
    }

    /**
     * @param AbstractRevisionContainer|null $revision
     */
    public static function checkRevisionContainer(?AbstractRevisionContainer $revision): void
    {
        assert($revision instanceof EventParticipant);
    }

    final public function getEvent(): ?Event
    {
        return $this->event;
    }

    /**
     * @param Event|null $event
     *
     * @throws EventCapacityExceededException
     */
    final public function setEvent(?Event $event): void
    {
        if ($this->event && $event !== $this->event) {
            $this->event->removeEventParticipantRevision($this);
        }
        if ($event && $this->event !== $event) {
            $this->event = $event;
            $event->addEventParticipantRevision($this);
        }
    }

    final public function getContact(): ?AbstractContact
    {
        return $this->contact;
    }

    final public function setContact(?AbstractContact $contact): void
    {
        $this->contact = $contact;
    }
}
