<?php

namespace OswisOrg\OswisCalendarBundle\Entity\ParticipantMail;

use Doctrine\ORM\Mapping\Cache;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Table;
use OswisOrg\OswisCalendarBundle\Repository\Participant\ParticipantMailCategoryRepository;
use OswisOrg\OswisCoreBundle\Entity\AbstractClass\AbstractMailCategory;

/**
 * @author Jakub Zak <mail@jakubzak.eu>
 * @OswisOrg\OswisCoreBundle\Filter\SearchAnnotation({"id"})
 * @ApiPlatform\Core\Annotation\ApiResource(
 *   attributes={
 *     "filters"={"search"},
 *     "security"="is_granted('ROLE_ADMIN')",
 *     "normalization_context"={"groups"={"participant_mail_templates_get"}, "enable_max_depth"=true},
 *     "denormalization_context"={"groups"={"participant_mail_templates_post"}, "enable_max_depth"=true}
 *   },
 *   collectionOperations={
 *     "get"={
 *       "security"="is_granted('ROLE_ADMIN')",
 *       "normalization_context"={"groups"={"participant_mail_templates_get"}, "enable_max_depth"=true},
 *     },
 *     "post"={
 *       "security"="is_granted('ROLE_ADMIN')",
 *       "denormalization_context"={"groups"={"participant_mail_templates_post"}, "enable_max_depth"=true}
 *     }
 *   },
 *   itemOperations={
 *     "get"={
 *       "security"="is_granted('ROLE_ADMIN')",
 *       "normalization_context"={"groups"={"participant_mail_template_get"}, "enable_max_depth"=true},
 *     },
 *     "put"={
 *       "security"="is_granted('ROLE_ADMIN')",
 *       "denormalization_context"={"groups"={"participant_mail_template_put"}, "enable_max_depth"=true}
 *     }
 *   }
 * )
 */
#[Entity(repositoryClass : ParticipantMailCategoryRepository::class)]
#[Table(name : 'calendar_participant_mail_category')]
#[Cache(usage : 'NONSTRICT_READ_WRITE', region : 'calendar_participant_mail')]
class ParticipantMailCategory extends AbstractMailCategory
{
}
