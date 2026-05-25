<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Controller\WebAdmin;

use Doctrine\ORM\EntityManagerInterface;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantNote;
use OswisOrg\OswisCalendarBundle\Repository\Participant\ParticipantRepository;
use OswisOrg\OswisCalendarBundle\Service\Participant\ParticipantService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Web admin CRUD for ParticipantNote (admin-side notes on a participant —
 * NOT to be confused with ParticipantManualNote which logs a phone/chat
 * record in the communication timeline). Renders inline forms straight on
 * the participant detail page so the admin doesn't bounce between pages.
 */
#[IsGranted('ROLE_MANAGER')]
final class WebAdminParticipantNoteController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ParticipantService $participantService,
    ) {
    }

    public function create(Request $request, int $participantId): Response
    {
        if (!$this->isCsrfTokenValid('participant_note_create_'.$participantId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Neplatný CSRF token.');
        }
        $participant = $this->loadParticipant($participantId);

        $text = trim((string) $request->request->get('textValue'));
        if ('' === $text) {
            $this->addFlash('error', 'Text poznámky nesmí být prázdný.');

            return $this->redirectToDetail($participantId);
        }
        $isPublic = (bool) $request->request->get('publicNote', false);

        $note = new ParticipantNote($participant, $text, $isPublic);
        $this->em->persist($note);
        $this->em->flush();

        $this->addFlash('success', 'Poznámka přidána.');

        return $this->redirectToDetail($participantId);
    }

    public function edit(Request $request, int $participantId, int $noteId): Response
    {
        if (!$this->isCsrfTokenValid('participant_note_edit_'.$noteId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Neplatný CSRF token.');
        }
        $note = $this->em->find(ParticipantNote::class, $noteId)
            ?? throw $this->createNotFoundException('Poznámka nenalezena.');
        if ($note->getParticipant()?->getId() !== $participantId) {
            throw $this->createAccessDeniedException('Poznámka nepatří účastníkovi.');
        }

        $text = trim((string) $request->request->get('textValue'));
        if ('' === $text) {
            $this->addFlash('error', 'Text poznámky nesmí být prázdný.');

            return $this->redirectToDetail($participantId);
        }
        $note->setTextValue($text);
        $note->setPublicNote((bool) $request->request->get('publicNote', false));
        $this->em->flush();

        $this->addFlash('success', 'Poznámka uložena.');

        return $this->redirectToDetail($participantId);
    }

    public function delete(Request $request, int $participantId, int $noteId): Response
    {
        if (!$this->isCsrfTokenValid('participant_note_delete_'.$noteId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Neplatný CSRF token.');
        }
        $note = $this->em->find(ParticipantNote::class, $noteId)
            ?? throw $this->createNotFoundException('Poznámka nenalezena.');
        if ($note->getParticipant()?->getId() !== $participantId) {
            throw $this->createAccessDeniedException('Poznámka nepatří účastníkovi.');
        }
        $this->em->remove($note);
        $this->em->flush();

        $this->addFlash('success', 'Poznámka smazána.');

        return $this->redirectToDetail($participantId);
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

    private function redirectToDetail(int $participantId): RedirectResponse
    {
        return new RedirectResponse($this->generateUrl(
            'oswis_org_oswis_calendar_web_admin_participant_detail',
            ['participantId' => $participantId, '_fragment' => 'poznamky'],
        ));
    }
}
