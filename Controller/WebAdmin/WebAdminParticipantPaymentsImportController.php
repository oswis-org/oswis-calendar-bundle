<?php
/**
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Controller\WebAdmin;

use Exception;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantPaymentsImport;
use OswisOrg\OswisCalendarBundle\Service\ParticipantPaymentsImportService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class WebAdminParticipantPaymentsImportController extends AbstractController
{
    public ParticipantPaymentsImportService $paymentsImportService;

    public function __construct(ParticipantPaymentsImportService $paymentsImportService)
    {
        $this->paymentsImportService = $paymentsImportService;
    }

    public function getPaymentsImportService(): ParticipantPaymentsImportService
    {
        return $this->paymentsImportService;
    }

    public function import(Request $request): Response
    {
        $paymentsImport = new ParticipantPaymentsImport();
        try {
            $form = $this->createForm(ParticipantPaymentsImportService::class, $paymentsImport);
            $form->handleRequest($request);
            if ($form->isSubmitted() && $form->isValid() && (($paymentsImport = $form->getData()) instanceof ParticipantPaymentsImport)) {
                $this->paymentsImportService->processImport($paymentsImport);

                return $this->renderMessage("Platby importovány!", "Import plateb proběhl úspěšně. Shrnutí bylo odesláno do archivu.");
            }

            return $this->renderForm($form);
        } catch (Exception $exception) {
            $paymentsImport ??= new ParticipantPaymentsImport();
            if (!isset($form)) {
                $form = $this->createForm(ParticipantPaymentsImportService::class, $paymentsImport);
                $form->handleRequest($request);
            }
            $form->addError(new FormError('Nastala chyba. Zkuste to znovu nebo nás kontaktujte. '.$exception->getMessage().''));

            return $this->renderForm($form);
        }
    }

    public function renderMessage(string $title, string $message): Response
    {
        return $this->render(
            '@OswisOrgOswisCore/web/pages/message.html.twig',
            [
                'title'     => $title,
                'pageTitle' => $title,
                'message'   => $message,
            ]
        );
    }

    public function renderForm(FormInterface $form): Response
    {
        return $this->render(
            "@OswisOrgOswisCalendar/web/pages/participant-payments-import-form.html.twig",
            [
                'form'      => $form->createView(),
                'title'     => "Import plateb účastníků",
                'pageTitle' => "Import plateb účastníků",
                'type'      => "form",
            ]
        );
    }
}
