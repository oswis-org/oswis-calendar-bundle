<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Mail;

use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
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
        yield new MailBlock(
            'informace-o-akci',
            'Informace o akci',
            '@OswisOrgOswisCalendar/other/summary/event-summary.html.twig',
            static function (array $context): array {
                $participant = $context['participant'] ?? null;

                return ['event' => $participant instanceof Participant ? $participant->getEvent(false) : null];
            },
        );
    }
}
