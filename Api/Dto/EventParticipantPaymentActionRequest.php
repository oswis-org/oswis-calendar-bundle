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
    public $identifiers;

    /**
     * @var string|null
     * @Symfony\Component\Validator\Constraints\NotNull()
     * @Symfony\Component\Validator\Constraints\NotBlank()
     */
    public $type;

    /**
     * @var string|null
     */
    public $csvContent;

    /**
     * @var string|null
     */
    public $csvDelimiter;

    /**
     * @var string|null
     */
    public $csvEnclosure;

    /**
     * @var string|null
     */
    public $csvEscape;

    /**
     * @var string|null
     */
    public $csvVariableSymbolColumnName;

    /**
     * @var string|null
     */
    public $csvDateColumnName;

    /**
     * @var string|null
     */
    public $csvValueColumnName;

    /**
     * @var string|null
     */
    public $csvCurrencyColumnName;

    /**
     * @var string|null
     */
    public $csvCurrencyAllowed;

    /**
     * @var string|null
     */
    public $csvEventParticipantType;

    /**
     * @var Event|null
     */
    public $event;
}
