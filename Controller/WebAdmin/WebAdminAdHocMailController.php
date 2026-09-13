<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Controller\WebAdmin;

use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Form\WebAdmin\AdHocMailType;
use OswisOrg\OswisCalendarBundle\Repository\Participant\ParticipantRepository;
use OswisOrg\OswisCalendarBundle\Service\Participant\ParticipantManualMail;
use OswisOrg\OswisCalendarBundle\Service\Participant\ParticipantManualMailer;
use OswisOrg\OswisCalendarBundle\Service\Participant\ParticipantService;
use OswisOrg\OswisCoreBundle\Mail\Catalog\MailCatalog;
use OswisOrg\OswisCoreBundle\Mail\Validation\MailValidationResult;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * „Nová zpráva" jednomu účastníkovi. Kontrola, náhled i odeslání jdou stejnou cestou jako hromadný
 * mail ({@see ParticipantManualMailer}); chyba = formulář se vrátí i s napsaným textem a výpisem chyb.
 */
#[IsGranted('ROLE_ADMIN')]
final class WebAdminAdHocMailController extends AbstractController
{
    public function __construct(
        private readonly ParticipantService $participantService,
        private readonly ParticipantManualMailer $mailer,
        private readonly MailCatalog $mailCatalog,
        private readonly ClockInterface $clock,
    ) {
    }

    public function compose(Request $request, int $participantId): Response
    {
        $participant = $this->loadParticipant($participantId);
        $form = $this->createForm(AdHocMailType::class);
        $form->handleRequest($request);
        $validation = null;

        if ($form->isSubmitted() && $form->isValid()) {
            $mail = new ParticipantManualMail(self::field($form, 'subject'), self::field($form, 'body'), adminName: $this->adminName());
            $validation = $this->mailer->validate($mail, [$participant]);
            if (!$validation->hasErrors()) {
                $result = $this->mailer->send($mail, $participant, 'ad-hoc-'.$this->clock->now()->format('YmdHis'));
                if ($result['sent'] > 0) {
                    $this->addFlash('success', sprintf('Zpráva účastníkovi #%d odeslána (adres: %d).', $participantId, $result['sent']));
                    if ([] !== $result['errors']) {
                        $this->addFlash('warning', 'Na některé adresy se nedoručilo: '.implode(' | ', $result['errors']));
                    }
                    foreach ($validation->warnings() as $problem) {
                        $this->addFlash('warning', $problem->message);
                    }

                    return new RedirectResponse($this->generateUrl(
                        'oswis_org_oswis_calendar_web_admin_participant_communication',
                        ['participantId' => $participantId],
                    ));
                }
                $this->addFlash('danger', 'Zpráva nikam neodešla: '.implode(' | ', $result['errors']));
            }
        }

        return $this->render('@OswisOrgOswisCalendar/web_admin/communication/ad_hoc_compose.html.twig', [
            'participant'     => $participant,
            'form'            => $form,
            'validation'      => $validation,
            'variableCatalog' => $this->mailCatalog->groupedForPanel(),
            'page_title'      => sprintf('Nová zpráva účastníkovi #%d :: ADMIN', $participantId),
            'pageTitle'       => sprintf('Nová zpráva účastníkovi #%d', $participantId),
        ], new Response(status: self::status($validation)));
    }

    /** Neodeslaná zpráva (chyby) = 422, jako neplatný formulář — text zůstává ve formuláři. */
    private static function status(?MailValidationResult $validation): int
    {
        return null !== $validation && $validation->hasErrors() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK;
    }

    /** @param FormInterface<mixed> $form */
    private static function field(FormInterface $form, string $name): string
    {
        $value = $form->get($name)->getData();

        return is_string($value) ? $value : '';
    }

    private function adminName(): ?string
    {
        $user = $this->getUser();

        return $user instanceof UserInterface ? $user->getUserIdentifier() : null;
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
