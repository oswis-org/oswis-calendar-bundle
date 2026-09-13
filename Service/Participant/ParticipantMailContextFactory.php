<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Service\Participant;

use OswisOrg\OswisAddressBookBundle\Entity\AbstractClass\AbstractContact;
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

    /**
     * Kontext bez údajů konkrétního systémového mailu — pro ruční zprávy, náhled a kontrolu. Klíče
     * systémových mailů (platba, QR kódy, token) jsou přítomné s neutrální hodnotou, aby je šablona
     * mohla číst i se striktními proměnnými; QR kódy vznikají až při odeslání (cid:), tady prázdné.
     * Bez `$appUser` se vezme první adresát přihlášky (u organizace jich může být víc).
     *
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    public function createNeutral(Participant $participant, ?AppUser $appUser = null, array $extra = []): array
    {
        return $this->create($participant, $appUser ?? self::recipientUsers($participant)[0] ?? null, array_merge([
            'type'             => 'preview',
            'category'         => null,
            'participantToken' => null,
            'isIS'             => false,
            'payment'          => null,
            'depositQr'        => '',
            'restQr'           => '',
        ], $extra));
    }

    /**
     * Účty, kterým mail k přihlášce jde: kontaktní osoby s aktivovaným účtem (osoba = 1, organizace = N).
     *
     * @return list<AppUser>
     */
    public static function recipientUsers(Participant $participant): array
    {
        $users = [];
        foreach ($participant->getContactPersons(true) as $contactPerson) {
            if ($contactPerson instanceof AbstractContact && null !== ($appUser = $contactPerson->getAppUser())) {
                $users[] = $appUser;
            }
        }

        return $users;
    }
}
