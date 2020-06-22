<?php
/**
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Entity\Registration;

use OswisOrg\OswisCoreBundle\Entity\NonPersistent\Nameable;
use OswisOrg\OswisCoreBundle\Traits\Common\ColorTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\NameableTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\ValueTrait;

/**
 * Flag is some specification of Participant. Each flag can adjust price and can be used only once in one participant.
 *
 * @example Type of accommodation, food allergies, time of arrival/departure...
 * @Doctrine\ORM\Mapping\Entity(repositoryClass="OswisOrg\OswisCalendarBundle\Repository\ParticipantFlagRepository")
 * @Doctrine\ORM\Mapping\Table(name="calendar_participant_category")
 * @ApiPlatform\Core\Annotation\ApiResource(
 *   attributes={
 *     "filters"={"search"},
 *     "access_control"="is_granted('ROLE_MANAGER')"
 *   },
 *   collectionOperations={
 *     "get"={
 *       "access_control"="is_granted('ROLE_MANAGER')",
 *       "normalization_context"={"groups"={"calendar_participant_categories_get"}},
 *     },
 *     "post"={
 *       "access_control"="is_granted('ROLE_MANAGER')",
 *       "denormalization_context"={"groups"={"calendar_participant_categories_post"}}
 *     }
 *   },
 *   itemOperations={
 *     "get"={
 *       "access_control"="is_granted('ROLE_MANAGER')",
 *       "normalization_context"={"groups"={"calendar_participant_category_get"}},
 *     },
 *     "put"={
 *       "access_control"="is_granted('ROLE_MANAGER')",
 *       "denormalization_context"={"groups"={"calendar_participant_category_put"}}
 *     },
 *     "delete"={
 *       "access_control"="is_granted('ROLE_ADMIN')",
 *       "denormalization_context"={"groups"={"calendar_participant_category_delete"}}
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
 * @Doctrine\ORM\Mapping\Cache(usage="NONSTRICT_READ_WRITE", region="calendar_participant")
 */
class Flag
{
    use NameableTrait;
    use ColorTrait;

    /**
     * @Doctrine\ORM\Mapping\ManyToOne(targetEntity="OswisOrg\OswisCalendarBundle\Entity\Participant\RegistrationFlagCategory", fetch="EAGER")
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     */
    protected ?FlagCategory $category = null;

    public function __construct(?Nameable $nameable = null, ?FlagCategory $flagType = null)
    {
        $this->setFieldsFromNameable($nameable);
        $this->setCategory($flagType);
    }

    public function getType(): ?string
    {
        return $this->getCategory() ? $this->getCategory()->getType() : null;
    }

    public function getCategory(): ?FlagCategory
    {
        return $this->category;
    }

    public function setCategory(?FlagCategory $category): void
    {
        $this->category = $category;
    }
}
