<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Mail;

use OswisOrg\OswisCoreBundle\Mail\Parent\MailParent;
use OswisOrg\OswisCoreBundle\Mail\Parent\MailParentProviderInterface;

/** Obálky mailů k přihláškám — pro pole „Vychází z" u šablony e-mailu. */
final class ParticipantMailParentProvider implements MailParentProviderInterface
{
    public function getParents(): iterable
    {
        yield new MailParent(
            '@OswisOrgOswisCalendar/e-mail/pages/participant-universal.html.twig',
            'Mail k přihlášce — podoba podle typu (ověření, shrnutí přihlášky)',
        );
        yield new MailParent(
            '@OswisOrgOswisCalendar/e-mail/pages/participant-payment.html.twig',
            'Potvrzení platby — částka, termíny a QR k doplatku',
        );
    }
}
