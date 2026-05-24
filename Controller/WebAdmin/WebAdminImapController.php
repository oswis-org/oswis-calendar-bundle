<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Controller\WebAdmin;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use OswisOrg\OswisCalendarBundle\Entity\Imap\ParticipantIncomingMail;
use OswisOrg\OswisCalendarBundle\Entity\Imap\ParticipantUnmatchedMail;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Repository\Imap\ParticipantUnmatchedMailRepository;
use OswisOrg\OswisCalendarBundle\Service\Imap\ImapFetchService;
use OswisOrg\OswisCoreBundle\Entity\AbstractClass\AbstractMail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Phase D admin UI:
 *  - GET  /web_admin/komunikace/nezarazene   — list unmatched mails.
 *  - POST /web_admin/komunikace/nezarazene/{id}/priradit/{participantId} — assign one.
 *  - POST /web_admin/komunikace/nezarazene/{id}/smazat — drop one (spam).
 *  - POST /web_admin/komunikace/imap-refresh — sync IMAP now (returns to referer).
 */
#[IsGranted('ROLE_ADMIN')]
final class WebAdminImapController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ImapFetchService $imapFetchService,
        private readonly ParticipantUnmatchedMailRepository $unmatchedRepository,
    ) {
    }

    public function listUnmatched(): Response
    {
        $unmatched = $this->unmatchedRepository->findBy([], ['occurredAt' => 'DESC'], 200);

        return $this->render('@OswisOrgOswisCalendar/web_admin/communication/unmatched.html.twig', [
            'unmatched' => $unmatched,
            'pageTitle' => 'Nezařazené e-maily (IMAP)',
            'page_title' => 'Nezařazené e-maily (IMAP) :: ADMIN',
        ]);
    }

    public function assignUnmatched(Request $request, int $id, int $participantId): Response
    {
        if (!$this->isCsrfTokenValid('unmatched_assign_'.$id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Neplatný CSRF token.');
        }
        $unmatched = $this->em->find(ParticipantUnmatchedMail::class, $id)
            ?? throw $this->createNotFoundException('E-mail nenalezen.');
        $participant = $this->em->find(Participant::class, $participantId)
            ?? throw $this->createNotFoundException('Účastník nenalezen.');

        $incoming = new ParticipantIncomingMail(
            participant: $participant,
            messageId:   $unmatched->getMessageId(),
            direction:   $unmatched->getDirection(),
            occurredAt:  $unmatched->getOccurredAt(),
        );
        $incoming->setSubject($unmatched->getSubject());
        $incoming->setBody($unmatched->getBody());
        $incoming->setBodyHtml($unmatched->getBodyHtml());
        $incoming->setFromAddress($unmatched->getFromAddress());
        $incoming->setFromName($unmatched->getFromName());
        $incoming->setInReplyTo($unmatched->getInReplyTo());
        $incoming->setThreadKey(AbstractMail::computeThreadKey(
            $unmatched->getSubject() ?? '',
            $unmatched->getFromAddress(),
        ));
        $incoming->setImapFolder($unmatched->getImapFolder());
        $incoming->setImapUid($unmatched->getImapUid());

        $this->em->persist($incoming);
        $this->em->remove($unmatched);
        try {
            $this->em->flush();
            $this->addFlash('success', sprintf(
                'E-mail #%d přiřazen účastníkovi #%d.',
                $id, $participantId,
            ));
        } catch (UniqueConstraintViolationException) {
            // Same Message-ID already exists in calendar_participant_incoming_mail
            // (typical cause: IMAP sync re-import raced with this assign action,
            // or the admin clicked "Přiřadit" twice). Just drop the unmatched row
            // — the mail is already linked to a participant somewhere.
            $this->em->clear();
            $unmatchedFresh = $this->em->find(ParticipantUnmatchedMail::class, $id);
            if ($unmatchedFresh instanceof ParticipantUnmatchedMail) {
                $this->em->remove($unmatchedFresh);
                $this->em->flush();
            }
            $this->addFlash('warning', sprintf(
                'E-mail #%d byl už dříve přiřazen (Message-ID duplikát). Nezařazený záznam smazán.',
                $id,
            ));
        }

        return new RedirectResponse($this->generateUrl('oswis_org_oswis_calendar_web_admin_imap_unmatched'));
    }

    public function deleteUnmatched(Request $request, int $id): Response
    {
        if (!$this->isCsrfTokenValid('unmatched_delete_'.$id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Neplatný CSRF token.');
        }
        $unmatched = $this->em->find(ParticipantUnmatchedMail::class, $id)
            ?? throw $this->createNotFoundException('E-mail nenalezen.');
        $this->em->remove($unmatched);
        $this->em->flush();

        $this->addFlash('warning', sprintf('Nezařazený e-mail #%d smazán (spam-handling).', $id));

        return new RedirectResponse($this->generateUrl('oswis_org_oswis_calendar_web_admin_imap_unmatched'));
    }

    public function refresh(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('imap_refresh', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Neplatný CSRF token.');
        }
        $report = $this->imapFetchService->fetchAll(100);

        if (!$report['enabled']) {
            $this->addFlash('warning', 'IMAP fetch vypnutý (OSWIS_IMAP_ENABLED=0).');
        } else {
            $totalMatched = 0;
            $totalUnmatched = 0;
            $errors = [];
            foreach ($report['folders'] as $folder => $stats) {
                $totalMatched += (int) ($stats['matched'] ?? 0);
                $totalUnmatched += (int) ($stats['unmatched'] ?? 0);
                if (!empty($stats['error'])) {
                    $errors[] = "$folder: ".$stats['error'];
                }
            }
            $this->addFlash('success', sprintf(
                'IMAP sync: %d nových matched, %d unmatched%s.',
                $totalMatched,
                $totalUnmatched,
                $errors === [] ? '' : ' — chyby: '.implode(' | ', $errors),
            ));
        }

        $referer = $request->headers->get('referer');
        if (is_string($referer) && '' !== $referer) {
            return new RedirectResponse($referer);
        }

        return new RedirectResponse($this->generateUrl('oswis_org_oswis_calendar_web_admin_imap_unmatched'));
    }
}
