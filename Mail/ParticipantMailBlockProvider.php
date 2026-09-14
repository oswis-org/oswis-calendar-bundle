<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Mail;

use OswisOrg\OswisCoreBundle\Mail\Block\MailBlock;
use OswisOrg\OswisCoreBundle\Mail\Block\MailBlockProviderInterface;

/** Bloky z kódu pro maily k přihlášce (nahrazují `{% include '@…/participant-summary…' %}`). */
final class ParticipantMailBlockProvider implements MailBlockProviderInterface
{
    public function getBlocks(): iterable
    {
        yield new MailBlock(
            'rekapitulace-prihlasky',
            'Rekapitulace přihlášky',
            '@OswisOrgOswisCalendar/other/summary/participant-summary.html.twig',
        );
        // Totéž, co sekce „Událost" v rekapitulaci. Ne `event-summary.html.twig` — to jsou jen
        // neviditelná metadata pro Gmail a do zprávy by nevložila nic, co by adresát viděl.
        yield new MailBlock(
            'informace-o-akci',
            'Informace o akci',
            '@OswisOrgOswisCalendar/other/summary/event-info.html.twig',
        );
    }
}
