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
    public ?array $identifiers;

    /**
     * @var string|null
     * @Symfony\Component\Validator\Constraints\NotNull()
     * @Symfony\Component\Validator\Constraints\NotBlank()
     */
    public ?string $type;

    /**
     * @var string|null
     */
    public ?string $csvContent;

    /**
     * @var string|null
     */
    public ?string $csvDelimiter;

    /**
     * @var string|null
     */
    public ?string $csvEnclosure;

    /**
     * @var string|null
     */
    public ?string $csvEscape;

    /**
     * @var string|null
     */
    public ?string $csvVariableSymbolColumnName;

    /**
     * @var string|null
     */
    public ?string $csvDateColumnName;

    /**
     * @var string|null
     */
    public ?string $csvValueColumnName;

    /**
     * @var string|null
     */
    public ?string $csvCurrencyColumnName;

    /**
     * @var string|null
     */
    public ?string $csvCurrencyAllowed;

    /**
     * @var string|null
     */
    public ?string $csvEventParticipantType;

    /**
     * @var Event|null
     */
    public ?Event $event;
}
