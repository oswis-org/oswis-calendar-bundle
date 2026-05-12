<?php

namespace OswisOrg\OswisCalendarBundle\Controller\WebAdmin;

use OswisOrg\OswisCalendarBundle\Repository\Participant\ParticipantRepository;
use OswisOrg\OswisCalendarBundle\Service\Participant\ParticipantService;
use OswisOrg\OswisCoreBundle\Exceptions\OswisException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;

final class WebAdminParticipantsController extends AbstractController
{
    public function __construct(
        private readonly ParticipantService $participantService,
    ) {
    }

    /**
     * @throws OswisException
     */
    public function sendAutoMails(int $limit = 100, ?string $type = null): Response
    {
        $this->participantService->sendAutoMails(null, $type, $limit);

        return $this->render('@OswisOrgOswisCore/web/pages/message.html.twig', [
            'title'     => 'Akce provedena.',
            'pageTitle' => 'Akce provedena.',
            'message'   => 'E-maily rozeslány.',
        ]);
    }

    /**
     * Show one participant's detail page used as an arrival check-in screen.
     *
     * The `$arrival` route argument is currently only used to pick the lookup
     * strictness: `true` shows only an active (non-deleted, activated)
     * participant, anything else (`false` / `null`) widens the lookup to also
     * include deleted/non-activated rows. No DB mutation happens here despite
     * the route name — the actual arrival timestamp is set elsewhere.
     */
    public function arrival(int $participantId, ?bool $arrival = true): Response
    {
        $strict = (true === $arrival);
        $participant = $this->participantService->getParticipant(
            [
                ParticipantRepository::CRITERIA_ID              => $participantId,
                ParticipantRepository::CRITERIA_INCLUDE_DELETED => !$strict,
            ],
            !$strict,
        );

        return $this->render('@OswisOrgOswisCalendar/web_admin/participant.html.twig', [
            'participant' => $participant,
        ]);
    }
}
