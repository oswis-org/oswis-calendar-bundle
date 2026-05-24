<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Controller\WebAdmin;

use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Entity\ParticipantNote\ParticipantManualNote;
use OswisOrg\OswisCalendarBundle\Form\WebAdmin\ManualNoteType;
use OswisOrg\OswisCalendarBundle\Repository\Participant\ParticipantRepository;
use OswisOrg\OswisCalendarBundle\Service\Participant\ParticipantService;
use OswisOrg\OswisCoreBundle\Entity\AppUser\AppUser;
use OswisOrg\OswisCoreBundle\Enum\Communication\CommunicationChannel;
use OswisOrg\OswisCoreBundle\Enum\Communication\CommunicationDirection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Phase B (Komponenta B) — admin form to log a manual communication entry
 * (phone / chat / etc.) onto a participant's timeline.
 */
#[IsGranted('ROLE_ADMIN')]
final class WebAdminManualNoteController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ParticipantService $participantService,
    ) {
    }

    public function create(Request $request, int $participantId): Response
    {
        $participant = $this->loadParticipant($participantId);

        $defaultOtherPartyName = $participant->getContact()?->getName();
        $defaultOtherPartyContact = $participant->getContact()?->getPhone() ?? $participant->getContact()?->getEmail();

        $form = $this->createForm(ManualNoteType::class, [
            'occurredAt'        => new DateTime(),
            'otherPartyName'    => $defaultOtherPartyName,
            'otherPartyContact' => $defaultOtherPartyContact,
            'channel'           => CommunicationChannel::PHONE,
            'direction'         => CommunicationDirection::IN,
            'internal'          => true,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            if (!is_array($data)) {
                $this->addFlash('error', 'Neúplná data formuláře.');
            } else {
                $author = $this->getUser() instanceof AppUser ? $this->getUser() : null;
                $note = new ParticipantManualNote(
                    participant:        $participant,
                    channel:            $data['channel'] ?? CommunicationChannel::PHONE,
                    direction:          $data['direction'] ?? CommunicationDirection::IN,
                    occurredAt:         $data['occurredAt'] instanceof DateTime ? $data['occurredAt'] : new DateTime(),
                    subject:            isset($data['subject']) ? (string) $data['subject'] : null,
                    body:               isset($data['body']) ? (string) $data['body'] : null,
                    internal:           (bool) ($data['internal'] ?? true),
                    authorAppUser:      $author,
                    otherPartyName:     isset($data['otherPartyName']) ? (string) $data['otherPartyName'] : null,
                    otherPartyContact:  isset($data['otherPartyContact']) ? (string) $data['otherPartyContact'] : null,
                    durationSec:        isset($data['durationSec']) ? (int) $data['durationSec'] : null,
                );
                $this->em->persist($note);
                $this->em->flush();

                $this->addFlash('success', 'Záznam komunikace přidán.');

                return new RedirectResponse($this->generateUrl(
                    'oswis_org_oswis_calendar_web_admin_participant_communication',
                    ['participantId' => $participantId],
                ));
            }
        }

        return $this->render('@OswisOrgOswisCalendar/web_admin/communication/manual_note_form.html.twig', [
            'participant' => $participant,
            'form'        => $form,
            'page_title'  => sprintf('Nový záznam komunikace pro účastníka #%d :: ADMIN', $participantId),
            'pageTitle'   => sprintf('Nový záznam komunikace pro účastníka #%d', $participantId),
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
