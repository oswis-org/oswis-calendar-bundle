<?php

namespace Zakjakub\OswisCalendarBundle\Manager;

use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\NamedAddress;
use Zakjakub\OswisAddressBookBundle\Entity\Person;
use Zakjakub\OswisCalendarBundle\Entity\EventParticipant\EventParticipantPayment;
use Zakjakub\OswisCoreBundle\Exceptions\OswisException;
use Zakjakub\OswisCoreBundle\Provider\OswisCoreSettingsProvider;
use Zakjakub\OswisCoreBundle\Utils\EmailUtils;
use function assert;

class EventParticipantPaymentManager
{

    /**
     * @var EntityManagerInterface
     */
    protected $em;

    /**
     * @var Mailer
     */
    protected $mailer;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @var OswisCoreSettingsProvider
     */
    protected $oswisCoreSettings;

    /**
     * EventParticipantPaymentManager constructor.
     *
     * @param EntityManagerInterface    $em
     * @param MailerInterface           $mailer
     * @param LoggerInterface           $logger
     * @param OswisCoreSettingsProvider $oswisCoreSettings
     */
    public function __construct(
        EntityManagerInterface $em,
        MailerInterface $mailer,
        LoggerInterface $logger,
        OswisCoreSettingsProvider $oswisCoreSettings
    ) {
        $this->em = $em;
        $this->mailer = $mailer;
        $this->logger = $logger;
        $this->oswisCoreSettings = $oswisCoreSettings;
    }

    /**
     * @param EventParticipantPayment $payment
     *
     * @return string
     * @throws OswisException
     */
    final public function sendConfirmation(
        EventParticipantPayment $payment = null
    ): string {
        try {

            if (!$payment) {
                throw new NotFoundHttpException('Platba nenalezena.');
            }

            assert($payment instanceof EventParticipantPayment);

            $em = $this->em;

            $eventParticipant = $payment->getEventParticipant();
            $contact = $eventParticipant ? $eventParticipant->getContact() : null;

            if ($payment->getNumericValue() < 0) {
                $title = 'Vrácení/oprava platby';
            } else {
                $title = 'Přijetí platby';
            }

            if ($contact instanceof Person) {
                $salutationName = $contact ? $contact->getSalutationName() : '';
                $a = $contact ? $contact->getCzechSuffixA() : '';
            } else {
                // TODO: Correct salutation (contact of organization).
                $salutationName = $contact ? $contact->getContactName() : '';
                $a = '';
            }

            if ($contact->getAppUser()) {
                $name = $contact->getAppUser()->getFullName();
                $eMail = $contact->getAppUser()->getEmail();
            } else {
                $name = $contact->getContactName();
                $eMail = $contact->getEmail();
            }

            $mailSettings = $this->oswisCoreSettings->getEmail();

            $mailData = array(
                'salutationName' => $salutationName,
                'a'              => $a,
                'payment'        => $payment,
            );

            $archive = new NamedAddress(
                $mailSettings['archive_address'] ?? '',
                EmailUtils::mime_header_encode($mailSettings['archive_name'] ?? '') ?? ''
            );

            $email = (new TemplatedEmail())
                ->to(new NamedAddress($eMail ?? '', EmailUtils::mime_header_encode($name ?? '') ?? ''))
                ->bcc($archive)
                ->subject(EmailUtils::mime_header_encode($title))
                ->htmlTemplate('@ZakjakubOswisCalendar/e-mail/event-participant-payment.html.twig')
                ->context($mailData);
            $this->mailer->send($email);
            $payment->setMailConfirmationSend('event-participant-payment-manager');
            $em->persist($payment);
            $em->flush();

            return true;
        } catch (Exception $e) {
            throw new OswisException('Problém s odesláním potvrzení o platbě (při vytváření zprávy).  '.$e->getMessage());
        } catch (TransportExceptionInterface $e) {
            throw new OswisException('Problém s odesláním potvrzení o platbě (při odeslání zprávy).  '.$e->getMessage());
        }
    }

}
