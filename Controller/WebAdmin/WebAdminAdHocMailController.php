<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Controller\WebAdmin;

use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Form\WebAdmin\AdHocMailType;
use OswisOrg\OswisCalendarBundle\Repository\Participant\ParticipantRepository;
use OswisOrg\OswisCalendarBundle\Service\Participant\ParticipantManualMail;
use OswisOrg\OswisCalendarBundle\Service\Participant\ParticipantManualMailer;
use OswisOrg\OswisCalendarBundle\Service\Participant\ParticipantService;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * „Nová zpráva" jednomu účastníkovi. Kontrola, náhled i odeslání jdou stejnou cestou jako hromadný
 * mail ({@see ParticipantManualMailer}); chyba = formulář se vrátí i s napsaným textem a výpisem chyb.
 */
#[IsGranted('ROLE_ADMIN')]
final class WebAdminAdHocMailController extends AbstractController
{
    use ManualMailRequestTrait;

    public function __construct(
        private readonly ParticipantService $participantService,
        private readonly ParticipantManualMailer $mailer,
        private readonly ClockInterface $clock,
    ) {
    }

    public function compose(Request $request, int $participantId): Response
    {
        $participant = $this->loadParticipant($participantId);
        $form = $this->createForm(AdHocMailType::class, null, ['preview' => [
            'url'          => $this->generateUrl('oswis_org_oswis_calendar_web_admin_message_preview'),
            'valuesUrl'    => $this->generateUrl('oswis_org_oswis_calendar_web_admin_message_values'),
            'recipients'   => [['id' => $participantId, 'label' => sprintf('Přihláška #%d', $participantId)]],
            'subjectField' => 'ad_hoc_mail_subject',
        ]]);
        $form->handleRequest($request);
        $validation = null;
        // Sem se po odeslání formuláře dostane jen neúspěch (chyby, nebo nic neodešlo) → 422 a text zůstává.

        if ($form->isSubmitted() && $form->isValid()) {
            $mail = new ParticipantManualMail(self::field($form, 'subject'), self::field($form, 'body'), adminName: $this->adminName());
            $validation = $this->mailer->validate($mail, [$participant]);
            // Bez chyb a s potvrzenými varováními (varování se ukáže PŘED odesláním, ne po něm).
            if ($validation->isConfirmedBy($request->request->getString('confirmWarnings'))) {
                $result = $this->mailer->send($mail, $participant, 'ad-hoc-'.$this->clock->now()->format('YmdHis'));
                if ($result['sent'] > 0) {
                    $this->addFlash('success', sprintf('Zpráva účastníkovi #%d odeslána (adres: %d).', $participantId, $result['sent']));
                    if ([] !== $result['errors']) {
                        $this->addFlash('warning', 'Na některé adresy se nedoručilo: '.implode(' | ', $result['errors']));
                    }

                    return new RedirectResponse($this->generateUrl(
                        'oswis_org_oswis_calendar_web_admin_participant_detail',
                        ['participantId' => $participantId, '_fragment' => 'komunikace'],
                    ));
                }
                $this->addFlash('danger', 'Zpráva nikam neodešla: '.implode(' | ', $result['errors']));
            }
        }

        return $this->render('@OswisOrgOswisCalendar/web_admin/communication/ad_hoc_compose.html.twig', [
            'participant'     => $participant,
            'form'            => $form,
            'validation'      => $validation,
            'page_title'      => sprintf('Nová zpráva účastníkovi #%d :: ADMIN', $participantId),
            'pageTitle'       => sprintf('Nová zpráva účastníkovi #%d', $participantId),
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    /** @param FormInterface<mixed> $form */
    private static function field(FormInterface $form, string $name): string
    {
        $value = $form->get($name)->getData();

        return is_string($value) ? $value : '';
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
