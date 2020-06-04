<?php
/**
 * @noinspection MethodShouldBeFinalInspection
 * @noinspection PhpUnused
 */

namespace OswisOrg\OswisCalendarBundle\Controller;

use OswisOrg\OswisCalendarBundle\Service\ParticipantService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;

class ParticipantController extends AbstractController
{
    public ParticipantService $participantService;

    public function __construct(ParticipantService $participantService)
    {
        $this->participantService = $participantService;
    }

    /**
     * Partners for homepage.
     * @return Response
     */
    public function partnersFooter(): Response
    {
        return $this->render(
            '@OswisOrgOswisCalendar/web/parts/partners-footer.html.twig',
            [
                'footerPartners' => $this->participantService->getWebPartners(),
            ]
        );
    }
}
