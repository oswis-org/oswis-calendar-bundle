<?php
/**
 * @noinspection PhpUnused
 * @noinspection PropertyCanBePrivateInspection
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Entity\CheckIn;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use DateTime;
use Doctrine\ORM\Mapping\Cache;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\Table;
use Doctrine\ORM\Mapping\UniqueConstraint;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Repository\CheckIn\ParticipantStationVisitRepository;
use OswisOrg\OswisCalendarBundle\State\ParticipantStationVisitProcessor;
use OswisOrg\OswisCoreBundle\Interfaces\Common\BasicInterface;
use OswisOrg\OswisCoreBundle\Traits\Common\BasicTrait;
use Symfony\Component\Serializer\Attribute\MaxDepth;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Splnění check-in stanice účastníkem — kdy prošel kterou stanicí + kdo ho odbavil + volitelná
 * hodnota (velikost trička / barva pásku / číslo pokoje) + poznámka. Nezávislé, ne sekvenční.
 *
 * Idempotentní per (participant, station) — unique constraint. `completedAt` je EXPLICITNÍ
 * (klient ho posílá z offline fronty = kdy se to reálně stalo), `createdAt`/`createdBy` (BasicTrait)
 * = kdy/kým se to zapsalo na server. Poslední-zápis-vyhrává.
 *
 * Serializační grupy: Resources/config/serialization/CheckIn/ParticipantStationVisit.yaml.
 * Spec: docs/superpowers/specs/2026-07-14-checkin-accommodation-module-design.md (§4, §8, §9).
 */
#[ApiResource(
    operations: [
        new GetCollection(
            normalizationContext: ['groups' => ['entities_get', 'calendar_participant_station_visits_get'], 'enable_max_depth' => true],
            security: "is_granted('ROLE_MANAGER')",
        ),
        new Get(
            normalizationContext: ['groups' => ['entity_get', 'calendar_participant_station_visit_get'], 'enable_max_depth' => true],
            security: "is_granted('ROLE_MANAGER')",
        ),
        // POST i PUT = IDEMPOTENTNÍ upsert per (participant, station) přes processor (offline-ready, §9).
        new Post(
            normalizationContext: ['groups' => ['entity_get', 'calendar_participant_station_visit_get'], 'enable_max_depth' => true],
            denormalizationContext: ['groups' => ['calendar_participant_station_visit_post']],
            security: "is_granted('ROLE_MANAGER')",
            processor: ParticipantStationVisitProcessor::class,
        ),
        new Put(
            normalizationContext: ['groups' => ['entity_get', 'calendar_participant_station_visit_get'], 'enable_max_depth' => true],
            denormalizationContext: ['groups' => ['calendar_participant_station_visit_put']],
            security: "is_granted('ROLE_MANAGER')",
            processor: ParticipantStationVisitProcessor::class,
        ),
        new Delete(
            security: "is_granted('ROLE_MANAGER')",
            processor: ParticipantStationVisitProcessor::class,
        ),
    ],
    security: "is_granted('ROLE_MANAGER')",
)]
#[ApiFilter(SearchFilter::class, strategy: 'exact', properties: ['participant.id', 'station.id', 'station.event.id', 'station.stationKind'])]
#[Entity(repositoryClass: ParticipantStationVisitRepository::class)]
#[Table(name: 'calendar_participant_station_visit')]
#[UniqueConstraint(name: 'uniq_participant_station', columns: ['participant_id', 'station_id'])]
#[Cache(usage: 'NONSTRICT_READ_WRITE', region: 'calendar_participant')]
class ParticipantStationVisit implements BasicInterface
{
    use BasicTrait;

    #[ManyToOne(targetEntity: Participant::class, inversedBy: 'stationVisits')]
    #[JoinColumn(name: 'participant_id', nullable: false)]
    #[Assert\NotNull]
    #[MaxDepth(1)]
    protected ?Participant $participant = null;

    #[ManyToOne(targetEntity: CheckInStation::class)]
    #[JoinColumn(name: 'station_id', nullable: false)]
    #[Assert\NotNull]
    #[MaxDepth(1)]
    protected ?CheckInStation $station = null;

    /** Kdy stanice reálně proběhla (klient posílá z offline fronty; default = teď). */
    #[Column(type: 'datetime', nullable: false)]
    #[Assert\NotNull]
    protected ?DateTime $completedAt = null;

    /** Zachycená hodnota stanice (vydaná velikost / barva pásku / číslo pokoje). */
    #[Column(type: 'string', length: 255, nullable: true)]
    protected ?string $value = null;

    /** Volitelná poznámka obsluhy stanice. */
    #[Column(type: 'string', length: 512, nullable: true)]
    protected ?string $note = null;

    public function __construct(?DateTime $completedAt = null, ?string $value = null, ?string $note = null)
    {
        $this->completedAt = $completedAt ?? new DateTime();
        $this->value = $value;
        $this->note = $note;
    }

    public function getParticipant(): ?Participant
    {
        return $this->participant;
    }

    public function setParticipant(?Participant $participant): void
    {
        $this->participant = $participant;
    }

    public function getStation(): ?CheckInStation
    {
        return $this->station;
    }

    public function setStation(?CheckInStation $station): void
    {
        $this->station = $station;
    }

    public function getCompletedAt(): ?DateTime
    {
        return $this->completedAt;
    }

    public function setCompletedAt(?DateTime $completedAt): void
    {
        $this->completedAt = $completedAt ?? new DateTime();
    }

    public function getValue(): ?string
    {
        return $this->value;
    }

    public function setValue(?string $value): void
    {
        $this->value = '' === trim((string) $value) ? null : trim((string) $value);
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): void
    {
        $this->note = '' === trim((string) $note) ? null : trim((string) $note);
    }
}
