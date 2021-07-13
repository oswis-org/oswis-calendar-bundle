<?php
/**
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Entity\Registration;

use OswisOrg\OswisCoreBundle\Entity\NonPersistent\Nameable;
use OswisOrg\OswisCoreBundle\Interfaces\Common\NameableInterface;
use OswisOrg\OswisCoreBundle\Traits\Common\ColorTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\NameableTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\ValueTrait;

/**
 * ParticipantFlag is some specification of Participant. Each flag can adjust price and can be used only once in one participant.
 * @example Type of accommodation, food allergies, time of arrival/departure...
 * @Doctrine\ORM\Mapping\Entity(repositoryClass="OswisOrg\OswisCalendarBundle\Repository\FlagOfParticipantRepository")
 * @Doctrine\ORM\Mapping\Table(name="calendar_flag")
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
 * @ApiPlatform\Core\Annotation\ApiFilter(ApiPlatform\Core\Bridge\Doctrine\Orm\Filter\OrderFilter::class)
 * @OswisOrg\OswisCoreBundle\Filter\SearchAnnotation({
 *     "id",
 *     "name",
 *     "shortName",
 *     "description",
 *     "note"
 * })
 * @Doctrine\ORM\Mapping\Cache(usage="NONSTRICT_READ_WRITE", region="calendar_flag")
 */
class ParticipantFlag implements NameableInterface
{
    use NameableTrait;
    use ColorTrait;

    /**
     * @Doctrine\ORM\Mapping\ManyToOne(targetEntity="ParticipantFlagCategory", fetch="EAGER")
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     */
    protected ?ParticipantFlagCategory $category = null;

    /**
     * @Doctrine\ORM\Mapping\Column(type="string", nullable=true)
     */
    protected ?string $flagFormGroup = null;

    public function __construct(?Nameable $nameable = null, ?ParticipantFlagCategory $flagType = null)
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

    public function getCategory(): ?ParticipantFlagCategory
    {
        return $this->category;
    }

    public function setCategory(?ParticipantFlagCategory $category): void
    {
        $this->category = $category;
    }
}
