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
use function array_key_exists;
use function array_map;
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
        // $csvRow = null;
        $eventParticipants = $event->getEventParticipantsByTypeOfType(
            $eventParticipantTypeOfType,
            null,
            true,
            true,
            1
        );
        // $csvPayments = str_getcsv($csv, $delimiter, $enclosure, $escape);
        // $csvArray = array_map('str_getcsv', file($file));
        $eventParticipantsCount = $eventParticipants->count();
        $eventName = $event->getSlug();
        $this->logger->info("Creating payments from CSV. Searching in $eventParticipantsCount participants of event $eventName.");
        $csvPayments = array_map(
            static function ($row) use ($delimiter, $enclosure, $escape) {
                return str_getcsv($row, $delimiter, $enclosure, $escape);
            },
            str_getcsv($csv, "\n")
        );
        $successfulPayments = [];
        $failedPayments = [];
        array_walk(
            $csvPayments,
            static function (&$a) use ($csvPayments) {
                $a = array_combine($csvPayments[0], $a);
            }
        );
        array_shift($csvPayments); # remove column header
        foreach ($csvPayments as $csvPayment) {
            $csvRow = null;
            try {
                $csvVariableSymbol = $csvPayment[$variableSymbolColumnName];
                if (array_key_exists($dateColumnName, $csvPayment)) {
                    $csvDate = new DateTime($csvPayment[$dateColumnName]);
                } elseif (array_key_exists('"'.$dateColumnName.'"', $csvPayment)) {
                    $csvDate = new DateTime($csvPayment['"'.$dateColumnName.'"']);
                } else {
                    $csvDate = new DateTime();
                }
                $csvValue = (int)($csvPayment[$valueColumnName] ?? 0);
                $csvCurrency = $csvPayment[$currencyColumnName] ?? null;
                $csvRow = implode('; ', $csvPayment);
                if (!$csvCurrency || $csvCurrency !== $currencyAllowed) {
                    $this->logger->notice("CSV_PAYMENT_FAILED: ERROR: Wrong currency ('$csvCurrency'' instead of '$currencyAllowed'); CSV: $csvRow;");
                    $failedPayments[] = $csvRow.' [CURRENCY not allowed]';
                    continue;
                }
                if ($csvVariableSymbol && strlen($csvVariableSymbol) > 5) {
                    $csvVariableSymbol = preg_replace('/\s/', '', $csvVariableSymbol);
                    $csvVariableSymbol = substr(trim($csvVariableSymbol), strlen(trim($csvVariableSymbol)) - 9, 9);
                }
                if (!$csvVariableSymbol || strlen($csvVariableSymbol) < 6) {
                    $this->logger->notice("CSV_PAYMENT_FAILED: ERROR: VS ($csvVariableSymbol) in CSV is too short; CSV: $csvRow;");
                    $failedPayments[] = $csvRow.' [VS short]';
                    continue;
                }
                $filteredEventParticipants = $eventParticipants->filter(
                    static function (EventParticipant $eventParticipant) use ($csvVariableSymbol) {
                        return !$eventParticipant->isDeleted() && $eventParticipant->getVariableSymbol() === $csvVariableSymbol;
                    }
                );
                if ($filteredEventParticipants->count() < 1) {
                    $filteredEventParticipants = $eventParticipants->filter(
                        static function (EventParticipant $eventParticipant) use ($csvVariableSymbol) {
                            return $eventParticipant->getVariableSymbol() === $csvVariableSymbol;
                        }
                    );
                }
                if ($filteredEventParticipants->count() < 1) {
                    $this->logger->info("CSV_PAYMENT_FAILED: ERROR: VS ($csvVariableSymbol) not found; CSV: $csvRow;");
                    $failedPayments[] = $csvRow.' [VS not found]';
                    continue;
                }
                if ($filteredEventParticipants->count() > 1) {
                    $message = "CSV_PAYMENT_NOTICE: NOT_UNIQUE_VS: VS ($csvVariableSymbol) is present in ".$filteredEventParticipants->count()." eventParticipants; CSV: $csvRow;";
                    $this->logger->info($message);
                    $eventParticipant = $filteredEventParticipants->filter(
                        static function (EventParticipant $oneEventParticipant) {
                            return $oneEventParticipant->hasActivatedContactUser();
                        }
                    )->first();
                    if (!$eventParticipant) {
                        $eventParticipant = $filteredEventParticipants->first();
                    }
                } else {
                    $eventParticipant = $eventParticipants->first();
                }
                assert($eventParticipant instanceof EventParticipant);
                if (!$eventParticipant) {
                    $this->logger->info("CSV_PAYMENT_FAILED: ERROR: EventParticipant with VS ($csvVariableSymbol) not found; CSV: $csvRow;");
                    $failedPayments[] = $csvRow.' [VS not found (2. step)]';
                    continue;
                }
                $oneNewPayment = $this->create($eventParticipant, $csvValue, $csvDate, 'csv', null, $csvRow);
                $this->sendConfirmation($oneNewPayment);
                $infoMessage = 'CSV_PAYMENT_CREATED: id: '.$oneNewPayment->getId().', ';
                $infoMessage .= 'participant: '.$eventParticipant->getId().' '.$eventParticipant->getContact()->getContactName().', ';
                $infoMessage .= 'CSV: '.$csvRow.'; ';
                if ($eventParticipant->isDeleted()) {
                    $infoMessage .= ' [DELETED PARTICIPANT] ';
                }
                $this->logger->info($infoMessage);
                $successfulPayments[] = $csvRow;
            } catch (Exception $e) {
                $this->logger->info('CSV_PAYMENT_FAILED: CSV: '.$csvRow.'; EXCEPTION: '.$e->getMessage());
                $failedPayments[] = $csvRow.' [EXCEPTION: '.$e->getMessage().']';
            }
        }
        $this->logger->error(
            'CSV_PAYMENT_END: added '.count($successfulPayments).' from '.count($csvPayments).' (+ '.count($failedPayments).' failed).'
        );
        try {
            $this->sendCsvReport($successfulPayments, $failedPayments);
        } catch (Exception $e) {
            $this->logger->error('CSV_PAYMENT_REPORT_FAILED: '.$e->getMessage());
            $this->logger->error('Trace -> '.$e->getTraceAsString());
        }

        return count($successfulPayments);
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

    /**
     * @param EventParticipantPayment $payment
     *
     * @return void
     * @throws OswisException
     * @todo Fix case when contact is organization.
     */
    final public function sendConfirmation(
        EventParticipantPayment $payment = null
    ): void {
        try {
            $em = $this->em;
            if (!$payment) {
                throw new NotFoundHttpException('Platba nenalezena.');
            }
            assert($payment instanceof EventParticipantPayment);
            $eventParticipant = $payment->getEventParticipant();
            if (!$eventParticipant || $eventParticipant->isDeleted()) {
                return;
            }
            $formal = $eventParticipant->getEventParticipantType() ? $eventParticipant->getEventParticipantType()->isFormal() : true;
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
                'salutationName'   => $salutationName,
                'a'                => $a,
                'f'                => $formal,
                'payment'          => $payment,
                'eventParticipant' => $payment->getEventParticipant(),
                'oswis'            => $this->oswisCoreSettings,
                'logo'             => 'cid:logo',
            );
            $archive = new NamedAddress(
                $mailSettings['archive_address'] ?? '',
                EmailUtils::mime_header_encode($mailSettings['archive_name'] ?? '') ?? ''
            );
            $email = (new TemplatedEmail())->to(new NamedAddress($eMail ?? '', EmailUtils::mime_header_encode($name ?? '') ?? ''))->bcc($archive)->subject(
                EmailUtils::mime_header_encode($title)
            )->htmlTemplate('@ZakjakubOswisCalendar/e-mail/event-participant-payment.html.twig')->context($mailData);
            $this->mailer->send($email);
            $payment->setMailConfirmationSend('event-participant-payment-manager');
            $em->persist($payment);
            $em->flush();
        } catch (Exception $e) {
            $message = 'Problém s odesláním potvrzení o platbě (při vytváření zprávy). ';
            $this->logger->error($message.$e->getMessage());
            $this->logger->error($e->getTraceAsString());
            throw new OswisException($message);
        } catch (TransportExceptionInterface $e) {
            $message = 'Problém s odesláním potvrzení o platbě (při odeslání zprávy). ';
            $this->logger->error($message.$e->getMessage());
            $this->logger->error($e->getTraceAsString());
            throw new OswisException($message);
        }
    }

    /**
     * @param array $successfulPayments
     * @param array $failedPayments
     *
     * @return string
     * @throws OswisException
     */
    final public function sendCsvReport(
        array $successfulPayments,
        array $failedPayments
    ): string {
        try {
            $title = 'Report CSV plateb';
            $mailSettings = $this->oswisCoreSettings->getEmail();
            $mailData = array(
                'successfulPayments' => $successfulPayments,
                'failedPayments'     => $failedPayments,
                'oswis'              => $this->oswisCoreSettings,
                'logo'               => 'cid:logo',
            );
            $archive = new NamedAddress(
                $mailSettings['archive_address'] ?? '',
                EmailUtils::mime_header_encode($mailSettings['archive_name'] ?? '') ?? ''
            );
            $email = (new TemplatedEmail())->to($archive)->subject(EmailUtils::mime_header_encode($title))->htmlTemplate(
                '@ZakjakubOswisCalendar/e-mail/event-participant-csv-payments-report.html.twig'
            )->context($mailData);
            $this->mailer->send($email);

            return true;
        } catch (Exception $e) {
            throw new OswisException('Problém s odesláním reportu o CSV platbách (při vytváření zprávy).  '.$e->getMessage());
        } catch (TransportExceptionInterface $e) {
            throw new OswisException('Problém s odesláním reportu o CSV platbách (při odeslání zprávy).  '.$e->getMessage());
        }
    }
}
