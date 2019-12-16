<?php /** @noinspection PhpUnused */

namespace Zakjakub\OswisCalendarBundle\Api\Dto;

use ApiPlatform\Core\Annotation\ApiResource;
use Zakjakub\OswisCalendarBundle\Entity\Event\Event;

/**
 * @ApiResource(
 *  attributes={
 *      "access_control"="is_granted('ROLE_MANAGER')",
 *  },
 *  collectionOperations={
 *      "post"={
 *          "path"="/event_action",
 *      },
 *  },
 *  itemOperations={},
 *  output=false
 * )
 */
final class EventActionRequest
{
    /**
     * @Symfony\Component\Validator\Constraints\NotNull()
     * @Symfony\Component\Validator\Constraints\NotBlank()
     */
    public ?string $type = null;

    public ?Event $event = null;

    public ?int $count = null;

    public ?int $startId = null;

    public ?int $endId = null;

    public ?int $recursiveDepth = null;

    public ?string $eventParticipantTypeOfType = null;
}
