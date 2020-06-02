<?php
/**
 * @noinspection RedundantDocCommentTagInspection
 */

namespace OswisOrg\OswisCalendarBundle\Service;

use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use OswisOrg\OswisAddressBookBundle\Entity\Organization;
use OswisOrg\OswisAddressBookBundle\Entity\Person;
use OswisOrg\OswisCalendarBundle\Entity\Event\Event;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantPayment;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantType;
use OswisOrg\OswisCoreBundle\Exceptions\OswisException;
use OswisOrg\OswisCoreBundle\Provider\OswisCoreSettingsProvider;
use OswisOrg\OswisCoreBundle\Utils\EmailUtils;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use function array_key_exists;
use function array_map;
use function assert;
use function count;
use function implode;
use function str_getcsv;
use function strlen;

class ParticipantPaymentService
{

    protected EntityManagerInterface $em;

    protected MailerInterface $mailer;

    protected LoggerInterface $logger;

    protected OswisCoreSettingsProvider $coreSettings;

    protected ParticipantService $participantService;

    public function __construct(
        EntityManagerInterface $em,
        MailerInterface $mailer,
        LoggerInterface $logger,
        OswisCoreSettingsProvider $oswisCoreSettings,
        ParticipantService $participantService
    ) {
        $this->em = $em;
        $this->mailer = $mailer;
        $this->logger = $logger;
        $this->coreSettings = $oswisCoreSettings;
        $this->participantService = $participantService;
    }

