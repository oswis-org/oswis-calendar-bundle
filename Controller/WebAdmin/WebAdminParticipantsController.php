<?php

namespace OswisOrg\OswisCalendarBundle\Controller\WebAdmin;

use OswisOrg\OswisCalendarBundle\Repository\Participant\ParticipantRepository;
use OswisOrg\OswisCalendarBundle\Service\Participant\ParticipantService;
use OswisOrg\OswisCoreBundle\Exceptions\OswisException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class WebAdminParticipantsController extends AbstractController
{
    public function __construct(
        private readonly ParticipantService $participantService,
    ) {
    }

    /**
     * @throws OswisException
     */
    #[IsGranted('ROLE_ADMIN')]
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

    /**
     * Restore a soft-deleted participant (set deletedAt back to null).
     * Cascade-deleted children (flags, registrations) stay deleted — admin
     * can verify and act on them from the participant detail page.
     */
    #[IsGranted('ROLE_ADMIN')]
    public function restore(Request $request, int $participantId): Response
    {
        if (!$this->isCsrfTokenValid('participant_restore_'.$participantId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Neplatný CSRF token.');
        }
        $participant = $this->participantService->getParticipant(
            [
                ParticipantRepository::CRITERIA_ID              => $participantId,
                ParticipantRepository::CRITERIA_INCLUDE_DELETED => true,
            ],
            true,
        ) ?? throw $this->createNotFoundException('Účastník nenalezen.');

        $this->participantService->restore($participant);
        $this->addFlash('success', sprintf(
            'Účastník #%d obnoven. Případné smazané flagy a registrace zůstávají smazané — zkontroluj na detailu.',
            $participantId,
        ));

        $redirectUrl = (string) $request->request->get('_redirect', $this->generateUrl(
            'oswis_org_oswis_calendar_web_admin_participant_arrival',
            ['participantId' => $participantId, 'arrival' => '0'],
        ));

        return new RedirectResponse($redirectUrl);
    }
}
