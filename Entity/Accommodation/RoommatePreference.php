<?php
/**
 * @noinspection PhpUnused
 * @noinspection PropertyCanBePrivateInspection
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Entity\Accommodation;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use Doctrine\ORM\Mapping\Cache;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\Table;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Repository\Accommodation\RoommatePreferenceRepository;
use OswisOrg\OswisCoreBundle\Interfaces\Common\BasicInterface;
use OswisOrg\OswisCoreBundle\Traits\Common\BasicTrait;
use Symfony\Component\Serializer\Attribute\MaxDepth;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Preference spolubydlení — vazba účastník↔účastník (nebo volný text) + důvod + zdroj + stav.
 * Modelováno NEZÁVISLE na přiřazení jednotky (funguje i bez plného ubytování; committed 2026).
 * Detekce konfliktu = stav. `createdBy` (BasicTrait) = kdo zadal.
 *
 * ZÁPIS přes web admin / službu (řeší se párování + konflikt) — API zatím READ-only.
 * Serializace: serialization/Accommodation/RoommatePreference.yaml.
 * Spec: docs/superpowers/specs/2026-07-14-checkin-accommodation-module-design.md (§5).
 */
#[ApiResource(
    operations: [
        new GetCollection(
            normalizationContext: ['groups' => ['entities_get', 'calendar_roommate_preferences_get'], 'enable_max_depth' => true],
            security: "is_granted('ROLE_MEMBER')",
        ),
        new Get(
            normalizationContext: ['groups' => ['entity_get', 'calendar_roommate_preference_get'], 'enable_max_depth' => true],
            security: "is_granted('ROLE_MEMBER')",
        ),
    ],
    security: "is_granted('ROLE_MEMBER')",
)]
#[ApiFilter(SearchFilter::class, strategy: 'exact', properties: ['participant.id', 'participant.event.id', 'status'])]
#[Entity(repositoryClass: RoommatePreferenceRepository::class)]
#[Table(name: 'calendar_roommate_preference')]
#[Cache(usage: 'NONSTRICT_READ_WRITE', region: 'calendar_participant')]
class RoommatePreference implements BasicInterface
{
    use BasicTrait;

    public const string STATUS_OK = 'ok';
    public const string STATUS_CONFLICT = 'conflict';
    public const string STATUS_UNMATCHED = 'unmatched';

    public const string SOURCE_REGISTRATION = 'registration';
    public const string SOURCE_EMAIL = 'email';
    public const string SOURCE_OTHER = 'other';

    /** Účastník, který preferenci vyslovil. */
    #[ManyToOne(targetEntity: Participant::class)]
    #[JoinColumn(name: 'participant_id', nullable: false)]
    #[Assert\NotNull]
    #[MaxDepth(1)]
    protected ?Participant $participant = null;

    /** Konkrétní spolubydlící (null = jen volný text, ještě nespárováno). */
    #[ManyToOne(targetEntity: Participant::class)]
    #[JoinColumn(name: 'with_participant_id', nullable: true)]
    #[MaxDepth(1)]
    protected ?Participant $withParticipant = null;

    /** Volný text preference/důvodu (jména, „chatka pro 4", „manželská postel"…). */
    #[Column(type: 'string', length: 512, nullable: true)]
    protected ?string $preferenceText = null;

    /** Zdroj požadavku (přihláška / e-mail / jiné). */
    #[Column(type: 'string', length: 16)]
    protected string $source = self::SOURCE_EMAIL;

    /** Stav párování (ok / konflikt / nespárováno). */
    #[Column(type: 'string', length: 16)]
    protected string $status = self::STATUS_UNMATCHED;

    public function __construct(?string $preferenceText = null, string $source = self::SOURCE_EMAIL)
    {
        $this->preferenceText = $preferenceText;
        $this->source = $source;
    }

    public function getParticipant(): ?Participant
    {
        return $this->participant;
    }

    public function setParticipant(?Participant $participant): void
    {
        $this->participant = $participant;
    }

    public function getWithParticipant(): ?Participant
    {
        return $this->withParticipant;
    }

    public function setWithParticipant(?Participant $withParticipant): void
    {
        $this->withParticipant = $withParticipant;
    }

    public function getPreferenceText(): ?string
    {
        return $this->preferenceText;
    }

    public function setPreferenceText(?string $preferenceText): void
    {
        $this->preferenceText = $preferenceText;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function setSource(string $source): void
    {
        $this->source = $source;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
    }
}
