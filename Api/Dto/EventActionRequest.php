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
     * @var string|null
     * @Symfony\Component\Validator\Constraints\NotNull()
     * @Symfony\Component\Validator\Constraints\NotBlank()
     */
    public ?string $type = null;

    /**
     * @var Event|null
     */
    public ?Event $event = null;

    /**
     * @var int|null
     */
    public ?int $count = null;

    /**
     * @var int|null
     */
    public ?int $startId = null;

    /**
     * @var int|null
     */
    public ?int $endId = null;

    /**
     * @var int|null
     */
    public ?int $recursiveDepth = null;
}
