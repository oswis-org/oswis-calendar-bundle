<?php

namespace OswisOrg\OswisCalendarBundle\Entity\ParticipantMail;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use Doctrine\ORM\Mapping\Cache;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Table;
use OswisOrg\OswisCalendarBundle\Repository\Participant\ParticipantMailCategoryRepository;
use OswisOrg\OswisCoreBundle\Entity\AbstractClass\AbstractMailCategory;

/**
 * @author Jakub Zak <mail@jakubzak.eu>
 * @OswisOrg\OswisCoreBundle\Filter\SearchAnnotation({"id"})
 */
#[ApiResource(
    operations: [
        new GetCollection(
            normalizationContext: ['groups' => ['participant_mail_templates_get'], 'enable_max_depth' => true],
            security: "is_granted('ROLE_ADMIN')",
        ),
        new Post(
            denormalizationContext: ['groups' => ['participant_mail_templates_post'], 'enable_max_depth' => true],
            security: "is_granted('ROLE_ADMIN')",
        ),
        new Get(
            normalizationContext: ['groups' => ['participant_mail_template_get'], 'enable_max_depth' => true],
            security: "is_granted('ROLE_ADMIN')",
        ),
        new Put(
            denormalizationContext: ['groups' => ['participant_mail_template_put'], 'enable_max_depth' => true],
            security: "is_granted('ROLE_ADMIN')",
        ),
    ],
    filters: ['search'],
    normalizationContext: ['groups' => ['participant_mail_templates_get'], 'enable_max_depth' => true],
    denormalizationContext: ['groups' => ['participant_mail_templates_post'], 'enable_max_depth' => true],
    security: "is_granted('ROLE_ADMIN')",
)]
#[Entity(repositoryClass: ParticipantMailCategoryRepository::class)]
#[Table(name: 'calendar_participant_mail_category')]
#[Cache(usage: 'NONSTRICT_READ_WRITE', region: 'calendar_participant_mail')]
class ParticipantMailCategory extends AbstractMailCategory
{
}
