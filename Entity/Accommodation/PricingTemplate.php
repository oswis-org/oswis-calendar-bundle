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
use OswisOrg\OswisCalendarBundle\Repository\Accommodation\PricingTemplateRepository;
use OswisOrg\OswisCoreBundle\Entity\NonPersistent\Nameable;
use OswisOrg\OswisCoreBundle\Interfaces\Common\DeletedInterface;
use OswisOrg\OswisCoreBundle\Interfaces\Common\NameableInterface;
use OswisOrg\OswisCoreBundle\Traits\Common\DeletedTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\NameableTrait;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Cenová šablona ubytovací jednotky — příprava na CELOROČNÍ booking (Karlov, OSWIS 2). Pro Seznamovák
 * je cena ubytování přes registrační flagy (TYPE_ACCOMMODATION_TYPE), tady se NEpoužije — model je
 * připraven, plný booking flow (rezervace/zálohy) se doimplementuje později.
 *
 * Serializace: serialization/Accommodation/PricingTemplate.yaml.
 * Spec: docs/superpowers/specs/2026-07-14-checkin-accommodation-module-design.md (§5, growth do OSWIS 2).
 */
#[ApiResource(
    operations: [
        new GetCollection(
            normalizationContext: ['groups' => ['entities_get', 'calendar_pricing_templates_get'], 'enable_max_depth' => true],
            security: "is_granted('ROLE_MANAGER')",
        ),
        new Get(
            normalizationContext: ['groups' => ['entity_get', 'calendar_pricing_template_get'], 'enable_max_depth' => true],
            security: "is_granted('ROLE_MANAGER')",
        ),
        new Post(
            denormalizationContext: ['groups' => ['entities_post', 'calendar_pricing_templates_post'], 'enable_max_depth' => true],
            security: "is_granted('ROLE_MANAGER')",
        ),
        new Put(
            denormalizationContext: ['groups' => ['entity_put', 'calendar_pricing_template_put'], 'enable_max_depth' => true],
            security: "is_granted('ROLE_MANAGER')",
        ),
        new Delete(security: "is_granted('ROLE_MANAGER')"),
    ],
)]
#[Entity(repositoryClass: PricingTemplateRepository::class)]
#[Table(name: 'calendar_accommodation_pricing_template')]
#[Cache(usage: 'NONSTRICT_READ_WRITE', region: 'calendar_participant')]
class PricingTemplate implements NameableInterface, DeletedInterface
{
    use NameableTrait;
    use DeletedTrait;

    public const string RATE_PER_NIGHT = 'per_night';
    public const string RATE_PER_STAY = 'per_stay';
    public const string RATE_PER_PERIOD = 'per_period';

    /** @var list<string> */
    public const array ALLOWED_RATE_KINDS = [self::RATE_PER_NIGHT, self::RATE_PER_STAY, self::RATE_PER_PERIOD];

    #[Column(type: 'string', length: 16)]
    #[Assert\Choice(choices: self::ALLOWED_RATE_KINDS)]
    protected string $rateKind = self::RATE_PER_NIGHT;

    /** Základní sazba (v haléřích/centech, dle konvence cen v systému). */
    #[Column(type: 'integer')]
    protected int $baseRate = 0;

    /** Sazba za přistýlku. */
    #[Column(type: 'integer')]
    protected int $extraBedRate = 0;

    /** Procento zálohy (Karlov: 30). */
    #[Column(type: 'integer', nullable: true)]
    protected ?int $depositPercentage = null;

    public function __construct(?Nameable $nameable = null, string $rateKind = self::RATE_PER_NIGHT, int $baseRate = 0)
    {
        $this->setFieldsFromNameable($nameable);
        $this->setRateKind($rateKind);
        $this->baseRate = $baseRate;
    }

    public function getRateKind(): string
    {
        return $this->rateKind;
    }

    public function setRateKind(string $rateKind): void
    {
        if (!in_array($rateKind, self::ALLOWED_RATE_KINDS, true)) {
            throw new \InvalidArgumentException("Neplatný typ sazby: '$rateKind'.");
        }
        $this->rateKind = $rateKind;
    }

    public function getBaseRate(): int
    {
        return $this->baseRate;
    }

    public function setBaseRate(int $baseRate): void
    {
        $this->baseRate = $baseRate;
    }

    public function getExtraBedRate(): int
    {
        return $this->extraBedRate;
    }

    public function setExtraBedRate(int $extraBedRate): void
    {
        $this->extraBedRate = $extraBedRate;
    }

    public function getDepositPercentage(): ?int
    {
        return $this->depositPercentage;
    }

    public function setDepositPercentage(?int $depositPercentage): void
    {
        $this->depositPercentage = $depositPercentage;
    }
}
