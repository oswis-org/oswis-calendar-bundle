<?php
/**
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Entity\ParticipantMail;

use OswisOrg\OswisCalendarBundle\Entity\Event\Event;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCoreBundle\Entity\AbstractClass\AbstractEMailGroup;
use OswisOrg\OswisCoreBundle\Entity\AbstractClass\AbstractMailGroup;
use OswisOrg\OswisCoreBundle\Entity\NonPersistent\DateTimeRange;
use OswisOrg\OswisCoreBundle\Entity\NonPersistent\Nameable;
use OswisOrg\OswisCoreBundle\Entity\TwigTemplate\TwigTemplate;

/**
 * @Doctrine\ORM\Mapping\Entity(repositoryClass="OswisOrg\OswisCalendarBundle\Repository\ParticipantMailGroupRepository")
 * @Doctrine\ORM\Mapping\Table(name="calendar_participant_mail_group")
 * @ApiPlatform\Core\Annotation\ApiResource(
 *   attributes={
 *     "filters"={"search"},
 *     "security"="is_granted('ROLE_ADMIN')",
 *     "normalization_context"={"groups"={"participant_mail_groups_get"}, "enable_max_depth"=true},
 *     "denormalization_context"={"groups"={"participant_mail_groups_post"}, "enable_max_depth"=true}
 *   },
 *   collectionOperations={
 *     "get"={
 *       "security"="is_granted('ROLE_ADMIN')",
 *       "normalization_context"={"groups"={"participant_mail_groups_get"}, "enable_max_depth"=true},
 *     },
 *     "post"={
 *       "security"="is_granted('ROLE_ADMIN')",
 *       "denormalization_context"={"groups"={"participant_mail_groups_post"}, "enable_max_depth"=true}
 *     }
 *   },
 *   itemOperations={
 *     "get"={
 *       "security"="is_granted('ROLE_ADMIN')",
 *       "normalization_context"={"groups"={"participant_mail_group_get"}, "enable_max_depth"=true},
 *     },
 *     "put"={
 *       "security"="is_granted('ROLE_ADMIN')",
 *       "denormalization_context"={"groups"={"participant_mail_group_put"}, "enable_max_depth"=true}
 *     }
 *   }
 * )
 * @OswisOrg\OswisCoreBundle\Filter\SearchAnnotation({"id"})
 * @author Jakub Zak <mail@jakubzak.eu>
 * @Doctrine\ORM\Mapping\Cache(usage="NONSTRICT_READ_WRITE", region="calendar_participant_mail")
 */
class ParticipantMailGroup extends AbstractMailGroup
{
    /**
     * @Doctrine\ORM\Mapping\ManyToOne(targetEntity="OswisOrg\OswisCalendarBundle\Entity\ParticipantMail\ParticipantMailCategory", fetch="EAGER")
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     */
    protected ?ParticipantMailCategory $category = null;

    /**
     * @Doctrine\ORM\Mapping\ManyToOne(targetEntity="OswisOrg\OswisCalendarBundle\Entity\Event\Event", fetch="EAGER")
     * @Doctrine\ORM\Mapping\JoinColumn(nullable=true)
     */
    protected ?Event $event = null;

    /**
     * @Doctrine\ORM\Mapping\Column(type="boolean", nullable=false)
     */
    protected bool $onlyActive = true;

    public function __construct(
        ?Nameable $nameable = null,
        ?int $priority = null,
        ?DateTimeRange $range = null,
        ?TwigTemplate $twigTemplate = null,
        bool $automaticMailing = false,
        ParticipantMailCategory $participantMailCategory = null
    ) {
        parent::__construct($nameable, $priority, $range, $twigTemplate, $automaticMailing);
        $this->setCategory($participantMailCategory);
    }

    public function isCategory(?ParticipantMailCategory $category): bool
    {
        return $this->getCategory() === $category;
    }

    public function getCategory(): ?ParticipantMailCategory
    {
        return $this->category;
    }

    public function setCategory(?ParticipantMailCategory $category): void
    {
        $this->category = $category;
    }

    public function isType(?string $type): bool
    {
        return $this->getType() === $type;
    }

    public function getType(): ?string
    {
        return $this->getCategory()?->getType();
    }

    public function getEvent(): ?Event
    {
        return $this->event;
    }

    public function setEvent(?Event $event): void
    {
        $this->event = $event;
    }

    public function isOnlyActive(): bool
    {
        return $this->onlyActive;
    }

    public function setOnlyActive(bool $onlyActive): void
    {
        $this->onlyActive = $onlyActive;
    }

    public function isApplicableByRestrictions(?object $entity): bool
    {
        if (!($entity instanceof Participant)) {
            return false;
        }
        if ($this->onlyActive && !$entity->isActive()) {
            return false;
        }
        if ($this->event && !$entity->isContainedInEvent($this->event)) {
            return false;
        }

        return true;
    }
}
