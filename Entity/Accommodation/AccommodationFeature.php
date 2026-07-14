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
use OswisOrg\OswisCalendarBundle\Repository\Accommodation\AccommodationFeatureRepository;
use OswisOrg\OswisCoreBundle\Entity\NonPersistent\Nameable;
use OswisOrg\OswisCoreBundle\Interfaces\Common\DeletedInterface;
use OswisOrg\OswisCoreBundle\Interfaces\Common\NameableInterface;
use OswisOrg\OswisCoreBundle\Traits\Common\DeletedTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\NameableTrait;

/**
 * Vlastnost ubytovací jednotky — konfigurovatelný číselník (WC, sprcha, TV, ZTP, pet-friendly,
 * manželská postel, kuchyňka, lednice, balkon…). M:N na {@see AccommodationUnit}.
 *
 * Serializace: Resources/config/serialization/Accommodation/AccommodationFeature.yaml.
 * Spec: docs/superpowers/specs/2026-07-14-checkin-accommodation-module-design.md (§5).
 */
#[ApiResource(
    operations: [
        new GetCollection(
            normalizationContext: ['groups' => ['entities_get', 'calendar_accommodation_features_get'], 'enable_max_depth' => true],
            security: "is_granted('ROLE_MEMBER')",
        ),
        new Get(
            normalizationContext: ['groups' => ['entity_get', 'calendar_accommodation_feature_get'], 'enable_max_depth' => true],
            security: "is_granted('ROLE_MEMBER')",
        ),
        new Post(
            denormalizationContext: ['groups' => ['entities_post', 'calendar_accommodation_features_post'], 'enable_max_depth' => true],
            security: "is_granted('ROLE_MANAGER')",
        ),
        new Put(
            denormalizationContext: ['groups' => ['entity_put', 'calendar_accommodation_feature_put'], 'enable_max_depth' => true],
            security: "is_granted('ROLE_MANAGER')",
        ),
        new Delete(security: "is_granted('ROLE_MANAGER')"),
    ],
)]
#[Entity(repositoryClass: AccommodationFeatureRepository::class)]
#[Table(name: 'calendar_accommodation_feature')]
#[Cache(usage: 'NONSTRICT_READ_WRITE', region: 'calendar_participant')]
class AccommodationFeature implements NameableInterface, DeletedInterface
{
    use NameableTrait;
    use DeletedTrait;

    /** Strojový kód vlastnosti (wc, shower, tv, ztp, pet, double_bed, kitchenette, fridge…). */
    #[Column(type: 'string', length: 32, unique: true)]
    protected string $code = '';

    public function __construct(?Nameable $nameable = null, string $code = '')
    {
        $this->setFieldsFromNameable($nameable);
        $this->code = $code;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $code): void
    {
        $this->code = $code;
    }
}
