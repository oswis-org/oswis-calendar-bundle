<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Entity\Staff;

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
use OswisOrg\OswisCoreBundle\Entity\NonPersistent\Nameable;
use OswisOrg\OswisCoreBundle\Interfaces\Common\NameableInterface;
use OswisOrg\OswisCoreBundle\Traits\Common\ColorTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\NameableTrait;

/**
 * Číselník FUNKCÍ/ROLÍ týmu (řízení, jídelna, svolávání, technika, vede, dozor, chystání…).
 *
 * Sdílený mezi celodenními službami i per-aktivitními rolemi ({@see StaffAssignment}) — svolávání je
 * i denní služba, i role u konkrétní aktivity, proto JEDEN číselník, ne dva překrývající se seznamy.
 * Konfigurovatelný per nasazení (deployment si nadefinuje svoje — konference „registrace"/„zvuk"…),
 * proto DATA, ne enum v kódu. Duty-doménová obdoba {@see \OswisOrg\OswisCalendarBundle\Entity\Event\EventCategory},
 * NE EventCategory samotný (funkce nejsou událost — viz [[reference_event_is_only_happenings]]).
 *
 * `type` = stabilní kód (rizeni/jidelna/…), `name` = zobrazované jméno, `color` = barva čipu,
 * `appliesTo` = kde se funkce NABÍZÍ (služba / u aktivity / obojí) — NE jak dlouho trvá. Zda je
 * konkrétní služba celodenní nebo jen na snídani/oběd/večeři se řeší časem u {@see StaffAssignment}.
 */
#[ApiResource(
    operations: [
        new GetCollection(
            security: "is_granted('ROLE_MANAGER')",
            normalizationContext: ['groups' => ['calendar_staff_roles_get']],
        ),
        new Post(
            security: "is_granted('ROLE_MANAGER')",
            denormalizationContext: ['groups' => ['calendar_staff_roles_post']],
        ),
        new Get(
            security: "is_granted('ROLE_MANAGER')",
            normalizationContext: ['groups' => ['calendar_staff_role_get']],
        ),
        new Put(
            security: "is_granted('ROLE_MANAGER')",
            denormalizationContext: ['groups' => ['calendar_staff_role_put']],
        ),
        new Delete(security: "is_granted('ROLE_ADMIN')"),
    ],
    security: "is_granted('ROLE_MANAGER')",
)]
#[Entity]
#[Table(name: 'calendar_staff_role')]
#[Cache(usage: 'NONSTRICT_READ_WRITE', region: 'calendar_event')]
class StaffRole implements NameableInterface
{
    use NameableTrait;
    use ColorTrait;

    /**
     * Stabilní kód role (rizeni/technika/…). VOLNĚ konfigurovatelný per nasazení — NENÍ pevný enum
     * (na rozdíl od EventCategory::type), aby si každé nasazení nadefinovalo svoje funkce. Proto
     * BEZ TypeTrait (ten validuje proti povolenému seznamu).
     */
    #[Column(type: 'string', nullable: true)]
    protected ?string $type = null;

    /** Služba (řízení/jídelna) — nabízí se v rozpisu služeb (u přiřazení bez konkrétní aktivity). */
    public const string APPLIES_SERVICE = 'service';

    /** Role u konkrétní aktivity (vede/technika) — `activity` u přiřazení je vyplněná. */
    public const string APPLIES_ACTIVITY = 'activity';

    /** Použitelné v obou rovinách (typicky svolávání). */
    public const string APPLIES_BOTH = 'both';

    /** Kde se role používá: {@see APPLIES_SERVICE}/{@see APPLIES_ACTIVITY}/{@see APPLIES_BOTH}. */
    #[Column(type: 'string', nullable: true)]
    protected ?string $appliesTo = self::APPLIES_BOTH;

    public function __construct(
        ?Nameable $nameable = null,
        ?string $type = null,
        ?string $color = null,
        ?string $appliesTo = self::APPLIES_BOTH,
    ) {
        $this->setFieldsFromNameable($nameable);
        $this->setType($type);
        $this->setColor($color);
        $this->appliesTo = $appliesTo;
    }

    public function getAppliesTo(): ?string
    {
        return $this->appliesTo;
    }

    public function setAppliesTo(?string $appliesTo): void
    {
        $this->appliesTo = $appliesTo;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(?string $type): void
    {
        $this->type = null !== $type && '' !== $type ? $type : null;
    }

    /** Použitelná jako služba (service nebo both)? */
    public function isForService(): bool
    {
        return in_array($this->appliesTo, [self::APPLIES_SERVICE, self::APPLIES_BOTH, null], true);
    }

    /** Použitelná u konkrétní aktivity (activity nebo both)? */
    public function isForActivity(): bool
    {
        return in_array($this->appliesTo, [self::APPLIES_ACTIVITY, self::APPLIES_BOTH, null], true);
    }
}
