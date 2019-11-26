<?php /** @noinspection PhpUnused */

namespace Zakjakub\OswisCalendarBundle\Api\Dto;

use ApiPlatform\Core\Annotation\ApiResource;
use Zakjakub\OswisCalendarBundle\Entity\Event\Event;
use Zakjakub\OswisCalendarBundle\Form\EventParticipant\EventParticipantType;

/**
 * @ApiResource(
 *  attributes={
 *      "access_control"="is_granted('ROLE_MANAGER')",
 *  },
 *  collectionOperations={
 *      "post"={
 *          "path"="/event_participant_list_action",
 *      },
 *  },
 *  itemOperations={}
 * )
 */
final class EventParticipantListActionRequest
{
    /**
     * @var Event|null
     * @Symfony\Component\Validator\Constraints\NotNull()
     * @Symfony\Component\Validator\Constraints\NotBlank()
     */
    public ?Event $event;

    /**
     * @var EventParticipantType|null
     */
    public ?EventParticipantType $eventParticipantType;

    /**
     * @var string|null
     */
    public ?string $title;

    /**
     * @var bool|null
     */
    public ?bool $detailed;
}
