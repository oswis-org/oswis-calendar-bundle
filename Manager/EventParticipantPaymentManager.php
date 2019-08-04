<?php

namespace Zakjakub\OswisCalendarBundle\Manager;

use DateTime;
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
use Zakjakub\OswisCalendarBundle\Entity\Event\Event;
use Zakjakub\OswisCalendarBundle\Entity\EventParticipant\EventParticipant;
use Zakjakub\OswisCalendarBundle\Entity\EventParticipant\EventParticipantPayment;
use Zakjakub\OswisCalendarBundle\Entity\EventParticipant\EventParticipantType;
use Zakjakub\OswisCoreBundle\Exceptions\OswisException;
use Zakjakub\OswisCoreBundle\Provider\OswisCoreSettingsProvider;
use Zakjakub\OswisCoreBundle\Utils\EmailUtils;
use function assert;
use function count;
use function implode;
use function str_getcsv;
use function strlen;

/**
 * Class EventParticipantPaymentManager
 * @package Zakjakub\OswisCalendarBundle\Manager
 */
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

    /**
     * @param Event       $event
     * @param string      $csv
     * @param string|null $eventParticipantTypeOfType
     * @param string      $delimiter
     * @param string      $enclosure
     * @param string      $escape
     * @param string      $variableSymbolColumnName
     * @param string      $dateColumnName
     * @param string      $valueColumnName
     * @param string      $currencyColumnName
     * @param string      $currencyAllowed
     *
     * @return int
     */
    final public function createFromCsv(
        Event $event,
        string $csv,
        ?string $eventParticipantTypeOfType = EventParticipantType::TYPE_ATTENDEE,
        ?string $delimiter = ';',
        ?string $enclosure = '"',
        ?string $escape = '\\',
        ?string $variableSymbolColumnName = 'VS',
        ?string $dateColumnName = 'Datum',
        ?string $valueColumnName = 'Objem',
        ?string $currencyColumnName = 'Měna',
        ?string $currencyAllowed = 'CZK'
    ): int {
        $eventParticipantTypeOfType = $eventParticipantTypeOfType ?? EventParticipantType::TYPE_ATTENDEE;
        $delimiter = $delimiter ?? ';';
        $enclosure = $enclosure ?? '"';
        $escape = $escape ?? '\\';
        $variableSymbolColumnName = $variableSymbolColumnName ?? 'VS';
        $dateColumnName = $dateColumnName ?? 'Datum';
        $valueColumnName = $valueColumnName ?? 'Objem';
        $currencyColumnName = $currencyColumnName ?? 'Měna';
        $currencyAllowed = $currencyAllowed ?? 'CZK';

        $this->logger ? $this->logger->info('CSV_PAYMENT_START') : null;
        $count = 0;
        $failedPaymentsCount = 0;
        $csvRow = null;
        $eventParticipants = $event->getEventParticipantsByTypeOfType($eventParticipantTypeOfType);
        $csvPayments = str_getcsv($csv, $delimiter, $enclosure, $escape);

        array_walk(
            $csvPayments,
            static function (&$a) use ($csvPayments) {
                $a = array_combine($csvPayments[0], $a);
            }
        );
        array_shift($csvPayments); # remove column header

        foreach ($csvPayments as $csvPayment) {
            try {
                $csvVariableSymbol = $csvPayment[$variableSymbolColumnName];
                $csvDate = $csvPayment[$dateColumnName] ? new DateTime($csvPayment[$dateColumnName]) : new DateTime();
                $csvValue = (int)($csvPayment[$valueColumnName] ?? 0);
                $csvCurrency = $csvPayment[$currencyColumnName] ?? null;
                $csvRow = implode('; ', $csvPayment);

                if (!$csvCurrency || $csvCurrency !== $currencyAllowed) {
                    $this->logger->notice("CSV_PAYMENT_FAILED: ERROR: Wrong currency ('$csvCurrency'' instead of '$currencyAllowed'); CSV: $csvRow;");
                    $failedPaymentsCount++;
                    continue;
                }

                if ($csvVariableSymbol && strlen($csvVariableSymbol) > 5) {
                    $csvVariableSymbol = preg_replace('/\s/', '', $csvVariableSymbol);
                    $csvVariableSymbol = substr(trim($csvVariableSymbol), strlen(trim($csvVariableSymbol)) - 9, 9);
                }
                if (!$csvVariableSymbol || strlen($csvVariableSymbol) < 6) {
                    $this->logger->notice("CSV_PAYMENT_FAILED: ERROR: VS ($csvVariableSymbol) in CSV is too short; CSV: $csvRow;");
                    $failedPaymentsCount++;
                    continue;
                }

                $filteredEventParticipants = $eventParticipants->filter(
                    static function (EventParticipant $eventParticipant) use ($csvVariableSymbol) {
                        return !$eventParticipant->isDeleted() && $eventParticipant->getVariableSymbol() === $csvVariableSymbol;
                    }
                );

                if ($filteredEventParticipants->count() < 1) {
                    $this->logger->info("CSV_PAYMENT_FAILED: ERROR: VS ($csvVariableSymbol) not found; CSV: $csvRow;");
                    $failedPaymentsCount++;
                    continue;
                }

                if ($filteredEventParticipants->count() > 1) {
                    $message = "CSV_PAYMENT_FAILED: ERROR: NOT_UNIQUE_VS: VS ($csvVariableSymbol) is present in "
                        .$filteredEventParticipants->count()." eventParticipants; CSV: $csvRow;";
                    $this->logger->info($message);
                    $failedPaymentsCount++;
                    continue;
                }

                $eventParticipant = $eventParticipants->first();
                assert($eventParticipant instanceof EventParticipant);
                $entity = $this->create($eventParticipant, $csvValue, $csvDate, 'csv', null, $csvRow);
                $infoMessage = 'CSV_PAYMENT_CREATED: id: '.$entity->getId().', ';
                $infoMessage .= 'participant: '.$eventParticipant->getId().' '.$eventParticipant->getContact()->getContactName().', ';
                $infoMessage .= 'CSV: '.$csvRow.'; ';
                $this->logger->info($infoMessage);
                $count++;
            } catch (Exception $e) {
                $this->logger->info('CSV_PAYMENT_FAILED: CSV: '.$csvRow.'; EXCEPTION: '.$e->getMessage());
                $failedPaymentsCount++;
            }

        }
        $this->logger->info('CSV_PAYMENT_END: added '.$count.' from '.count($csvPayments)." ($failedPaymentsCount failed).");

        return $count;
    }

    final public function create(
        EventParticipant $eventParticipant = null,
        int $numericValue = 0,
        DateTime $dateTime = null,
        string $type = null,
        string $note = null,
        string $internalNote = null
    ): EventParticipantPayment {
        try {
            $em = $this->em;
            $entity = new EventParticipantPayment($eventParticipant, $numericValue, $dateTime, $type, $note, $internalNote);
            $em->persist($entity);
            $em->flush();
            $name = $entity->getEventParticipant() ? $entity->getEventParticipant()->getContact()->getContactName() : $entity->getEventParticipant()->getId();
            $this->logger->info('CREATE: Created event participant payment (by manager): '.$entity->getId().' '.$name.'.');

            return $entity;
        } catch (Exception $e) {
            $this->logger->info('ERROR: Event participant payment not created (by manager): '.$e->getMessage());

            return null;
        }
    }


}
