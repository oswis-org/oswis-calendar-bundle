<?php /** @noinspection PhpUnused */

namespace Zakjakub\OswisCalendarBundle\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Exception;
use LogicException;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Encoder\UserPasswordEncoderInterface;
use Zakjakub\OswisAddressBookBundle\Entity\Person;
use Zakjakub\OswisCalendarBundle\Entity\EventParticipant\EventParticipant;
use Zakjakub\OswisCalendarBundle\Service\EventParticipantService;
use function assert;

class EventParticipantController extends AbstractController
{
    public EntityManagerInterface $em;

    public LoggerInterface $logger;

    public UserPasswordEncoderInterface $encoder;

    public EventParticipantService $participantService;

    public function __construct(
        EntityManagerInterface $em,
        LoggerInterface $logger,
        UserPasswordEncoderInterface $encoder,
        EventParticipantService $participantService
    ) {
        $this->em = $em;
        $this->logger = $logger;
        $this->encoder = $encoder;
        $this->participantService = $participantService;
    }

    /**
     * Process registration and appUser account activation.
     *
     * @param string $token
     * @param int    $eventParticipantId
     *
     * @return Response
     * @throws LogicException
     */
    final public function eventParticipantRegistrationConfirmAction(string $token, int $eventParticipantId): Response
    {
        try {
            if (!$token || !$eventParticipantId) {
                return $this->render(
                    '@ZakjakubOswisCalendar/web/pages/event-participant-registration-confirmation.html.twig',
                    array(
                        'type'    => 'error',
                        'title'   => 'Chyba! Nesprávný formát adresy!',
                        'message' => 'Formát adresy pro ověření je chybný.
                            Zkuste odkaz otevřít znovu nebo jej zkopírovat celý do adresního řádku prohlížeče.
                            Pokud se to nepodaří, kontaktujte nás a společně to vyřešíme.',
                    )
                );
            }
            $eventParticipant = $this->getDoctrine()->getRepository(EventParticipant::class)->findOneBy(['id' => $eventParticipantId]);
            assert($eventParticipant instanceof EventParticipant);
            $person = $eventParticipant->getContact();
            assert($person instanceof Person);
            if (!$eventParticipant || !$person) {
                $error = !$eventParticipant ? ', přihláška nenalezena' : '';
                $error .= !$person ? ', účastník nenalezen' : '';

                return $this->render(
                    '@ZakjakubOswisCalendar/web/pages/event-participant-registration-confirmation.html.twig',
                    array(
                        'type'    => 'error',
                        'title'   => 'Chyba!',
                        'message' => "Aktivace se nezdařila. 
                        Kontaktujte nás, prosím. (token $token, přihláška $eventParticipantId$error)",
                    )
                );
            }
            $eventParticipant->removeEmptyEventParticipantNotes();
            if ($eventParticipant->getContact()) {
                $eventParticipant->getContact()->removeEmptyContactDetails();
                $eventParticipant->getContact()->removeEmptyNotes();
            }
            $this->participantService->sendMail($eventParticipant, $this->encoder, true, $token);

            return $this->render(
                '@ZakjakubOswisCalendar/web/pages/event-participant-registration-confirmation.html.twig',
                array(
                    'type'    => 'success',
                    'title'   => 'Hotovo!',
                    'message' => 'Registrace i přihláška byly úspěšně potvrzeny.',
                )
            );
        } catch (Exception $e) {
            try {
                $this->logger->notice('OSWIS_CONFIRM_ERROR: '.$e->getMessage());
            } catch (Exception $exception) {
                $this->logger->notice('OSWIS_CONFIRM_ERROR: PROBLEM WITH ERROR MESSAGE!!!');
            }

            return $this->render(
                '@ZakjakubOswisCalendar/web/pages/event-participant-registration-confirmation.html.twig',
                array(
                    'type'    => 'error',
                    'title'   => 'Neočekávaná chyba!',
                    'message' => 'Registraci a přihlášku se nepodařilo potvrdit. Kontaktujte nás a společně to vyřešíme.',
                )
            );
        }
    }
}
