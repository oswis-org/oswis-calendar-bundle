<?php
/**
 * @noinspection PhpUnused
 * @noinspection PropertyCanBePrivateInspection
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Entity\Event;

use Doctrine\ORM\Mapping\Cache;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\Table;
use OswisOrg\OswisCalendarBundle\Repository\Event\EventSectionRepository;
use OswisOrg\OswisCoreBundle\Entity\NonPersistent\Nameable;
use OswisOrg\OswisCoreBundle\Interfaces\Common\NameableInterface;
use OswisOrg\OswisCoreBundle\Traits\Common\EntityPublicTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\NameableTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\PriorityTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\TextValueTrait;

/**
 * Informační sekce turnusu (volný blok textu pro účastníka — např. „Co s sebou",
 * „Doprava", „Kontakty"). Řazení dle priority, viditelnost web/app, volitelná ikona.
 *
 * Spec: docs/superpowers/specs/2026-06-12-program-module-design.md (krok 5).
 */
#[Entity(repositoryClass: EventSectionRepository::class)]
#[Table(name: 'calendar_event_section')]
#[Cache(usage: 'NONSTRICT_READ_WRITE', region: 'calendar_event')]
class EventSection implements NameableInterface
{
    use NameableTrait;
    use TextValueTrait;
    use PriorityTrait;
    use EntityPublicTrait;

    /** Ikona sekce (název/identifikátor ikony pro UI). */
    #[Column(type: 'string', nullable: true)]
    protected ?string $icon = null;

    /** Turnus (Event), do kterého sekce patří. */
    #[ManyToOne(targetEntity: Event::class)]
    #[JoinColumn(name: 'event_id', nullable: false)]
    protected Event $event;

    public function __construct(Event $event, ?Nameable $nameable = null)
    {
        $this->event = $event;
        $this->setFieldsFromNameable($nameable);
    }

    public function getIcon(): ?string
    {
        return $this->icon;
    }

    public function setIcon(?string $icon): void
    {
        $this->icon = $icon;
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
