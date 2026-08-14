<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Controller\WebAdmin;

use Doctrine\ORM\EntityManagerInterface;
use OswisOrg\OswisCalendarBundle\Entity\Registration\RegistrationOffer;
use OswisOrg\OswisCalendarBundle\Repository\Participant\ParticipantRepository;
use OswisOrg\OswisCalendarBundle\Service\Participant\ParticipantService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Přesun JEDNOHO účastníka na jinou nabídku (turnus) přímo z jeho detailu.
 *
 * PROČ TO VZNIKLO: web-admin uměl jen **hromadný** přesun
 * ({@see WebAdminBulkReassignController}), takže přesunout jednoho člověka znamenalo odejít
 * z jeho detailu do průvodce, vybrat zdrojovou akci a kategorii, najít ho v seznamu a zaškrtnout.
 * Aplikace to přitom na detailu uměla (`POST /api/participants/{id}/offer`) — mezera šla tedy
 * opačným směrem, než se u web-adminu čeká (inventura parity 2026-08-13).
 *
 * Doménová logika je ZÁMĚRNĚ tatáž jako u hromadného přesunu i u API endpointu — `setOffer()`
 * (kapacita + migrace příznaků) a po flushi `applyPostMoveSideEffects()` (mail o změně přihlášky
 * + přepočet obsazenosti zdrojové i cílové nabídky). Žádná druhá pravda o tom, co přesun znamená.
 *
 * `admin = true`: ROLE_ADMIN akce smí překročit základní kapacitu — stejně jako hromadný přesun
 * a jako editor příznaků. Bez toho by se kontrolovalo proti cachované `base_usage` a legitimní
 * přesun by šlo falešně zablokovat.
 */
#[IsGranted('ROLE_ADMIN')]
final class WebAdminParticipantMoveController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ParticipantService $participantService,
    ) {
    }

    public function move(Request $request, int $participantId): Response
    {
        if (!$this->isCsrfTokenValid('participant_move_'.$participantId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Neplatný CSRF token.');
        }

        $participant = $this->participantService->getParticipant(
            [
                ParticipantRepository::CRITERIA_ID              => $participantId,
                ParticipantRepository::CRITERIA_INCLUDE_DELETED => true,
            ],
            true,
        ) ?? throw $this->createNotFoundException('Účastník nenalezen.');

        $rawOfferId = $request->request->get('targetOfferId');
        $offerId = is_numeric($rawOfferId) ? (int) $rawOfferId : 0;
        $targetOffer = $offerId > 0 ? $this->em->find(RegistrationOffer::class, $offerId) : null;
        if (!$targetOffer instanceof RegistrationOffer) {
            $this->addFlash('error', 'Cílová nabídka nebyla nalezena.');

            return $this->zpetNaDetail($participantId);
        }

        $oldOffer = $participant->getOffer();
        // No-op přesun přeskočit: jinak by setOffer zbytečně přepsal registraci
        // a applyPostMoveSideEffects poslal účastníkovi mail o „změně", ke které nedošlo.
        if ($oldOffer === $targetOffer) {
            $this->addFlash('warning', 'Účastník už na téhle nabídce je — nic se nezměnilo.');

            return $this->zpetNaDetail($participantId);
        }

        try {
            $participant->setOffer($targetOffer, true);
            $this->em->persist($participant);
            $this->em->flush();
        } catch (\Throwable $e) {
            $this->addFlash('error', 'Přesun se nezdařil: '.$e->getMessage());

            return $this->zpetNaDetail($participantId);
        }

        // Až po flushi — diff změn čte verzované záznamy, které vzniknou teprve flushem.
        // Chyby si applyPostMoveSideEffects loguje sám, takže neodeslaný mail přesun neshodí.
        $this->participantService->applyPostMoveSideEffects($participant, $oldOffer);

        $this->addFlash('success', sprintf(
            'Účastník přesunut na „%s". Účastníkovi odešlo oznámení o změně přihlášky.',
            $targetOffer->getName() ?? '?',
        ));

        return $this->zpetNaDetail($participantId);
    }

    private function zpetNaDetail(int $participantId): RedirectResponse
    {
        return new RedirectResponse($this->generateUrl(
            'oswis_org_oswis_calendar_web_admin_participant_detail',
            ['participantId' => $participantId],
        ));
    }
}
