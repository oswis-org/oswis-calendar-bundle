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
 *          "path"="/event_participant_payment_action",
 *      },
 *  },
 *  itemOperations={},
 *  output=false
 * )
 */
final class EventParticipantPaymentActionRequest
{

    /**
     * @var int[]|null
     */
    public ?array $identifiers = null;

    /**
     * @var string|null
     * @Symfony\Component\Validator\Constraints\NotNull()
     * @Symfony\Component\Validator\Constraints\NotBlank()
     */
    public ?string $type = null;

    /**
     * @var string|null
     */
    public ?string $csvContent = null;

    /**
     * @var string|null
     */
    public ?string $csvDelimiter = null;

    /**
     * @var string|null
     */
    public ?string $csvEnclosure = null;

    /**
     * @var string|null
     */
    public ?string $csvEscape = null;

    /**
     * @var string|null
     */
    public ?string $csvVariableSymbolColumnName = null;

    /**
     * @var string|null
     */
    public ?string $csvDateColumnName = null;

    /**
     * @var string|null
     */
    public ?string $csvValueColumnName = null;

    /**
     * @var string|null
     */
    public ?string $csvCurrencyColumnName = null;

    /**
     * @var string|null
     */
    public ?string $csvCurrencyAllowed = null;

    /**
     * @var string|null
     */
    public ?string $csvEventParticipantType = null;

    /**
     * @var Event|null
     */
    public ?Event $event = null;
}
