<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Controller\WebAdmin;

use Doctrine\ORM\EntityManagerInterface;
use Exception;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantPayment;
use OswisOrg\OswisCalendarBundle\Form\WebAdmin\ParticipantPaymentEditType;
use OswisOrg\OswisCalendarBundle\Repository\Participant\ParticipantPaymentRepository;
use OswisOrg\OswisCalendarBundle\Service\Participant\ParticipantMailService;
use OswisOrg\OswisCalendarBundle\Service\Participant\PaymentMatchingService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class WebAdminPaymentController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ParticipantPaymentRepository $paymentRepo,
        private readonly PaymentMatchingService $matchingService,
        private readonly ParticipantMailService $participantMailService,
    ) {
    }

    public function list(string $filter = ParticipantPaymentRepository::FILTER_ALL): Response
    {
        $payments = $this->paymentRepo->findFiltered($filter, 500);

        return $this->render('@OswisOrgOswisCalendar/web_admin/payments/list.html.twig', [
            'title'      => 'Přehled plateb :: ADMIN',
            'payments'   => $payments,
            'filter'     => $filter,
            'filterTabs' => $this->buildTabs($filter),
        ]);
    }

    public function edit(int $id, Request $request): Response
    {
        $payment = $this->em->find(ParticipantPayment::class, $id) ?? throw $this->createNotFoundException();

        $form = $this->createForm(ParticipantPaymentEditType::class, $payment);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->flush();
            $this->addFlash('success', 'Platba uložena.');

            return $this->redirectToRoute('oswis_org_oswis_calendar_web_admin_payment_edit', ['id' => $id]);
        }

        return $this->render('@OswisOrgOswisCalendar/web_admin/payments/edit.html.twig', [
            'title'   => "Úprava platby #$id :: ADMIN",
            'payment' => $payment,
            'form'    => $form,
        ]);
    }

    public function match(int $id): Response
    {
        $payment = $this->em->find(ParticipantPayment::class, $id) ?? throw $this->createNotFoundException();
        $candidates = $this->matchingService->suggest($payment, 10);

        return $this->render('@OswisOrgOswisCalendar/web_admin/payments/match.html.twig', [
            'title'      => "Spárování platby #$id :: ADMIN",
            'payment'    => $payment,
            'candidates' => $candidates,
        ]);
    }

    public function assign(int $id, int $participantId): RedirectResponse
    {
        $payment = $this->em->find(ParticipantPayment::class, $id) ?? throw $this->createNotFoundException();
        $participant = $this->em->find(Participant::class, $participantId) ?? throw $this->createNotFoundException();

        $payment->setParticipant($participant);
        $this->em->flush();
        $this->addFlash('success', sprintf(
            'Platba #%d přiřazena účastníkovi #%d (%s).',
            $id,
            $participantId,
            $participant->getName() ?? '(bez jména)',
        ));
        // Ruční spárování musí potvrdit platbu e-mailem stejně jako automatické při importu
        // (sendPaymentConfirmation razítkuje confirmedByMailAt, takže se nikdy nepošle 2×).
        $this->sendConfirmationGuarded($payment);

        return $this->redirectToRoute('oswis_org_oswis_calendar_web_admin_payment_edit', ['id' => $id]);
    }

    public function unmatch(int $id): RedirectResponse
    {
        $payment = $this->em->find(ParticipantPayment::class, $id) ?? throw $this->createNotFoundException();
        // setParticipant(null) na persistované platbě záměrně hází NotImplementedException
        // (ochrana API zápisů) — admin odpojení jde přes sankcionovanou detachParticipant().
        $payment->detachParticipant();
        // Razítko potvrzení patří ke spárování s konkrétním účastníkem — po odpojení se musí
        // resetovat, jinak by správný účastník po přepárování (A→B) potvrzení nikdy nedostal.
        $payment->setConfirmedByMailAt(null);
        $this->em->flush();
        $this->addFlash('success', "Účastník odpojen od platby #$id.");

        return $this->redirectToRoute('oswis_org_oswis_calendar_web_admin_payment_match', ['id' => $id]);
    }

    /** Ruční (do)odeslání potvrzení platby — pro platby přiřazené dříve, než assign() posílal mail. */
    public function sendConfirmation(int $id, Request $request): RedirectResponse
    {
        $payment = $this->em->find(ParticipantPayment::class, $id) ?? throw $this->createNotFoundException();
        if (!$this->isCsrfTokenValid('payment_send_confirmation_'.$id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Neplatný CSRF token.');
        }
        if (null === $payment->getParticipant()) {
            $this->addFlash('warning', "Platba #$id nemá přiřazeného účastníka — potvrzení nelze odeslat.");
        } elseif ($payment->isConfirmedByMail()) {
            $this->addFlash('warning', "Potvrzení platby #$id už bylo odesláno ".$payment->getConfirmedByMailAt()?->format('j. n. Y H:i').'.');
        } else {
            $this->sendConfirmationGuarded($payment);
        }

        return $this->redirectToRoute('oswis_org_oswis_calendar_web_admin_payment_edit', ['id' => $id]);
    }

    /** Pošle potvrzení platby (pokud ještě nebylo) a převede výsledek na flash zprávu. */
    private function sendConfirmationGuarded(ParticipantPayment $payment): void
    {
        if ($payment->isConfirmedByMail()) {
            return;
        }
        try {
            $this->participantMailService->sendPaymentConfirmation($payment);
            $this->addFlash('success', sprintf('Potvrzení platby #%d odesláno e-mailem.', (int) $payment->getId()));
        } catch (Exception $exception) {
            $this->addFlash('danger', sprintf(
                'Potvrzení platby #%d se NEPODAŘILO odeslat: %s',
                (int) $payment->getId(),
                $exception->getMessage(),
            ));
        }
    }

    /**
     * @return list<array{filter: string, url: string, label: string, active: bool}>
     */
    private function buildTabs(string $active): array
    {
        $entries = [
            ParticipantPaymentRepository::FILTER_ALL        => 'Všechny',
            ParticipantPaymentRepository::FILTER_ORPHANED   => 'Nepřiřazené',
            ParticipantPaymentRepository::FILTER_WITH_ERROR => 'S chybou',
            ParticipantPaymentRepository::FILTER_ASSIGNED   => 'Přiřazené',
        ];
        $tabs = [];
        foreach ($entries as $filter => $label) {
            $tabs[] = [
                'filter' => $filter,
                'url'    => $this->generateUrl('oswis_org_oswis_calendar_web_admin_participant_payments_list', ['filter' => $filter]),
                'label'  => $label,
                'active' => $filter === $active,
            ];
        }

        return $tabs;
    }
}
