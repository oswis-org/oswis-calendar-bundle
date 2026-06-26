<?php
/**
 * @noinspection PhpUnused
 * @noinspection PropertyCanBePrivateInspection
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Entity\Participant;

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
use OswisOrg\OswisCalendarBundle\Entity\Event\Event;
use OswisOrg\OswisCalendarBundle\Repository\Participant\ParticipantGroupRepository;
use OswisOrg\OswisCalendarBundle\State\ProgramApiProcessor;
use OswisOrg\OswisCoreBundle\Entity\NonPersistent\Nameable;
use OswisOrg\OswisCoreBundle\Interfaces\Common\NameableInterface;
use OswisOrg\OswisCoreBundle\Traits\Common\ColorTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\NameableTrait;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Skupina pásků — účastníci jednoho turnusu rozdělení do barevných skupin (pásky)
 * pro program, rotaci slotů a pořadí na jídlo. Nositel přiřazení = Participant::$group.
 *
 * Spec: docs/superpowers/specs/2026-06-12-program-module-design.md (krok 5).
 */
#[ApiResource(
    operations: [
        new GetCollection(
            normalizationContext: ['groups' => ['entities_get', 'calendar_participant_groups_get'], 'enable_max_depth' => true],
            security: "is_granted('ROLE_CUSTOMER')",
        ),
        new Get(
            normalizationContext: ['groups' => ['entity_get', 'calendar_participant_group_get'], 'enable_max_depth' => true],
            security: "is_granted('ROLE_CUSTOMER')",
        ),
        new Post(
            denormalizationContext: ['groups' => ['entities_post', 'calendar_participant_groups_post'], 'enable_max_depth' => true],
            security: "is_granted('ROLE_MANAGER')",
            processor: ProgramApiProcessor::class,
        ),
        new Put(
            normalizationContext: ['groups' => ['entity_get', 'calendar_participant_group_get'], 'enable_max_depth' => true],
            denormalizationContext: ['groups' => ['entity_put', 'calendar_participant_group_put'], 'enable_max_depth' => true],
            security: "is_granted('ROLE_MANAGER')",
            processor: ProgramApiProcessor::class,
        ),
        new Delete(security: "is_granted('ROLE_MANAGER')"),
    ],
)]
#[ApiFilter(SearchFilter::class, strategy: 'exact', properties: ['event.id'])]
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

    /**
     * Turnus (Event), do kterého skupina patří. Nastavuje se setterem (NE konstruktorem) —
     * API Platform resolvuje IRI relace jen přes setter, ne přes constructor argument.
     */
    #[ManyToOne(targetEntity: Event::class)]
    #[JoinColumn(name: 'event_id', nullable: false)]
    #[Assert\NotNull]
    protected ?Event $event = null;

    public function __construct(
        ?Nameable $nameable = null,
        ?string $color = null,
        ?int $mealOrder = null,
    ) {
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

    public function getEvent(): ?Event
    {
        return $this->event;
    }

    public function setEvent(?Event $event): void
    {
        $this->event = $event;
    }
}
