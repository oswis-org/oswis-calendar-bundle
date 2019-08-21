<?php

namespace Zakjakub\OswisCalendarBundle\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Exception;
use LogicException;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Security\Core\Encoder\UserPasswordEncoderInterface;
use Twig\Environment;
use Zakjakub\OswisAddressBookBundle\Entity\Person;
use Zakjakub\OswisCalendarBundle\Entity\EventParticipant\EventParticipant;
use Zakjakub\OswisCalendarBundle\Manager\EventParticipantManager;
use Zakjakub\OswisCoreBundle\Provider\OswisCoreSettingsProvider;
use function assert;

/**
 * Class EventParticipantController
 * @package Zakjakub\OswisCalendarBundle\Controller
 */
class EventParticipantController extends AbstractController
{

    /**
     * @var EntityManagerInterface
     */
    public $em;

    /**
     * @var LoggerInterface
     */
    public $logger;

    /**
     * @var UserPasswordEncoderInterface
     */
    public $encoder;

    /**
     * @var OswisCoreSettingsProvider
     */
    public $oswisCoreSettings;

    /**
     * @var MailerInterface
     */
    public $mailer;

    /**
     * @var Environment
     */
    public $templating;

    /**
     * EventParticipantController constructor.
     *
     * @param EntityManagerInterface       $em
     * @param LoggerInterface              $logger
     * @param UserPasswordEncoderInterface $encoder
     * @param OswisCoreSettingsProvider    $oswisCoreSettings
     * @param MailerInterface              $mailer
     * @param Environment                  $templating
     */
    public function __construct(
        EntityManagerInterface $em,
        LoggerInterface $logger,
        UserPasswordEncoderInterface $encoder,
        OswisCoreSettingsProvider $oswisCoreSettings,
        MailerInterface $mailer,
        Environment $templating
    ) {
        $this->em = $em;
        $this->logger = $logger;
        $this->encoder = $encoder;
        $this->oswisCoreSettings = $oswisCoreSettings;
        $this->mailer = $mailer;
        $this->templating = $templating;
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
    final public function eventParticipantRegistrationConfirmAction(
        string $token,
        int $eventParticipantId
    ): Response {
        try {
            $eventParticipantManager = new EventParticipantManager(
                $this->em, $this->mailer, $this->oswisCoreSettings, $this->logger, $this->templating
            );
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
            $eventParticipant->getContact()->removeEmptyContactDetails();
            $eventParticipant->getContact()->removeEmptyNotes();
            $eventParticipantManager->sendMail($eventParticipant, $this->encoder, true, $token);

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
