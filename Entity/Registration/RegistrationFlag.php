<?php
/**
 * @noinspection PhpUnused
 * @noinspection PropertyCanBePrivateInspection
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Entity\Registration;

use ApiPlatform\Core\Annotation\ApiFilter;
use ApiPlatform\Core\Bridge\Doctrine\Orm\Filter\OrderFilter;
use Doctrine\ORM\Mapping\Cache;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\Table;
use OswisOrg\OswisCalendarBundle\Repository\Registration\RegistrationFlagRepository;
use OswisOrg\OswisCoreBundle\Entity\NonPersistent\Nameable;
use OswisOrg\OswisCoreBundle\Interfaces\Common\NameableInterface;
use OswisOrg\OswisCoreBundle\Traits\Common\ColorTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\NameableTrait;

/**
 * RegistrationFlag is some specification of Participant. Each flag can adjust price and can be used only once in one participant.
 * @example Type of accommodation, food allergy, time of arrival/departure...
 * @OswisOrg\OswisCoreBundle\Filter\SearchAnnotation({
 *     "id",
 *     "name",
 *     "shortName",
 *     "description",
 *     "note"
 * })
 * @ApiPlatform\Core\Annotation\ApiResource(
 *   attributes={
 *     "filters"={"search"},
 *     "security"="is_granted('ROLE_MANAGER')"
 *   },
 *   collectionOperations={
 *     "get"={
 *       "security"="is_granted('ROLE_MANAGER')",
 *       "normalization_context"={"groups"={"entities_get", "calendar_participant_categories_get"}},
 *     },
 *     "post"={
 *       "security"="is_granted('ROLE_MANAGER')",
 *       "denormalization_context"={"groups"={"entities_post", "calendar_participant_categories_post"}}
 *     }
 *   },
 *   itemOperations={
 *     "get"={
 *       "security"="is_granted('ROLE_MANAGER')",
 *       "normalization_context"={"groups"={"entity_get", "calendar_participant_category_get"}},
 *     },
 *     "put"={
 *       "security"="is_granted('ROLE_MANAGER')",
 *       "denormalization_context"={"groups"={"entity_put", "calendar_participant_category_put"}}
 *     }
 *   }
 * )
 */
#[Entity(repositoryClass : RegistrationFlagRepository::class)]
#[Table(name : 'calendar_flag')]
#[Cache(usage : 'NONSTRICT_READ_WRITE', region : 'calendar_flag')]
#[ApiFilter(OrderFilter::class)]
class RegistrationFlag implements NameableInterface
{
    use NameableTrait;
    use ColorTrait;

    #[ManyToOne(targetEntity : RegistrationFlagCategory::class, fetch : 'EAGER')]
    #[JoinColumn(nullable : true)]
    protected ?RegistrationFlagCategory $category = null;

    #[Column(type : 'string', nullable : true)]
    protected ?string $flagFormGroup = null;

    public function __construct(?Nameable $nameable = null, ?RegistrationFlagCategory $flagType = null)
    {
        $this->setFieldsFromNameable($nameable);
        $this->setCategory($flagType);
    }

    public function getFlagFormGroup(): ?string
    {
        return $this->flagFormGroup;
    }

    public function setFlagFormGroup(?string $flagFormGroup): void
    {
        $this->flagFormGroup = $flagFormGroup;
    }

    public function getType(): ?string
    {
        return $this->getCategory()?->getType();
    }

    public function getCategory(): ?RegistrationFlagCategory
    {
        return $this->category;
    }

    public function setCategory(?RegistrationFlagCategory $category): void
    {
        $this->category = $category;
    }
}
