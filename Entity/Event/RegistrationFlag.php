<?php
/**
 * @noinspection MethodShouldBeFinalInspection
 * @noinspection PhpUnused
 */

namespace OswisOrg\OswisCalendarBundle\Entity\Event;

use ApiPlatform\Core\Annotation\ApiFilter;
use ApiPlatform\Core\Annotation\ApiResource;
use ApiPlatform\Core\Bridge\Doctrine\Orm\Filter\OrderFilter;
use OswisOrg\OswisCoreBundle\Entity\NonPersistent\Nameable;
use OswisOrg\OswisCoreBundle\Filter\SearchAnnotation as Searchable;
use OswisOrg\OswisCoreBundle\Traits\Common\NameableTrait;
use OswisOrg\OswisCoreBundle\Traits\Common\ValueTrait;

/**
 * Flag is some specification of Participant. Each flag can adjust price and can be used only once in one participant.
 *
 * @example Type of accommodation, food allergies, time of arrival/departure...
 * @Doctrine\ORM\Mapping\Entity(repositoryClass="OswisOrg\OswisCalendarBundle\Repository\ParticipantFlagRepository")
 * @Doctrine\ORM\Mapping\Table(name="calendar_participant_category")
 * @ApiResource(
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
 * @ApiFilter(OrderFilter::class)
 * @Searchable({
 *     "id",
 *     "name",
 *     "shortName",
 *     "description",
 *     "note"
 * })
 * @Doctrine\ORM\Mapping\Cache(usage="NONSTRICT_READ_WRITE", region="calendar_participant")
 */
class RegistrationFlag
{
    use NameableTrait;
    use ValueTrait;

    /**
     * @Doctrine\ORM\Mapping\ManyToOne(targetEntity="OswisOrg\OswisCalendarBundle\Entity\Participant\RegistrationFlagCategory", fetch="EAGER")
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     */
    protected ?RegistrationFlagCategory $category = null;

    public function __construct(?Nameable $nameable = null, ?RegistrationFlagCategory $flagType = null)
    {
        $this->setFieldsFromNameable($nameable);
        $this->setCategory($flagType);
    }

    public function getType(): ?string
    {
        return $this->getCategory() ? $this->getCategory()->getType() : null;
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
