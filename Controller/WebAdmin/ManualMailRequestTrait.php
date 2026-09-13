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
    private function manualMailFromRequest(Request $request): ParticipantManualMail
    {
        return new ParticipantManualMail(
            trim((string) $request->request->get('subject', '')),
            (string) $request->request->get('body', ''),
            (string) $request->request->get('templateSlug', ''),
            $this->adminName(),
        );
    }

    /** Přihlášený správce — jeho jméno se propíše do podpisu ruční zprávy. */
    private function adminName(): ?string
    {
        $user = $this->getUser();

        return $user instanceof UserInterface ? $user->getUserIdentifier() : null;
    }
}
