<?php

namespace OswisOrg\OswisCalendarBundle\Controller\WebAdmin;

use OswisOrg\OswisCalendarBundle\Entity\ParticipantMail\ParticipantMail;
use OswisOrg\OswisCalendarBundle\Repository\Participant\ParticipantRepository;
use OswisOrg\OswisCalendarBundle\Service\Communication\CommunicationTimelineService;
use OswisOrg\OswisCalendarBundle\Service\Participant\ParticipantMailService;
use OswisOrg\OswisCalendarBundle\Service\Participant\ParticipantService;
use OswisOrg\OswisCoreBundle\Exceptions\OswisException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class WebAdminParticipantsController extends AbstractController
{
    public function __construct(
        private readonly ParticipantService $participantService,
        private readonly CommunicationTimelineService $timelineService,
        private readonly ParticipantMailService $participantMailService,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Re-send a system mail (summary, payment, activation) — fresh delivery,
     * fresh ParticipantMail row, recipient = original recipient. Useful when
     * the participant says they didn't get the mail or deleted it by mistake.
     */
    #[IsGranted('ROLE_ADMIN')]
    public function resendMail(Request $request, int $participantId, int $mailId): Response
    {
        if (!$this->isCsrfTokenValid('participant_resend_mail_'.$mailId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Neplatný CSRF token.');
        }
        $mail = $this->em->find(ParticipantMail::class, $mailId)
            ?? throw $this->createNotFoundException('E-mail nenalezen.');
        $belongsToParticipant = $mail->getParticipant()?->getId() === $participantId;
        if (!$belongsToParticipant) {
            throw $this->createAccessDeniedException('E-mail nepatří tomuto účastníkovi.');
        }
        try {
            $this->participantMailService->resend($mail);
            $this->addFlash('success', sprintf(
                'E-mail "%s" znovu odeslán účastníkovi #%d.',
                $mail->getSubject() ?? $mail->getType() ?? 'systémový',
                $participantId,
            ));
        } catch (\Throwable $e) {
            $this->addFlash('error', sprintf('Re-send selhal: %s', $e->getMessage()));
        }

        return new RedirectResponse($this->generateUrl(
            'oswis_org_oswis_calendar_web_admin_participant_detail',
            ['participantId' => $participantId, '_fragment' => 'komunikace'],
        ));
    }

    /**
     * Full admin detail page for one participant: contact, registration,
     * payments, flags, notes and the embedded communication timeline.
     *
     * The legacy `arrival()` route still renders the same template without
     * timeline entries (kept for the lightweight check-in screen).
     */
    #[IsGranted('ROLE_MANAGER')]
    public function detail(int $participantId): Response
    {
        $participant = $this->participantService->getParticipant(
            [
                ParticipantRepository::CRITERIA_ID              => $participantId,
                ParticipantRepository::CRITERIA_INCLUDE_DELETED => true,
            ],
            true,
        ) ?? throw $this->createNotFoundException('Účastník nenalezen.');

        $entries = [];
        try {
            $entries = $this->timelineService->forParticipant($participant, includeInternal: true);
        } catch (\Throwable) {
            // Timeline failures must not prevent the detail page from rendering.
        }

        // Only ParticipantMail (system + ad-hoc) rows are resend-able.
        // IMAP-imported and manual-note rows have no underlying mailer.
        $resendableMailIds = [];
        foreach ($entries as $entry) {
            if ($entry instanceof ParticipantMail && $entry->getId() !== null) {
                $resendableMailIds[$entry->getId()] = true;
            }
        }

        return $this->render('@OswisOrgOswisCalendar/web_admin/participant.html.twig', [
            'participant'        => $participant,
            'entries'            => $entries,
            'isAdmin'            => true,
            'showFullDetail'     => true,
            'participantId'      => $participantId,
            'resendableMailIds'  => $resendableMailIds,
            'page_title'         => sprintf('Přihláška #%d', $participantId),
            'pageTitle'          => sprintf('Přihláška #%d', $participantId),
        ]);
    }

    /**
     * @throws OswisException
     */
    #[IsGranted('ROLE_ADMIN')]
    public function sendAutoMails(int $limit = 100, ?string $type = null): Response
    {
        $this->participantService->sendAutoMails(null, $type, $limit);

        // Admin message skeleton (keeps the admin menu) — not the public message page.
        return $this->render('@OswisOrgOswisCore/web_admin/message.html.twig', [
            'title'     => 'Akce provedena.',
            'pageTitle' => 'Akce provedena.',
            'message'   => 'E-maily rozeslány.',
            'backUrl'   => $this->generateUrl('oswis_org_oswis_core_web_admin_homepage'),
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
     * Resend the activation e-mail to the participant (admin-initiated).
     * Creates a fresh token via ParticipantService::requestActivation() and
     * redirects the admin back to the participant detail with a flash message.
     */
    #[IsGranted('ROLE_ADMIN')]
    public function resendActivation(Request $request, int $participantId): Response
    {
        if (!$this->isCsrfTokenValid('participant_resend_activation_'.$participantId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Neplatný CSRF token.');
        }
        $participant = $this->participantService->getParticipant(
            [
                ParticipantRepository::CRITERIA_ID              => $participantId,
                ParticipantRepository::CRITERIA_INCLUDE_DELETED => true,
            ],
            true,
        ) ?? throw $this->createNotFoundException('Účastník nenalezen.');

        try {
            $this->participantService->requestActivation($participant);
            $this->addFlash('success', sprintf(
                'Aktivační e-mail účastníkovi #%d znovu odeslán.',
                $participantId,
            ));
        } catch (OswisException $e) {
            $this->addFlash('error', sprintf(
                'Aktivační e-mail nešel odeslat: %s',
                $e->getMessage(),
            ));
        }

        return new RedirectResponse($this->generateUrl(
            'oswis_org_oswis_calendar_web_admin_participant_arrival',
            ['participantId' => $participantId, 'arrival' => '0'],
        ));
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

        // Hard-coded redirect to participant detail — never accept a redirect
        // URL from the request body (avoids open-redirect on admin actions).
        return new RedirectResponse($this->generateUrl(
            'oswis_org_oswis_calendar_web_admin_participant_arrival',
            ['participantId' => $participantId, 'arrival' => '0'],
        ));
    }

    /**
     * Soft-delete a participant (reversible via the restore action). Used by the quick
     * action on the unified participant list. Redirects back to the referring list view
     * when safe (same-host admin URL only), otherwise to the participant detail.
     */
    #[IsGranted('ROLE_ADMIN')]
    public function delete(Request $request, int $participantId): Response
    {
        if (!$this->isCsrfTokenValid('participant_delete_'.$participantId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Neplatný CSRF token.');
        }
        $participant = $this->participantService->getParticipant(
            [
                ParticipantRepository::CRITERIA_ID              => $participantId,
                ParticipantRepository::CRITERIA_INCLUDE_DELETED => true,
            ],
            true,
        ) ?? throw $this->createNotFoundException('Účastník nenalezen.');

        $this->participantService->delete($participant);
        $this->addFlash('success', sprintf('Účastník #%d smazán (lze obnovit).', $participantId));

        return new RedirectResponse($this->safeListRedirect($request, $participantId));
    }

    /**
     * Resolve a safe post-action redirect target: the `return` form field if it is a
     * same-host admin (/web_admin/...) path, otherwise the participant detail page.
     * Never trusts an absolute/off-site URL (open-redirect guard).
     */
    private function safeListRedirect(Request $request, int $participantId): string
    {
        $return = (string) $request->request->get('return', '');
        if (str_starts_with($return, '/web_admin/') && !str_contains($return, "\n") && !str_contains($return, "\r")) {
            return $return;
        }

        return $this->generateUrl(
            'oswis_org_oswis_calendar_web_admin_participant_arrival',
            ['participantId' => $participantId, 'arrival' => '0'],
        );
    }
}
