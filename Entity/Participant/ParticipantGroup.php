<?php
/**
 * @noinspection PhpUnused
 * @noinspection PropertyCanBePrivateInspection
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Entity\Participant;

use Doctrine\ORM\Mapping\Cache;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\Table;
use OswisOrg\OswisCalendarBundle\Entity\Event\Event;
use OswisOrg\OswisCalendarBundle\Repository\Participant\ParticipantGroupRepository;
use OswisOrg\OswisCoreBundle\Entity\NonPersistent\Nameable;
use OswisOrg\OswisCoreBundle\Interfaces\Common\NameableInterface;
use OswisOrg\OswisCoreBundle\Traits\Common\ColorTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\NameableTrait;

/**
 * Skupina pásků — účastníci jednoho turnusu rozdělení do barevných skupin (pásky)
 * pro program, rotaci slotů a pořadí na jídlo. Nositel přiřazení = Participant::$group.
 *
 * Spec: docs/superpowers/specs/2026-06-12-program-module-design.md (krok 5).
 */
#[Entity(repositoryClass: ParticipantGroupRepository::class)]
#[Table(name: 'calendar_participant_group')]
#[Cache(usage: 'NONSTRICT_READ_WRITE', region: 'calendar_participant')]
class ParticipantGroup implements NameableInterface
{
    use NameableTrait;
    use ColorTrait;

    /**
     * Pořadí skupiny na jídlo (dietáři první = nejnižší mealOrder). DEDIKOVANÉ pole,
     * záměrně NE PriorityTrait (jiná sémantika než obecná priorita řazení).
     */
    #[Column(type: 'integer', nullable: true)]
    protected ?int $mealOrder = null;

    /** Turnus (Event), do kterého skupina patří. */
    #[ManyToOne(targetEntity: Event::class)]
    #[JoinColumn(name: 'event_id', nullable: false)]
    protected Event $event;

    public function __construct(
        Event $event,
        ?Nameable $nameable = null,
        ?string $color = null,
        ?int $mealOrder = null,
    ) {
        $this->event = $event;
        $this->setFieldsFromNameable($nameable);
        $this->setColor($color);
        $this->mealOrder = $mealOrder;
    }

    public function getMealOrder(): ?int
    {
        return $this->mealOrder;
    }

    public function setMealOrder(?int $mealOrder): void
    {
        $this->mealOrder = $mealOrder;
    }

    public function getEvent(): Event
    {
        return $this->event;
    }

    public function setEvent(Event $event): void
    {
        $this->event = $event;
    }
}
