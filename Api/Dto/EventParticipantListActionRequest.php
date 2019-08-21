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
    public $event;

    /**
     * @var EventParticipantType|null
     */
    public $eventParticipantType;

    /**
     * @var string|null
     */
    public $title;

    /**
     * @var bool|null
     */
    public $detailed;
}
