<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Service\Participant;

use OswisOrg\OswisAddressBookBundle\Entity\Person;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Service\Payment\PaymentDeadlineCalculator;
use OswisOrg\OswisCoreBundle\Entity\AppUser\AppUser;

/**
 * JEDINÉ místo, kde se skládá kontext šablony mailu k přihlášce (spec 2026-09-13 §3.4) — pro
 * systémové maily, kampaně, ruční maily (1 i N) i náhled.
 *
 * PROČ: stejné pole se dřív skládalo na 7 místech a jedno zapomnělo `f` → hromadný mail vykal
 * uprostřed tykaného textu (10. 9. 2026).
 */
final class ParticipantMailContextFactory
{
    public function __construct(private readonly PaymentDeadlineCalculator $paymentDeadlines)
    {
    }

    /**
     * @param array<string, mixed> $extra doplňky konkrétního mailu (type, category, payment…); přepisují základ
     *
     * @return array<string, mixed>
     */
    public function create(Participant $participant, ?AppUser $appUser = null, array $extra = []): array
    {
        $contact = $participant->getContact();

        return array_merge([
            'participant'      => $participant,
            'appUser'          => $appUser,
            'contact'          => $contact,
            'salutationName'   => $contact instanceof Person ? $contact->getSalutationName() : $contact?->getName(),
            // Tykání/vykání JEN z přihlášky (příznak > kategorie); kontakt ani účet sloupec `formal` nemají.
            'f'                => $participant->isFormal(true) ?? false,
            // Totéž, co dopočítá message.html.twig (`contact.czechSuffixA|default(…)`), ale výslovně,
            // aby to měl i text mailu vykreslovaný mimo tu šablonu (MailRenderer).
            'a'                => $contact instanceof Person ? $contact->getCzechSuffixA() : '',
            'registrations'    => $participant->getParticipantRegistrations(true),
            'paymentDeadlines' => $this->paymentDeadlines->forParticipant($participant),
        ], $extra);
    }
}
