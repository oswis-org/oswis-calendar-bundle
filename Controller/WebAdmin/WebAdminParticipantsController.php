<?php
/**
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Controller\WebAdmin;

use OswisOrg\OswisCalendarBundle\Repository\Participant\ParticipantRepository;
use OswisOrg\OswisCalendarBundle\Service\Participant\ParticipantService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;

class WebAdminParticipantsController extends AbstractController
{
    public function __construct(
        public ParticipantService $participantService,
    ) {
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

    public function arrival(int $participantId, ?bool $arrival = true): Response
    {
        $participant = null;
        if (true === $arrival) {
            // Process arrival.
            $participant = $this->participantService->getParticipant(
                [
                    ParticipantRepository::CRITERIA_ID              => $participantId,
                    ParticipantRepository::CRITERIA_INCLUDE_DELETED => false,
                ],
                false
            );
        }
        if (false === $arrival) {
            // Process de-arrival.
            $participant = $this->participantService->getParticipant(
                [
                    ParticipantRepository::CRITERIA_ID              => $participantId,
                    ParticipantRepository::CRITERIA_INCLUDE_DELETED => true,
                ],
                true
            );
        }
        if (null === $arrival) {
            // Only show.
            $participant = $this->participantService->getParticipant(
                [
                    ParticipantRepository::CRITERIA_ID              => $participantId,
                    ParticipantRepository::CRITERIA_INCLUDE_DELETED => true,
                ],
                true
            );
        }

        return $this->render('@OswisOrgOswisCalendar/web_admin/participant.html.twig', ['participant' => $participant]);
    }

}
