<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Mail;

use Doctrine\DBAL\Connection;
use OswisOrg\OswisCoreBundle\Mail\Attachment\MailAttachmentIds;
use OswisOrg\OswisCoreBundle\Mail\Attachment\MailAttachmentUsageProviderInterface;

/**
 * Přílohy, které kalendář používá — úklid je nesmí smazat: koncepty a hromadné zprávy (i naplánované a zrušené,
 * ať jde zprávu znovu otevřít) a odeslané maily přihláškám (historie komunikace odkazuje na stažení).
 */
final readonly class ParticipantMailAttachmentUsage implements MailAttachmentUsageProviderInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function usedAttachmentIds(): array
    {
        return MailAttachmentIds::fromTables($this->connection, ['calendar_participant_mail_bulk', 'calendar_participant_mail']);
    }
}
