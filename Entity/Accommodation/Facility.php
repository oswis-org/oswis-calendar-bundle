<?php
/**
 * @noinspection PhpUnused
 * @noinspection PropertyCanBePrivateInspection
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Entity\Accommodation;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use Doctrine\ORM\Mapping\Cache;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Table;
use OswisOrg\OswisCalendarBundle\Repository\Accommodation\FacilityRepository;
use OswisOrg\OswisCoreBundle\Entity\NonPersistent\Nameable;
use OswisOrg\OswisCoreBundle\Interfaces\Common\DeletedInterface;
use OswisOrg\OswisCoreBundle\Interfaces\Common\NameableInterface;
use OswisOrg\OswisCoreBundle\Traits\Common\DeletedTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\NameableTrait;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Ubytovací zařízení (kemp Morava Camp, hotel, hostel, apartmány, soukromí). Sdružuje jednotky.
 * Univerzální (event-bound i celoroční — celoroční booking flow je OSWIS 2, v1 jen event-bound).
 *
 * Serializace: Resources/config/serialization/Accommodation/Facility.yaml.
 * Spec: docs/superpowers/specs/2026-07-14-checkin-accommodation-module-design.md (§5).
 */
#[ApiResource(
    operations: [
        new GetCollection(
            normalizationContext: ['groups' => ['entities_get', 'calendar_facilities_get'], 'enable_max_depth' => true],
            security: "is_granted('ROLE_MEMBER')",
        ),
        new Get(
            normalizationContext: ['groups' => ['entity_get', 'calendar_facility_get'], 'enable_max_depth' => true],
            security: "is_granted('ROLE_MEMBER')",
        ),
        new Post(
            denormalizationContext: ['groups' => ['entities_post', 'calendar_facilities_post'], 'enable_max_depth' => true],
            security: "is_granted('ROLE_MANAGER')",
        ),
        new Put(
            denormalizationContext: ['groups' => ['entity_put', 'calendar_facility_put'], 'enable_max_depth' => true],
            security: "is_granted('ROLE_MANAGER')",
        ),
        new Delete(security: "is_granted('ROLE_MANAGER')"),
    ],
)]
#[Entity(repositoryClass: FacilityRepository::class)]
#[Table(name: 'calendar_accommodation_facility')]
#[Cache(usage: 'NONSTRICT_READ_WRITE', region: 'calendar_participant')]
class Facility implements NameableInterface, DeletedInterface
{
    use NameableTrait;
    use DeletedTrait;

    public const string TYPE_CAMP = 'camp';
    public const string TYPE_HOTEL = 'hotel';
    public const string TYPE_HOSTEL = 'hostel';
    public const string TYPE_APARTMENTS = 'apartment_complex';
    public const string TYPE_PRIVATE = 'private_house';

    /** @var list<string> */
    public const array ALLOWED_TYPES = [
        self::TYPE_CAMP,
        self::TYPE_HOTEL,
        self::TYPE_HOSTEL,
        self::TYPE_APARTMENTS,
        self::TYPE_PRIVATE,
    ];

    #[Column(type: 'string', length: 32)]
    #[Assert\Choice(choices: self::ALLOWED_TYPES)]
    protected string $facilityType = self::TYPE_CAMP;

    /** Celoroční provoz (Karlov) vs. jen event-bound (Seznamovák kemp). */
    #[Column(type: 'boolean')]
    protected bool $operatesYearRound = false;

    /** Zapnutý veřejný online booking (Karlov, OSWIS 2) — pro event-bound false; model připraven, flow později. */
    #[Column(type: 'boolean')]
    protected bool $onlineBookingEnabled = false;

    public function __construct(
        ?Nameable $nameable = null,
        string $facilityType = self::TYPE_CAMP,
        bool $operatesYearRound = false,
    ) {
        $this->setFieldsFromNameable($nameable);
        $this->setFacilityType($facilityType);
        $this->operatesYearRound = $operatesYearRound;
    }

    public function isOnlineBookingEnabled(): bool
    {
        return $this->onlineBookingEnabled;
    }

    public function setOnlineBookingEnabled(bool $onlineBookingEnabled): void
    {
        $this->onlineBookingEnabled = $onlineBookingEnabled;
    }

    public function getFacilityType(): string
    {
        return $this->facilityType;
    }

    public function setFacilityType(string $facilityType): void
    {
        if (!in_array($facilityType, self::ALLOWED_TYPES, true)) {
            throw new \InvalidArgumentException("Neplatný typ zařízení: '$facilityType'.");
        }
        $this->facilityType = $facilityType;
    }

    public function isOperatesYearRound(): bool
    {
        return $this->operatesYearRound;
    }

    public function setOperatesYearRound(bool $operatesYearRound): void
    {
        $this->operatesYearRound = $operatesYearRound;
    }
}
