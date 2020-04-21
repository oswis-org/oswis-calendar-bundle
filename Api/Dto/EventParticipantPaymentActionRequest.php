<?php /** @noinspection PhpUnused */

namespace OswisOrg\OswisCalendarBundle\Api\Dto;

use ApiPlatform\Core\Annotation\ApiResource;
use OswisOrg\OswisCalendarBundle\Entity\Event\Event;

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
     * @Symfony\Component\Validator\Constraints\NotNull()
     * @Symfony\Component\Validator\Constraints\NotBlank()
     */
    public ?string $type = null;

    public ?string $csvContent = null;

    public ?string $csvDelimiter = null;

    public ?string $csvEnclosure = null;

    public ?string $csvEscape = null;

    public ?string $csvVariableSymbolColumnName = null;

    public ?string $csvDateColumnName = null;

    public ?string $csvValueColumnName = null;

    public ?string $csvCurrencyColumnName = null;

    public ?string $csvCurrencyAllowed = null;

    public ?string $csvEventParticipantType = null;

    public ?Event $event = null;
}
