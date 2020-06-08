<?php /** @noinspection PhpUnused */

namespace OswisOrg\OswisCalendarBundle\Api\Dto;

use ApiPlatform\Core\Annotation\ApiResource;
use OswisOrg\OswisCalendarBundle\Entity\Event\Event;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantCategory;

/**
 * @ApiResource(
 *  attributes={
 *      "access_control"="is_granted('ROLE_MANAGER')",
 *  },
 *  collectionOperations={
 *      "post"={
 *          "path"="/participant_list_action",
 *      },
 *  },
 *  itemOperations={}
 * )
 */
final class ParticipantListActionRequest
{
    /**
     * @Symfony\Component\Validator\Constraints\NotNull()
     * @Symfony\Component\Validator\Constraints\NotBlank()
     */
    public ?Event $event = null;

    public ?ParticipantCategory $eventParticipantType = null;

    public ?string $title = null;

    public ?bool $detailed = null;
}
