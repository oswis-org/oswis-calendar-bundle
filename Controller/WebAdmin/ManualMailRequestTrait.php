<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Controller\WebAdmin;

use OswisOrg\OswisCalendarBundle\Service\Participant\ParticipantManualMail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Ruční zpráva z požadavku — jedno místo pro hromadný mail, „Novou zprávu" i náhled editoru, aby
 * náhled počítal se stejným textem i odesílatelem (podpis) jako odeslání.
 *
 * @phpstan-require-extends AbstractController
 */
trait ManualMailRequestTrait
{
    /** Předmět, text (Twig) a případná uložená kampaň z POSTu — vykreslí se až pro každého příjemce. */
    /**
     * @param bool $allowDocument příznak „celý dokument" smí nastavit JEN náhled — odesílací cesty ho
     *                            ignorují, aby se hromadným mailem nedal poslat syrový zdroj kampaně
     */
    private function manualMailFromRequest(Request $request, bool $allowDocument = false): ParticipantManualMail
    {
        return new ParticipantManualMail(
            trim((string) $request->request->get('subject', '')),
            (string) $request->request->get('body', ''),
            (string) $request->request->get('templateSlug', ''),
            $this->adminName(),
            $allowDocument && '1' === (string) $request->request->get('document', ''),
        );
    }

    /** Přihlášený správce — jeho jméno se propíše do podpisu ruční zprávy. */
    private function adminName(): ?string
    {
        $user = $this->getUser();

        return $user instanceof UserInterface ? $user->getUserIdentifier() : null;
    }
}
