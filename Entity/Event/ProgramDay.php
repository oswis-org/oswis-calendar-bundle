<?php
/**
 * @noinspection PhpUnused
 * @noinspection PropertyCanBePrivateInspection
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Entity\Event;

use DateTimeInterface;
use Doctrine\ORM\Mapping\Cache;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\Table;
use OswisOrg\OswisCalendarBundle\Repository\Event\ProgramDayRepository;
use OswisOrg\OswisCoreBundle\Entity\NonPersistent\Nameable;
use OswisOrg\OswisCoreBundle\Interfaces\Common\NameableInterface;
use OswisOrg\OswisCoreBundle\Traits\Common\NameableTrait;

/**
 * Den programu v rámci turnusu (osa pro denní rozvrh aktivit). Název = volitelné
 * pojmenování dne (např. „Příjezdový den").
 *
 * Spec: docs/superpowers/specs/2026-06-12-program-module-design.md (krok 5).
 */
#[Entity(repositoryClass: ProgramDayRepository::class)]
#[Table(name: 'calendar_program_day')]
#[Cache(usage: 'NONSTRICT_READ_WRITE', region: 'calendar_event')]
class ProgramDay implements NameableInterface
{
    use NameableTrait;

    /** Datum dne programu. */
    #[Column(type: 'date', nullable: false)]
    protected DateTimeInterface $date;

    /** Turnus (Event), do kterého den patří. */
    #[ManyToOne(targetEntity: Event::class)]
    #[JoinColumn(name: 'event_id', nullable: false)]
    protected Event $event;

    public function __construct(Event $event, DateTimeInterface $date, ?Nameable $nameable = null)
    {
        $this->event = $event;
        $this->date = $date;
        $this->setFieldsFromNameable($nameable);
    }

    public function getDate(): DateTimeInterface
    {
        return $this->date;
    }

    public function setDate(DateTimeInterface $date): void
    {
        $this->date = $date;
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
