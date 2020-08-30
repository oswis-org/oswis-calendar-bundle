<?php
/**
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Controller\WebAdmin;

use OswisOrg\OswisCalendarBundle\Service\ParticipantService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;

class WebAdminParticipantsController extends AbstractController
{
    public ParticipantService $participantService;

    public function __construct(ParticipantService $participantService)
    {
        $this->participantService = $participantService;
    }

    public function sendAutoMails(): Response
    {
        $this->participantService->sendAutoMails();

        return $this->render(
            '@OswisOrgOswisCore/web/pages/message.html.twig',
            [
                'title'     => "Akce provedena.",
                'pageTitle' => "Akce provedena.",
                'message'   => "E-maily rozeslány.",
            ]
        );
    }

}
