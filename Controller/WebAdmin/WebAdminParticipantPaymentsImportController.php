<?php
/**
 * @noinspection PhpUnused
 * @noinspection MethodShouldBeFinalInspection
 */

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

class WebAdminParticipantPaymentsImportController extends AbstractController
{
    public function __construct(
        public ParticipantPaymentsImportService $paymentsImportService,
    )
    {
    }

    public function getPaymentsImportService(): ParticipantPaymentsImportService
    {
        return $this->paymentsImportService;
    }

    public function import(Request $request): Response
    {
        $paymentsImport = new ParticipantPaymentsImport();
        try {
            $form = $this->createForm(ParticipantPaymentsImportType::class, $paymentsImport);
            $form->handleRequest($request);
            if ($form->isSubmitted() && $form->isValid()
                && (($paymentsImport = $form->getData()) instanceof ParticipantPaymentsImport)) {
                $this->paymentsImportService->processImport($paymentsImport);

                return $this->renderMessage(
                    "Platby importovány!",
                    "Import plateb proběhl úspěšně. Shrnutí bylo odesláno do archivu."
                );
            }

            return $this->renderImportForm($form);
        } catch (Exception $exception) {
            /** @phpstan-ignore-next-line */
            if (!isset($form)) {
                $form = $this->createForm(ParticipantPaymentsImportType::class, $paymentsImport);
                $form->handleRequest($request);
            }
            $form->addError(
                new FormError(
                    'Nastala chyba. Zkuste to znovu nebo nás kontaktujte. '.$exception->getMessage()
                )
            );

            return $this->renderImportForm($form);
        }
    }

    public function renderMessage(string $title, string $message): Response
    {
        return $this->render('@OswisOrgOswisCore/web/pages/message.html.twig', [
            'title' => $title,
            'pageTitle' => $title,
            'message' => $message,
        ]);
    }

    public function renderImportForm(FormInterface $form): Response
    {
        return $this->render("@OswisOrgOswisCalendar/web/pages/participant-payments-import-form.html.twig", [
            'form' => $form->createView(),
            'title' => "Import plateb účastníků",
            'pageTitle' => "Import plateb účastníků",
            'type' => "form",
        ]);
    }
}