    final public function createFromCsv(
        Event $event,
        string $csv,
        ?string $eventParticipantTypeOfType = ParticipantType::TYPE_ATTENDEE,
        ?string $delimiter = ';',
        ?string $enclosure = '"',
        ?string $escape = '\\',
        ?string $variableSymbolColumnName = 'VS',
        ?string $dateColumnName = 'Datum',
        ?string $valueColumnName = 'Objem',
        ?string $currencyColumnName = 'Měna',
        ?string $currencyAllowed = 'CZK'
    ): int {
        $eventParticipantTypeOfType = $eventParticipantTypeOfType ?? ParticipantType::TYPE_ATTENDEE;
        $delimiter = $delimiter ?? ';';
        $enclosure = $enclosure ?? '"';
        $escape = $escape ?? '\\';
        $variableSymbolColumnName = $variableSymbolColumnName ?? 'VS';
        $dateColumnName = $dateColumnName ?? 'Datum';
        $valueColumnName = $valueColumnName ?? 'Objem';
        $currencyColumnName = $currencyColumnName ?? 'Měna';
        $currencyAllowed = $currencyAllowed ?? 'CZK';
        $this->logger->info('CSV_PAYMENT_START');
        // $csvRow = null;
        $eventParticipants = $this->participantService->getEventParticipantsByTypeOfType(
            $event,
            $eventParticipantTypeOfType,
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
        $dateKey = preg_grep('/.*Datum.*/', array_keys($csvPayments[0]))[0] ?? null;
        foreach ($csvPayments as $csvPayment) {
            $csvRow = null;
            try {
                $csvVariableSymbol = $csvPayment[$variableSymbolColumnName];
                if (array_key_exists($dateColumnName, $csvPayment)) {
                    $csvDate = new DateTime($csvPayment[$dateColumnName]);
                } elseif (array_key_exists('"'.$dateColumnName.'"', $csvPayment)) {
                    $csvDate = new DateTime($csvPayment['"'.$dateColumnName.'"']);
                } elseif (array_key_exists('\"'.$dateColumnName.'\"', $csvPayment)) {
                    $csvDate = new DateTime($csvPayment['\"'.$dateColumnName.'\"']);
                } elseif (array_key_exists($dateKey, $csvPayment)) {
                    $csvDate = new DateTime($csvPayment[$dateKey]);
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
                    fn(Participant $p) => !$p->isDeleted() && $p->getVariableSymbol() === $csvVariableSymbol
                );
                if ($filteredEventParticipants->count() < 1) {
                    $filteredEventParticipants = $eventParticipants->filter(
                        fn(Participant $p) => $p->getVariableSymbol() === $csvVariableSymbol
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
                        static function (Participant $oneEventParticipant) use ($csvVariableSymbol) {
                            return $oneEventParticipant->getVariableSymbol() === $csvVariableSymbol && $oneEventParticipant->hasActivatedContactUser();
                        }
                    )->first();
                    if (empty($eventParticipant)) {
                        $eventParticipant = $filteredEventParticipants->first();
                    }
                } else {
                    $eventParticipant = $filteredEventParticipants->first();
                }
                if (!($eventParticipant instanceof Participant)) {
                    $this->logger->info("CSV_PAYMENT_FAILED: ERROR: Participant with VS ($csvVariableSymbol) not found; CSV: $csvRow;");
                    $failedPayments[] = $csvRow.' [VS not found (2. step)]';
                    continue;
                }
                $oneNewPayment = $this->create($eventParticipant, $csvValue, $csvDate, 'csv', null, $csvRow);
                if (null === $oneNewPayment) {
                    throw new OswisException('Error occurred, payment not created.');
                }
                $this->sendConfirmation($oneNewPayment);
                $infoMessage = 'CSV_PAYMENT_CREATED: id: '.$oneNewPayment->getId().', ';
                $infoMessage .= 'participant: '.$eventParticipant->getId().' ';
                $infoMessage .= $eventParticipant->getContact() ? $eventParticipant->getContact()->getName() : ''.', ';
                $infoMessage .= 'CSV: '.$csvRow.'; ';
                $deletedString = '';
                if ($eventParticipant->isDeleted()) {
                    $deletedString = ' [DELETED PARTICIPANT] ';
                    $infoMessage .= $deletedString;
                }
                $this->logger->info($infoMessage);
                $successfulPayments[] = $csvRow.$deletedString;
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
        Participant $eventParticipant,
        int $numericValue = 0,
        DateTime $dateTime = null,
        string $type = null,
        string $note = null,
        string $internalNote = null
    ): ?ParticipantPayment {
        try {
            $em = $this->em;
            $entity = new ParticipantPayment($eventParticipant, $numericValue, $dateTime, $type, $note, $internalNote);
            $em->persist($entity);
            $em->flush();
            if ($entity->getParticipant() && $entity->getParticipant()->getContact()) {
                $name = $entity->getParticipant()->getContact()->getName();
            } else {
                $name = $entity->getParticipant()->getId();
            }
            $this->logger->info('CREATE: Created event participant payment (by service): '.$entity->getId().' '.$name.'.');

            return $entity;
        } catch (Exception $e) {
            $this->logger->info('ERROR: Event participant payment not created (by service): '.$e->getMessage());

            return null;
        }
    }

    /**
     * @param ParticipantPayment $payment
     *
     * @return void
     * @throws OswisException
     * @todo Fix case when contact is organization.
     */
    final public function sendConfirmation(ParticipantPayment $payment = null): void
    {
        try {
            if (null === $payment || !($payment instanceof ParticipantPayment)) {
                throw new NotFoundHttpException('Platba neexistuje.');
            }
            $eventParticipant = $payment->getParticipant();
            if (!$eventParticipant || $eventParticipant->isDeleted()) {
                $this->logger->notice('Not sending payment confirmation because eventParticipant is deleted.');

                return;
            }
            $formal = $eventParticipant->getParticipantType() ? $eventParticipant->getParticipantType()->isFormal() : true;
            $contact = $eventParticipant->getContact();
            $title = $payment->getNumericValue() < 0 ? 'Vrácení/oprava platby' : 'Přijetí platby';
            if ($contact instanceof Person) {
                $salutationName = $contact->getSalutationName() ?? '';
                $a = $contact->getCzechSuffixA() ?? '';
            } else {
                assert($contact instanceof Organization);
                $salutationName = $contact->getName() ?? ''; // TODO: Correct salutation (contact of organization).
                $a = '';
            }
            $name = $contact->getAppUser() ? $contact->getAppUser()->getFullName() : $contact->getName();
            $eMail = $contact->getAppUser() ? $contact->getAppUser()->getEmail() : $contact->getEmail();
            $mailConfig = $this->coreSettings->getEmail();
            $mailData = array(
                'salutationName'   => $salutationName,
                'a'                => $a,
                'f'                => $formal,
                'payment'          => $payment,
                'eventParticipant' => $payment->getParticipant(),
                'oswis'            => $this->coreSettings,
                'logo'             => 'cid:logo',
            );
            $archive = new Address(
                $mailConfig['archive_address'] ?? '', self::mimeEnc($mailConfig['archive_name'] ?? '') ?? ''
            );
            $email = new TemplatedEmail();
            $email->to(new Address($eMail ?? '', self::mimeEnc($name ?? '') ?? ''));
            $email->bcc($archive)->subject(self::mimeEnc($title));
            $email->htmlTemplate('@OswisOrgOswisCalendar/e-mail/event-participant-payment.html.twig')->context($mailData);
            $this->mailer->send($email);
            $payment->setMailConfirmationSend('event-participant-payment-service');
            $this->em->persist($payment);
            $this->em->flush();
        } catch (TransportExceptionInterface $e) {
            $message = 'Problém s odesláním potvrzení o platbě. ';
            $this->logger->error($message.$e->getMessage());
            $this->logger->error($e->getTraceAsString());
            throw new OswisException($message);
        } catch (Exception $e) {
            $message = 'Problém při vytváření potvrzení o platbě. ';
            $this->logger->error($message.$e->getMessage());
            $this->logger->error($e->getTraceAsString());
            throw new OswisException($message);
        }
    }

    private static function mimeEnc(string $mime): string
    {
        return EmailUtils::mime_header_encode($mime);
    }

    /**
     * @param array $successfulPayments
     * @param array $failedPayments
     *
     * @return bool
     * @throws OswisException
     */
    final public function sendCsvReport(array $successfulPayments, array $failedPayments): bool
    {
        try {
            $title = 'Report CSV plateb';
            $mailConfig = $this->coreSettings->getEmail();
            $mailData = array(
                'successfulPayments' => $successfulPayments,
                'failedPayments'     => $failedPayments,
                'oswis'              => $this->coreSettings,
                'logo'               => 'cid:logo',
            );
            $archive = new Address(
                $mailConfig['archive_address'] ?? '', self::mimeEnc($mailConfig['archive_name'] ?? '') ?? ''
            );
            $email = new TemplatedEmail();
            $email->to($archive)->subject(self::mimeEnc($title));
            $email->htmlTemplate('@OswisOrgOswisCalendar/e-mail/event-participant-csv-payments-report.html.twig')->context($mailData);
            $this->mailer->send($email);

            return true;
        } catch (Exception $e) {
            throw new OswisException('Problém s vytvářením reportu o CSV platbách.  '.$e->getMessage());
        } catch (TransportExceptionInterface $e) {
            throw new OswisException('Problém s odesláním reportu o CSV platbách.  '.$e->getMessage());
        }
    }
}
