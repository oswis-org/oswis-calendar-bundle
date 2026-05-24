<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Controller\WebAdmin;

use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Form\WebAdmin\AdHocMailType;
use OswisOrg\OswisCalendarBundle\Repository\Participant\ParticipantRepository;
use OswisOrg\OswisCalendarBundle\Service\Participant\ParticipantMailService;
use OswisOrg\OswisCalendarBundle\Service\Participant\ParticipantService;
use OswisOrg\OswisCoreBundle\Exceptions\OswisException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Phase 4 (C) — compose ad-hoc admin mail to one participant.
 */
#[IsGranted('ROLE_ADMIN')]
final class WebAdminAdHocMailController extends AbstractController
{
    public function __construct(
        private readonly ParticipantService $participantService,
        private readonly ParticipantMailService $participantMailService,
    ) {
    }

    public function compose(Request $request, int $participantId): Response
    {
        $participant = $this->loadParticipant($participantId);
        $form = $this->createForm(AdHocMailType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $subject = is_array($data) ? (string) ($data['subject'] ?? '') : '';
            $rawBody = is_array($data) ? (string) ($data['body'] ?? '') : '';

            $sanitizer = new HtmlSanitizer(
                (new HtmlSanitizerConfig())
                    ->allowSafeElements()
                    ->allowLinkSchemes(['http', 'https', 'mailto', 'tel'])
                    ->allowRelativeLinks(false)
                    ->allowRelativeMedias(false),
            );
            $cleanBody = $sanitizer->sanitize($rawBody);

            $adminUser = $this->getUser();
            $adminName = $adminUser instanceof UserInterface ? $adminUser->getUserIdentifier() : null;

            try {
                $this->participantMailService->sendAdHoc($participant, $subject, $cleanBody, $adminName);
                $this->addFlash('success', sprintf(
                    'Ad-hoc e-mail účastníkovi #%d odeslán.',
                    $participantId,
                ));

                return new RedirectResponse($this->generateUrl(
                    'oswis_org_oswis_calendar_web_admin_participant_communication',
                    ['participantId' => $participantId],
                ));
            } catch (OswisException $e) {
                $this->addFlash('error', 'E-mail nelze odeslat: '.$e->getMessage());
            }
        }

        return $this->render('@OswisOrgOswisCalendar/web_admin/communication/ad_hoc_compose.html.twig', [
            'participant' => $participant,
            'form'        => $form,
            'page_title'  => sprintf('Nová zpráva účastníkovi #%d :: ADMIN', $participantId),
            'pageTitle'   => sprintf('Nová zpráva účastníkovi #%d', $participantId),
        ]);
    }

    private function loadParticipant(int $participantId): Participant
    {
        return $this->participantService->getParticipant(
            [
                ParticipantRepository::CRITERIA_ID              => $participantId,
                ParticipantRepository::CRITERIA_INCLUDE_DELETED => true,
            ],
            true,
        ) ?? throw $this->createNotFoundException('Účastník nenalezen.');
    }
}
