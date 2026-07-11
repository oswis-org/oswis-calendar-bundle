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
use DateTimeInterface;
use Doctrine\ORM\Mapping\Cache;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\Table;
use OswisOrg\OswisCalendarBundle\Repository\Event\ProgramDayRepository;
use OswisOrg\OswisCalendarBundle\State\ProgramApiProcessor;
use OswisOrg\OswisCoreBundle\Entity\NonPersistent\Nameable;
use OswisOrg\OswisCoreBundle\Interfaces\Common\NameableInterface;
use OswisOrg\OswisCoreBundle\Traits\Common\NameableTrait;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Den programu v rámci turnusu (osa pro denní rozvrh aktivit). Název = volitelné
 * pojmenování dne (např. „Příjezdový den").
 *
 * Spec: docs/superpowers/specs/2026-06-12-program-module-design.md (krok 5).
 */
#[ApiResource(
    operations: [
        new GetCollection(
            normalizationContext: ['groups' => ['entities_get', 'calendar_program_days_get'], 'enable_max_depth' => true],
            security: "is_granted('ROLE_CUSTOMER')",
        ),
        new Get(
            normalizationContext: ['groups' => ['entity_get', 'calendar_program_day_get'], 'enable_max_depth' => true],
            security: "is_granted('ROLE_CUSTOMER')",
        ),
        new Post(
            denormalizationContext: ['groups' => ['entities_post', 'calendar_program_days_post'], 'enable_max_depth' => true],
            security: "is_granted('ROLE_MANAGER')",
            processor: ProgramApiProcessor::class,
        ),
        new Put(
            normalizationContext: ['groups' => ['entity_get', 'calendar_program_day_get'], 'enable_max_depth' => true],
            denormalizationContext: ['groups' => ['entity_put', 'calendar_program_day_put'], 'enable_max_depth' => true],
            security: "is_granted('ROLE_MANAGER')",
            processor: ProgramApiProcessor::class,
        ),
        new Delete(security: "is_granted('ROLE_MANAGER')"),
    ],
)]
#[ApiFilter(SearchFilter::class, strategy: 'exact', properties: ['event.id'])]
#[Entity(repositoryClass: ProgramDayRepository::class)]
#[Table(name: 'calendar_program_day')]
#[Cache(usage: 'NONSTRICT_READ_WRITE', region: 'calendar_event')]
class ProgramDay implements NameableInterface
{
    use NameableTrait;

    /**
     * Datum dne programu. Nullable v PHP (aby šel objekt postavit přes API Platform bez
     * konstruktorové relace/IRI), povinnost drží #[Assert\NotNull] + DB sloupec nullable:false.
     */
    #[Column(type: 'date', nullable: false)]
    #[Assert\NotNull]
    protected ?DateTimeInterface $date = null;

    /**
     * Turnus (Event), do kterého den patří. Nastavuje se setterem (NE konstruktorem) —
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

    public function getDate(): ?DateTimeInterface
    {
        return $this->date;
    }

    public function setDate(?DateTimeInterface $date): void
    {
        $this->date = $date;
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
