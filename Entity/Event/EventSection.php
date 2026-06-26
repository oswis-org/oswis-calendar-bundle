<?php
/**
 * @noinspection PhpUnused
 * @noinspection PropertyCanBePrivateInspection
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Entity\Event;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use Doctrine\ORM\Mapping\Cache;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\Table;
use OswisOrg\OswisCalendarBundle\Repository\Event\EventSectionRepository;
use OswisOrg\OswisCalendarBundle\State\ProgramApiProcessor;
use OswisOrg\OswisCoreBundle\Entity\NonPersistent\Nameable;
use OswisOrg\OswisCoreBundle\Interfaces\Common\NameableInterface;
use OswisOrg\OswisCoreBundle\Traits\Common\EntityPublicTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\NameableTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\PriorityTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\TextValueTrait;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Informační sekce turnusu (volný blok textu pro účastníka — např. „Co s sebou",
 * „Doprava", „Kontakty"). Řazení dle priority, viditelnost web/app, volitelná ikona.
 *
 * Spec: docs/superpowers/specs/2026-06-12-program-module-design.md (krok 5).
 */
#[ApiResource(
    operations: [
        new GetCollection(
            normalizationContext: ['groups' => ['entities_get', 'calendar_event_sections_get'], 'enable_max_depth' => true],
            security: "is_granted('ROLE_CUSTOMER')",
        ),
        new Get(
            normalizationContext: ['groups' => ['entity_get', 'calendar_event_section_get'], 'enable_max_depth' => true],
            security: "is_granted('ROLE_CUSTOMER')",
        ),
        new Post(
            denormalizationContext: ['groups' => ['entities_post', 'calendar_event_sections_post'], 'enable_max_depth' => true],
            security: "is_granted('ROLE_MANAGER')",
            processor: ProgramApiProcessor::class,
        ),
        new Put(
            normalizationContext: ['groups' => ['entity_get', 'calendar_event_section_get'], 'enable_max_depth' => true],
            denormalizationContext: ['groups' => ['entity_put', 'calendar_event_section_put'], 'enable_max_depth' => true],
            security: "is_granted('ROLE_MANAGER')",
            processor: ProgramApiProcessor::class,
        ),
        new Delete(security: "is_granted('ROLE_MANAGER')"),
    ],
)]
#[ApiFilter(SearchFilter::class, strategy: 'exact', properties: ['event.id'])]
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

    /**
     * Turnus (Event), do kterého sekce patří. Nastavuje se setterem (NE konstruktorem) —
     * API Platform resolvuje IRI relace jen přes setter, ne přes constructor argument.
     */
    #[ManyToOne(targetEntity: Event::class)]
    #[JoinColumn(name: 'event_id', nullable: false)]
    #[Assert\NotNull]
    protected ?Event $event = null;

    public function __construct(?Nameable $nameable = null)
    {
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

    public function getEvent(): ?Event
    {
        return $this->event;
    }

    public function setEvent(?Event $event): void
    {
        $this->event = $event;
    }
}
