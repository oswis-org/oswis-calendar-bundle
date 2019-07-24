<?php

namespace Zakjakub\OswisCalendarBundle\Manager;

use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mime\NamedAddress;
use Twig\Environment;
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
     * @var Environment
     */
    protected $templating;

    /**
     * @var OswisCoreSettingsProvider
     */
    protected $oswisCoreSettings;

    /**
     * EventParticipantPaymentManager constructor.
     *
     * @param EntityManagerInterface    $em
     * @param Mailer                    $mailer
     * @param LoggerInterface           $logger
     * @param Environment               $templating
     * @param OswisCoreSettingsProvider $oswisCoreSettings
     */
    public function __construct(
        EntityManagerInterface $em,
        Mailer $mailer,
        LoggerInterface $logger,
        Environment $templating,
        OswisCoreSettingsProvider $oswisCoreSettings
    ) {
        $this->em = $em;
        $this->mailer = $mailer;
        $this->logger = $logger;
        $this->templating = $templating;
        $this->oswisCoreSettings = $oswisCoreSettings;
    }

    /// TODO: Multiple receipts.

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

            $mailData = array(
                'salutationName' => $salutationName,
                'a'              => $a,
                'payment'        => $payment,
            );

            $mailSettings = $this->oswisCoreSettings->getEmail();

            $email = (new TemplatedEmail())
                ->to(new NamedAddress($eMail ?? '', EmailUtils::mime_header_encode($name ?? '') ?? ''))
                ->bcc(
                    new NamedAddress(
                        $mailSettings['archive_address'] ?? '',
                        EmailUtils::mime_header_encode($mailSettings['archive_name'] ?? '') ?? ''
                    )
                )
                ->subject(EmailUtils::mime_header_encode($title))
                ->htmlTemplate('@ZakjakubOswisCalendar/e-mail/event-participant-payment.html.twig')
                ->context($mailData);
            $this->mailer->send($email);

            $payment->setMailConfirmationSend('event-participant-payment-manager');
            $em->persist($payment);
            $em->flush();

            return true;
        } catch (Exception $e) {
            throw new OswisException('Problém s odesláním potvrzení o platbě.  '.$e->getMessage());
        } catch (TransportExceptionInterface $e) {
            throw new OswisException('Problém s odesláním potvrzení o platbě.  '.$e->getMessage());
        }
    }

    /*
    /**
     * @param ReservationPayment|null $payment
     *
     * @return string
     * @throws Exception
     */
    /*
    final public function createReceiptPdfString(
        ReservationPayment $payment = null
    ): string {
        try {

            if (!$payment) {
                throw new OswisException('Platba nenalezena.');
            }

            $em = $this->em;

            $title = 'Příjmový/výdajový pokladní doklad';
            $subTitle = '';
            $reservation = $payment->getReservation();
            if (!$reservation) {
                throw new Exception('Rezervace nenalezena.');
            }
            $facility = $reservation->getFacilities()->first();
            if (!$facility) {
                throw new Exception('Ubytovací objekt nenalezen.');
            }
            assert($facility instanceof Facility);
            $author = $payment->getAuthor();
            if (!$author) {
                throw new Exception('Autor nenalezen.');
            }
            $organization = $facility ? $facility->getOrganization() : null;
            if (!$organization) {
                throw new Exception('Organizace nenalezena.');
            }
            $customer = $reservation ? $reservation->getCustomer() : null;
            if (!$customer) {
                throw new Exception('Zákazník nenalezen.');
            }

            if ($payment->getNumericValue() > 0) {
                $title = 'Příjmový pokladní doklad';
            } elseif ($payment->getNumericValue() < 0) {
                $title = 'Výdajový pokladní doklad';
            }

            if ($payment->getId()) {
                $subTitle .= ' č. '.$payment->getId();
            }

            if ($payment->getCreatedDateTime()) {
                $subTitle .= ' ze dne '.$payment->getCreatedDateTime()->format('j. n. Y');
            }

            $mpdf = new Mpdf(['format' => 'A4', 'mode' => 'utf-8']);
            $mpdf->SetTitle($title);
            $mpdf->SetAuthor($author ? $author->getFullName() : 'OSWIS');
            $mpdf->SetCreator($author ? $author->getFullName() : 'OSWIS');
            $mpdf->SetSubject($title.' '.$subTitle);
            $mpdf->SetKeywords('ubytování,doklad,platba');
            $mpdf->showImageErrors = true;

            //                 '@ZakjakubOswisAccommodation/documents/accommodation-decret.html.twig',

            $content = $this->templating->render(
                '@ZakjakubOswisAccommodation/documents/cash-receipt.html.twig',
                array(
                    'title'        => $title,
                    'subTitle'     => $subTitle,
                    'payment'      => $payment,
                    'author'       => $author,
                    'organization' => $organization,
                    'type'         => $payment->getType(),
                    'customer'     => $customer,
                )
            );

            $mpdf->WriteHTML($content);
            $pdfString = $mpdf->Output('', 'S');

            if ($pdfString) {
                return $pdfString;
            }

            throw new Exception();
        } catch (Exception $e) {
            throw new Exception('Problém s generováním pokladního dokladu ve formátu PDF.'.$e->getMessage());
        }
    }
*/

}
