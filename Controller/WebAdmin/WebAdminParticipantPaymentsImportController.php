<?php

namespace OswisOrg\OswisCalendarBundle\Controller\WebAdmin;

use Exception;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantPaymentsImport;
use OswisOrg\OswisCalendarBundle\Form\Participant\ParticipantPaymentsImportType;
use OswisOrg\OswisCalendarBundle\Service\Participant\ParticipantPaymentsImportService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class WebAdminParticipantPaymentsImportController extends AbstractController
{
    public function __construct(
        private readonly ParticipantPaymentsImportService $paymentsImportService,
    ) {
    }

    public function import(Request $request): Response
    {
        $paymentsImport = new ParticipantPaymentsImport();
        // Create the form outside the try/catch so it is always defined by the time
        // we reach the error path; processImport() is the only thing that can throw.
        $form = $this->createForm(ParticipantPaymentsImportType::class, $paymentsImport);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()
            && (($paymentsImport = $form->getData()) instanceof ParticipantPaymentsImport)) {
            try {
                $this->paymentsImportService->processImport($paymentsImport);

                return $this->renderMessage(
                    "Platby importovány!",
                    "Import plateb proběhl úspěšně. Shrnutí bylo odesláno do archivu."
                );
            } catch (Exception $exception) {
                $form->addError(
                    new FormError(
                        'Nastala chyba. Zkuste to znovu nebo nás kontaktujte. '.$exception->getMessage()
                    )
                );
            }
        }

        return $this->renderImportForm($form);
    }

    private function renderMessage(string $title, string $message): Response
    {
        // Admin message skeleton (keeps the admin menu) — not the public message page.
        return $this->render('@OswisOrgOswisCore/web_admin/message.html.twig', [
            'title'     => $title,
            'pageTitle' => $title,
            'message'   => $message,
            'backUrl'   => $this->generateUrl('oswis_org_oswis_calendar_web_admin_participant_payments_list'),
        ]);
    }

    private function renderImportForm(FormInterface $form): Response
    {
        return $this->render("@OswisOrgOswisCalendar/web/pages/participant-payments-import-form.html.twig", [
            'form'      => $form->createView(),
            'title'     => "Import plateb účastníků",
            'pageTitle' => "Import plateb účastníků",
            'type'      => "form",
        ]);
    }
}
