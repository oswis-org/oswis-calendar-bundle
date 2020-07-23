<?php /** @noinspection MethodShouldBeFinalInspection */

/**
 * @noinspection RedundantDocCommentTagInspection
 */

namespace OswisOrg\OswisCalendarBundle\Service;

use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use OswisOrg\OswisAddressBookBundle\Entity\Organization;
use OswisOrg\OswisAddressBookBundle\Entity\Person;
use OswisOrg\OswisCalendarBundle\Entity\NonPersistent\CsvPaymentImportSettings;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantPayment;
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

    /**
     * Find participant by variable symbol and value.
     *
     * @param array                    $csvPaymentRow
     * @param CsvPaymentImportSettings $csvSettings
     * @param string                   $csvRow
     *
     * @return Participant|null
     */
    public function getParticipantByPayment(array $csvPaymentRow, CsvPaymentImportSettings $csvSettings, string $csvRow): ?Participant
    {
        return null;
    }

    public function makePayment(array $csvPaymentRow, CsvPaymentImportSettings $csvSettings, string $csvRow): ParticipantPayment
    {
        $csvDate = $this->getDateFromCsvPayment($csvPaymentRow, $csvSettings, $dateKey);
        $csvValue = (int)($csvPaymentRow[$csvSettings->getValueColumnName()] ?? 0);
        $csvCurrency = $csvPaymentRow[$csvSettings->getCurrencyColumnName()] ?? null;

        $payment = new ParticipantPayment(null, null, null);

        throw new OswisException("Nepodařilo se vytvořit platbu.");
    }

    final public function createFromCsv(string $csv, ?CsvPaymentImportSettings $csvSettings = null): int
    {
        $csvSettings ??= new CsvPaymentImportSettings();
        $currencyAllowed = $csvSettings->getCurrencyAllowed();
        $this->logger->info('START: PROCESSING CSV PAYMENTS');
        $csvRows = str_getcsv($csv, "\n");
        $csvPaymentRows = array_map(
            fn($row) => str_getcsv($row, $csvSettings->getDelimiter(), $csvSettings->getEnclosure(), $csvSettings->getEscape()),
            $csvRows
        );
        $successfulPayments = [];
        $failedPayments = [];
        array_walk($csvPaymentRows, fn(&$a) => $a = array_combine($csvPaymentRows[0], $a));
        array_shift($csvPaymentRows); # remove column header
        foreach ($csvPaymentRows as $csvPaymentRowKey => $csvPaymentRow) {
            $csvRow = $csvRows[$csvPaymentRowKey];
            try {
                $csvDate = $this->getDateFromCsvPayment($csvPaymentRow, $csvSettings, $dateKey);
                $csvValue = (int)($csvPaymentRow[$csvSettings->getValueColumnName()] ?? 0);
                $csvCurrency = $csvPaymentRow[$csvSettings->getCurrencyColumnName()] ?? null;
                $csvRow = implode('; ', $csvPaymentRow);
                if (!$csvCurrency || $csvCurrency !== $currencyAllowed) {
                    $this->logger->notice(
                        "ERROR: CSV_PAYMENT_FAILED: Wrong payment currency ('$csvCurrency' instead of '$currencyAllowed'). Original CSV: ".$
                    );
                    $failedPayments[] = $csvRow.' [CURRENCY not allowed]';
                    continue;
                }
                $csvVariableSymbol = $this->getVariableSymbolFromPayment($csvPaymentRow, $csvSettings, $csvRow);
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
            'CSV_PAYMENT_END: added '.count($successfulPayments).' from '.count($csvPaymentRows).' (+ '.count($failedPayments).' failed).'
        );
        try {
            $this->sendCsvReport($successfulPayments, $failedPayments);
        } catch (Exception $e) {
            $this->logger->error('CSV_PAYMENT_REPORT_FAILED: '.$e->getMessage());
            $this->logger->error('Trace -> '.$e->getTraceAsString());
        }

        return count($successfulPayments);
    }

    private function getDateFromCsvPayment(array $csvPaymentRow, CsvPaymentImportSettings $csvSettings): ?DateTime
    {
        try {
            $dateKey = preg_grep('/.*Datum.*/', array_keys($csvPaymentRow))[0] ?? null;
            if (array_key_exists($csvSettings->getDateColumnName(), $csvPaymentRow)) {
                return new DateTime($csvPaymentRow[$csvSettings->getDateColumnName()]);
            }
            if (array_key_exists('"'.$csvSettings->getDateColumnName().'"', $csvPaymentRow)) {
                return new DateTime($csvPaymentRow['"'.$csvSettings->getDateColumnName().'"']);
            }
            if (array_key_exists('\"'.$csvSettings->getDateColumnName().'\"', $csvPaymentRow)) {
                return new DateTime($csvPaymentRow['\"'.$csvSettings->getDateColumnName().'\"']);
            }
            if (array_key_exists($dateKey, $csvPaymentRow)) {
                return new DateTime($csvPaymentRow[$dateKey]);
            }

            return new DateTime();
        } catch (Exception $e) {
            return null;
        }
    }

    public function create(ParticipantPayment $payment): ?ParticipantPayment {
        try {
            $this->em->persist($payment);
            $this->em->flush();
            $id = $payment->getId();
            $value = $payment->getNumericValue();
            $vs = $payment->getVariableSymbol();
            $this->logger->info("CREATE: Created event participant payment (by service): ID $id, VS $vs, value $value,- Kč.");

            return $payment;
        } catch (Exception $e) {
            $this->logger->notice('ERROR: Event participant payment not created (by service): '.$e->getMessage());

            return null;
        }
    }

    /**
     * @param ParticipantPayment|null $payment
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
            $formal = $eventParticipant->getParticipantCategory() ? $eventParticipant->getParticipantCategory()->isFormal() : true;
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
            $email->htmlTemplate('@OswisOrgOswisCalendar/e-mail/participant-payment.html.twig')->context($mailData);
            $this->mailer->send($email);
            // $payment->setMailConfirmationSend('event-participant-payment-service');
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
            $email->htmlTemplate('@OswisOrgOswisCalendar/e-mail/participant-csv-payments-report.html.twig')->context($mailData);
            $this->mailer->send($email);

            return true;
        } catch (Exception $e) {
            throw new OswisException('Problém s vytvářením reportu o CSV platbách.  '.$e->getMessage());
        } catch (TransportExceptionInterface $e) {
            throw new OswisException('Problém s odesláním reportu o CSV platbách.  '.$e->getMessage());
        }
    }

    public function getVariableSymbolFromPayment(
        array $csvPaymentRow,
        CsvPaymentImportSettings $csvSettings,
        string $csvRow
    ): ?string {
        $csvVariableSymbol = $csvPaymentRow[$csvSettings->getVariableSymbolColumnName()];
        if (empty($csvVariableSymbol)) {
            $this->logger->warning("ERROR: CSV_PAYMENT_FAILED: Variable symbol missing. CSV: ".$csvRow);
            $failedPayments[] = $csvRow.' [VS short]';

            return null;
        }
        $csvVariableSymbol = preg_replace('/\s/', '', $csvVariableSymbol);

        return substr(trim($csvVariableSymbol), strlen(trim($csvVariableSymbol)) - 9, 9);
    }
}
