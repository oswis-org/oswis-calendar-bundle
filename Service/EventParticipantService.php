<?php
/**
 * @noinspection PhpUnusedParameterInspection
 * @noinspection PhpUnused
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Service;

use DateTime;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Mpdf\MpdfException;
use OswisOrg\OswisAddressBookBundle\Entity\AbstractClass\AbstractContact;
use OswisOrg\OswisAddressBookBundle\Entity\Organization;
use OswisOrg\OswisAddressBookBundle\Entity\Person;
use OswisOrg\OswisCalendarBundle\Entity\Event\Event;
use OswisOrg\OswisCalendarBundle\Entity\EventAttendeeFlag;
use OswisOrg\OswisCalendarBundle\Entity\EventParticipant\EventParticipant;
use OswisOrg\OswisCalendarBundle\Entity\EventParticipant\EventParticipantFlag;
use OswisOrg\OswisCalendarBundle\Entity\EventParticipant\EventParticipantFlagConnection;
use OswisOrg\OswisCalendarBundle\Entity\EventParticipant\EventParticipantFlagInEventConnection;
use OswisOrg\OswisCalendarBundle\Entity\EventParticipant\EventParticipantFlagType;
use OswisOrg\OswisCalendarBundle\Entity\EventParticipant\EventParticipantType;
use OswisOrg\OswisCalendarBundle\Repository\EventParticipantRepository;
use OswisOrg\OswisCoreBundle\Entity\AppUser\AppUser;
use OswisOrg\OswisCoreBundle\Entity\NonPersistent\Export\PdfExportList;
use OswisOrg\OswisCoreBundle\Exceptions\OswisException;
use OswisOrg\OswisCoreBundle\Exceptions\OswisUserNotUniqueException;
use OswisOrg\OswisCoreBundle\Provider\OswisCoreSettingsProvider;
use OswisOrg\OswisCoreBundle\Service\AppUserService;
use OswisOrg\OswisCoreBundle\Service\ExportService;
use OswisOrg\OswisCoreBundle\Utils\StringUtils;
use Psr\Log\LoggerInterface;
use rikudou\CzQrPayment\QrPayment;
use rikudou\CzQrPayment\QrPaymentOptions;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Exception\LogicException;
use Symfony\Component\Mime\Exception\RfcComplianceException;
use Symfony\Component\Security\Core\Encoder\UserPasswordEncoderInterface;
use function assert;
use function Symfony\Component\String\u;

class EventParticipantService
{
    public const DEFAULT_LIST_TITLE = 'Přehled přihlášek';

    protected EntityManagerInterface $em;

    protected ?LoggerInterface $logger;

    protected ?OswisCoreSettingsProvider $coreSettings;

    protected MailerInterface $mailer;

    protected AppUserService $appUserService;

    protected ExportService $exportService;

    protected EventParticipantRepository $participantRepository;

    protected UserPasswordEncoderInterface $encoder;

    public function __construct(
        EntityManagerInterface $em,
        MailerInterface $mailer,
        OswisCoreSettingsProvider $oswisCoreSettings,
        ?LoggerInterface $logger,
        AppUserService $appUserService,
        ExportService $exportService,
        UserPasswordEncoderInterface $encoder
    ) {
        $this->em = $em;
        $this->logger = $logger;
        $this->coreSettings = $oswisCoreSettings;
        $this->mailer = $mailer;
        $this->appUserService = $appUserService;
        $this->exportService = $exportService;
        $this->participantRepository = $this->getRepository();
        $this->encoder = $encoder;
        /// TODO: Encoder, createAppUser...
        /// TODO: Throw exceptions!
    }

    final public function getRepository(): EventParticipantRepository
    {
        $repository = $this->em->getRepository(EventParticipant::class);
        assert($repository instanceof EventParticipantRepository);

        return $repository;
    }

    final public function create(
        ?AbstractContact $contact = null,
        ?Event $event = null,
        ?EventParticipantType $eventParticipantType = null,
        ?Collection $eventContactFlagConnections = null,
        ?Collection $eventParticipantNotes = null
    ): ?EventParticipant {
        try {
            $entity = new EventParticipant($contact, $event, $eventParticipantType, $eventContactFlagConnections, $eventParticipantNotes);
            $this->em->persist($entity);
            $this->em->flush();
            $infoMessage = 'CREATE: Created event participant (by service): ';
            $infoMessage .= $entity->getId().', ';
            $infoMessage .= ($entity->getContact() ? $entity->getContact()->getName() : '').', ';
            $infoMessage .= ($entity->getEvent() ? $entity->getEvent()->getName() : '').'.';
            $this->logger ? $this->logger->info($infoMessage) : null;

            return $entity;
        } catch (Exception $e) {
            $this->logger ? $this->logger->info('ERROR: Event event participant not created (by service): '.$e->getMessage()) : null;

            return null;
        }
    }

    /**
     * Sends summary e-mail if user is already activated or activation e-mail if user is not activated.
     *
     * @param EventParticipant|null $participant
     * @param bool                  $new
     * @param string|null           $token
     *
     * @return bool
     * @throws OswisException
     */
    final public function sendMail(EventParticipant $participant = null, ?bool $new = false, ?string $token = null): bool
    {
        if (!$participant || !$participant->getEvent() || !$participant->getContact()) {
            throw new OswisException('Přihláška není kompletní nebo je poškozená.');
        }
        if ($participant->isDeleted()) {
            return $participant->getEMailDeleteConfirmationDateTime() ? true : $this->sendCancelConfirmation($participant);
        }
        if ($participant->hasActivatedContactUser()) {
            return $participant->getEMailConfirmationDateTime() ? true : $this->sendSummary($participant, $new);
        }
        if (!empty($token)) {
            foreach ($participant->getContact()->getContactPersons() as $contactPerson) {
                assert($contactPerson instanceof Person);
                if ($contactPerson->getAppUser() && $contactPerson->getAppUser()->checkAndDestroyAccountActivationRequestToken($token)) {
                    return $this->sendSummary($participant, $new);
                }
            }
        }

        return $this->sendVerification($participant);
    }

    final public function sendCancelConfirmation(EventParticipant $participant): bool
    {
        try {
            $event = $participant->getEvent();
            $participantContact = $participant->getContact();
            if (null === $event || null === $participantContact) {
                return false;
            }
            $isOrganization = !($participantContact instanceof Person);
            $contactPersons = $isOrganization ? $participantContact->getContactPersons() : new ArrayCollection([$participantContact]);
            $remaining = $contactPersons->count();
            foreach ($contactPersons as $contactPerson) {
                assert($contactPerson instanceof Person);
                $email = $this->getEmptyEmail($contactPerson, 'Zrušení přihlášky');
                $email->htmlTemplate('@OswisOrgOswisCalendar/e-mail/event-participant-delete.html.twig');
                $email->context($this->getMailData($participant, $event, $contactPerson, $isOrganization));
                $this->em->persist($participant);
                try {
                    $this->mailer->send($email);
                    $remaining--;
                } catch (TransportExceptionInterface $e) {
                    $this->logger->error($e->getMessage());
                }
            }
            $this->em->flush();
            if ($remaining > 0) {
                throw new OswisException("Část zpráv se nepodařilo odeslat (chybí $remaining z ".$contactPersons->count().').');
            }

            return true;
        } catch (Exception $e) {
            $this->logger->error('Problém s odesláním potvrzení o zrušení přihlášky. '.$e->getMessage());

            return false;
        }
    }

    /**
     * @param Person $person
     * @param string $title
     *
     * @return TemplatedEmail
     * @throws LogicException
     * @throws RfcComplianceException
     */
    private function getEmptyEmail(Person $person, string $title): TemplatedEmail
    {
        return (new TemplatedEmail())->to($person->getMailerAddress())->bcc($this->coreSettings->getArchiveMailerAddress())->subject($title);
    }

    private function getMailData(EventParticipant $participant, Event $event, Person $contactPerson, bool $isOrg = false): array
    {
        return [
            'eventParticipant' => $participant,
            'event'            => $event,
            'contactPerson'    => $contactPerson,
            'f'                => $participant->isFormal(),
            'salutationName'   => $contactPerson->getSalutationName(),
            'a'                => $contactPerson->getCzechSuffixA(),
            'isOrganization'   => $isOrg,
            'oswis'            => $this->coreSettings->getArray(),
        ];
    }

    /**
     * Send summary of eventParticipant. Includes appUser info is appUser exist.
     *
     * @param EventParticipant $participant
     * @param bool             $new
     *
     * @return bool
     * @throws OswisException
     */
    final public function sendSummary(EventParticipant $participant, bool $new = false): bool
    {
        try {
            $event = $participant->getEvent();
            $participantContact = $participant->getContact();
            if (!($event instanceof Event) || !($participantContact instanceof AbstractContact)) {
                return false;
            }
            $isOrganization = !($participantContact instanceof Person);
            $contactPersons = $isOrganization ? $participantContact->getContactPersons() : new ArrayCollection([$participantContact]);
            $remaining = $contactPersons->count();
            foreach ($contactPersons as $contactPerson) {
                assert($contactPerson instanceof Person);
                $password = null;
                if (null !== $contactPerson->getAppUser() && empty($contactPerson->getAppUser()->getPassword())) {
                    $password = StringUtils::generatePassword();
                    $contactPerson->getAppUser()->setPassword($this->encoder->encodePassword($contactPerson->getAppUser(), $password));
                    $this->em->flush();
                }
                $mailData = $this->getMailData($participant, $event, $contactPerson, $isOrganization);
                $mailData['appUser'] = $participantContact->getAppUser();
                $mailData['password'] = $password;
                $mailData['bankAccount'] = $event->getBankAccountComplete();
                $email = $this->getEmptyEmail($contactPerson, !$new ? 'Změna přihlášky' : 'Shrnutí nové přihlášky');
                $email->htmlTemplate('@OswisOrgOswisCalendar/e-mail/event-participant.html.twig')->context($mailData);
                $qrComment = $participantContact->getSlug().', ID '.$participant->getId().', '.$event->getSlug();
                if ($depositPaymentQrPng = self::getQrPng($event, $participant, $qrComment, true)) {
                    $email->embed($depositPaymentQrPng, 'depositQr', 'image/png');
                    $mailData['depositQr'] = 'cid:depositQr';
                }
                if ($restPaymentQrPng = self::getQrPng($event, $participant, $qrComment, false)) {
                    $email->embed($restPaymentQrPng, 'restQr', 'image/png');
                    $mailData['restQr'] = 'cid:restQr';
                }
                try {
                    $this->mailer->send($email);
                    $participant->setMailConfirmationSend('event-participant-service');
                    $this->em->persist($participant);
                    $remaining--;
                } catch (TransportExceptionInterface $e) {
                    $this->logger->error($e->getMessage());
                }
            }
            $this->em->flush();
            if ($remaining > 0) {
                throw new OswisException("Část zpráv se nepodařilo odeslat (chybí $remaining z ".$contactPersons->count().').');
            }

            return true;
        } catch (Exception $e) {
            $this->logger->error($e->getMessage());
            throw new OswisException('Problém s odesláním shrnutí přihlášky. '.$e->getMessage());
        }
    }

    private static function getQrPng(Event $event, EventParticipant $eventParticipant, string $qrComment, bool $isDeposit): ?string
    {
        try {
            return (new QrPayment(
                $event->getBankAccountNumber(), $event->getBankAccountBank(), [
                    QrPaymentOptions::VARIABLE_SYMBOL => $eventParticipant->getVariableSymbol(),
                    QrPaymentOptions::AMOUNT          => $isDeposit ? $eventParticipant->getPriceDeposit() : $eventParticipant->getPriceRest(),
                    QrPaymentOptions::CURRENCY        => 'CZK',
                    QrPaymentOptions::COMMENT         => $qrComment.', '.($isDeposit ? 'záloha' : 'doplatek'),
                ]
            ))->getQrImage(true)->writeString();
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * @param EventParticipant $participant
     *
     * @return bool
     * @throws OswisException
     */
    final public function sendVerification(EventParticipant $participant): bool
    {
        try {
            $event = $participant->getEvent();
            $participantContact = $participant->getContact();
            if (null === $event || null === $participantContact) {
                return false;
            }
            $isOrganization = !($participantContact instanceof Person);
            $contactPersons = $isOrganization ? $participantContact->getContactPersons() : new ArrayCollection([$participantContact]);
            $remaining = $contactPersons->count();
            $appUserRepository = $this->appUserService->getRepository();
            $this->em->persist($participant);
            foreach ($contactPersons as $contactPerson) {
                assert($contactPerson instanceof Person);
                if (null === $contactPerson->getAppUser()) {
                    if (count($appUserRepository->findByEmail($contactPerson->getEmail())) > 0) {
                        throw new OswisUserNotUniqueException('Zadaný e-mail je již použitý.');
                    }
                    $contactPerson->setAppUser(new AppUser($contactPerson->getName(), null, $contactPerson->getEmail()));
                }
                $contactPerson->getAppUser()->generateAccountActivationRequestToken();
                $email = $this->getEmptyEmail($contactPerson, 'Ověření přihlášky');
                $email->htmlTemplate('@OswisOrgOswisCalendar/e-mail/event-participant-verification.html.twig');
                $email->context($this->getMailData($participant, $event, $contactPerson, $isOrganization));
                try {
                    $this->em->flush();
                    $this->mailer->send($email);
                    $remaining--;
                } catch (TransportExceptionInterface $e) {
                    $this->logger->error($e->getMessage());
                    throw new OswisException('Odeslání ověřovacího e-mailu se nezdařilo ('.$e->getMessage().').');
                }
                $this->em->flush();
            }
            if ($remaining > 0) {
                $message = "Část ověřovacích zpráv se nepodařilo odeslat (chybí $remaining z ".$contactPersons->count().').';
                throw new OswisException($message);
            }

            return true;
        } catch (Exception $e) {
            $this->logger->error($e->getMessage());
            throw new OswisException('Problém s odesláním ověřovacího e-mailu. '.$e->getMessage());
        }
    }

    /**
     * Calls method for update of active revision in container. DEPRECATED!
     */
    final public function updateActiveRevisions(): void
    {
        $eventParticipants = $this->participantRepository->findAll();
        foreach ($eventParticipants as $eventParticipant) {
            assert($eventParticipant instanceof EventParticipant);
            // $eventParticipant->updateActiveRevision();
            $eventParticipant->destroyRevisions();
            $this->em->persist($eventParticipant);
        }
        $this->em->flush();
    }

    /**
     * @param Event                     $event
     * @param EventParticipantType|null $participantType
     * @param bool                      $detailed
     * @param string                    $title
     *
     * @param int|null                  $recursiveDepth
     *
     * @throws LogicException
     * @throws OswisException
     * @throws RfcComplianceException
     */
    public function sendEventParticipantList(
        Event $event,
        ?EventParticipantType $participantType = null,
        bool $detailed = false,
        string $title = null,
        ?int $recursiveDepth = 0
    ): void {
        // TODO: Check and refactor.
        // $templatePdf = '@OswisOrgOswisCalendar/documents/pages/event-participant-list.html.twig';
        $templateEmail = '@OswisOrgOswisCalendar/e-mail/event-participant-list.html.twig';
        $title ??= self::DEFAULT_LIST_TITLE.' ('.$event->getShortName().')';
        $events = new ArrayCollection([$event]);
        // TODO: Send events participants from repo.
        foreach ($event->getSubEvents() as $subEvent) {
            if (!$events->contains($subEvent)) {
                $events->add($subEvent);
            }
        }
        $data = [
            'title'                => $title,
            'eventParticipantType' => $participantType,
            'event'                => $event,
            'events'               => $events,
            'participantsService'  => $this,
        ];
        $pdfString = null;
        $message = null;
        try {
            $pdfListConfig = new PdfExportList('Přehled účastníků', new ArrayCollection(), $data);
            $pdfString = $this->exportService->getPdfAsString($pdfListConfig);
            // TODO: $pdfString = $this->exportService->generatePdfAsString('Přehled účastníků', $templatePdf, $data, $paper, $detailed);
        } catch (MpdfException $e) {
            $pdfString = null;
            $this->logger->error($e->getMessage());
            $message = 'Vygenerování PDF se nezdařilo (MpdfException). '.$e->getMessage();
        } catch (Exception $e) {
            $pdfString = null;
            $this->logger->error($e->getMessage());
            $message = 'Vygenerování PDF se nezdařilo (Exception). '.$e->getMessage();
        }
        $mailData = [
            'data'    => $data,
            'message' => $message,
            'oswis'   => $this->coreSettings->getArray(),
        ];
        $mail = new TemplatedEmail();
        $mail->to($this->coreSettings->getArchiveMailerAddress())->subject($title);
        $mail->htmlTemplate($templateEmail)->context($mailData);
        if ($pdfString) {
            $mail->attach($pdfString, $title, 'application/pdf');
        }
        try {
            $this->mailer->send($mail);
        } catch (TransportExceptionInterface $e) {
            $this->logger->error($e->getMessage());
            throw new OswisException('Odeslání e-mailu s přehledem přihlášek se nezdařilo. '.$e->getMessage().' ');
        }
    }

    final public function sendInfoMails(
        Event $event,
        ?string $participantTypeOfType = null,
        int $recursiveDepth = 0,
        ?int $count = 0,
        ?string $source = null,
        ?bool $force = false
    ): int {
        $successCount = 0;
        $eventParticipants = $this->getEventParticipantsByTypeOfType(
            $event,
            $participantTypeOfType,
            false,
            false,
            $recursiveDepth
        )->filter(fn(EventParticipant $p) => $p->getInfoMailSentCount() < 1);
        $i = 0;
        foreach ($eventParticipants as $eventParticipant) {
            if ($this->sendInfoMail($eventParticipant, $source, $force)) {
                $eventParticipants->removeElement($eventParticipant);
                $successCount++;
            }
            $i++;
            if ($i >= $count) {
                break;
            }
        }

        return $successCount;
    }

    public function getEventParticipantsByTypeOfType(
        ?Event $event = null,
        ?string $participantTypeOfType = EventParticipantType::TYPE_ATTENDEE,
        ?bool $includeDeleted = false,
        ?bool $includeNotActivated = true,
        ?int $depth = 1
    ): Collection {
        $opts = [
            EventParticipantRepository::CRITERIA_EVENT                    => $event,
            EventParticipantRepository::CRITERIA_PARTICIPANT_TYPE_OF_TYPE => $participantTypeOfType,
            EventParticipantRepository::CRITERIA_INCLUDE_DELETED          => $includeDeleted,
            EventParticipantRepository::CRITERIA_EVENT_RECURSIVE_DEPTH    => $depth,
        ];

        return $this->getEventParticipants($opts, $includeNotActivated);
    }

    public function getEventParticipants(
        array $opts = [],
        ?bool $includeNotActivated = true,
        ?int $limit = null,
        ?int $offset = null
    ): Collection {
        return $this->participantRepository->getEventParticipants($opts, $includeNotActivated, $limit, $offset);
    }

    final public function sendInfoMail(EventParticipant $participant, ?string $source = null, ?bool $force = false): bool
    {
        try {
            $event = $participant->getEvent();
            $participantContact = $participant->getContact();
            if (null === $event || null === $participantContact) {
                return false;
            }
            if (!$force && $participant->getInfoMailSentCount() > 0) {
                return false;
            }
            $isOrganization = !($participantContact instanceof Person);
            $contactPersons = $isOrganization ? $participantContact->getContactPersons() : new ArrayCollection([$participantContact]);
            $title = 'Než vyrazíš na '.($event->getName() ?? 'akci');
            $pdfData = [
                'title'            => $title,
                'eventParticipant' => $participant,
                'event'            => $event,
            ];
            $pdfString = null;
            $message = null;
            $contactName = $participant->getContact() ? $participant->getContact()->getName() : 'Nepojmenovaný účastník';
            try {
                $pdfTitle = "Přihláška - $contactName".' ('.$event->getName().')';
                $pdfListConfig = new PdfExportList($pdfTitle, new ArrayCollection(), $pdfData);
                $pdfString = $this->exportService->getPdfAsString($pdfListConfig);
//                $pdfString = $this->exportService->generatePdfAsString(
//                    // $pdfTitle,
//                    '@OswisOrgOswisCalendar/documents/pages/event-participant-info-before-event.html.twig',
//                    // $pdfData,
//                    PdfGenerator::DEFAULT_PAPER_FORMAT,
//                    false,
//                    null,
//                    null
//                );
            } catch (Exception $e) {
                $pdfString = null;
                $message = 'Vygenerování PDF se nezdařilo. '.$e->getMessage();
                $this->logger->error($message);
            }
            $name = u($event->getShortName())->camel()->toString().'_'.u($contactName)->camel()->toString();
            $fileName = 'Shrnuti_'.iconv('utf-8', 'us-ascii//TRANSLIT', $name).'.pdf';
            $remaining = $contactPersons->count();
            foreach ($contactPersons as $contactPerson) {
                assert($contactPerson instanceof Person);
                $email = $this->getEmptyEmail($contactPerson, $title);
                $email->htmlTemplate('@OswisOrgOswisCalendar/e-mail/event-participant-info-before-event.html.twig');
                $email->context($this->getMailData($participant, $event, $contactPerson, $isOrganization));
                if (!empty($pdfString)) {
                    $email->attach($pdfString, $fileName, 'application/pdf');
                }
                try {
                    $this->mailer->send($email);
                    $remaining--;
                    $participant->setInfoMailSent($source);
                    $this->em->persist($participant);
                } catch (TransportExceptionInterface $e) {
                    $this->logger->error($e->getMessage());
                }
            }
            $this->em->flush();
            if ($remaining > 0) {
                throw new OswisException(
                    "Část ověřovacích zpráv se nepodařilo odeslat (chybí $remaining z ".$contactPersons->count().').'
                );
            }

            return true;
        } catch (Exception $e) {
            $this->logger->error($e->getMessage());

            return false;
        }
    }

    /**
     * @param Event       $event
     * @param string|null $participantTypeOfType
     * @param int         $recursiveDepth
     * @param int|null    $startId
     * @param int|null    $endId
     *
     * @return int Remaining (failed) count.
     */
    final public function sendFeedBackMails(
        Event $event,
        ?string $participantTypeOfType = null,
        int $recursiveDepth = 0,
        ?int $startId = 0,
        ?int $endId = null
    ): int {
        $eventParticipants = $this->getEventParticipantsByTypeOfType(
            $event,
            $participantTypeOfType,
            false,
            false,
            $recursiveDepth
        )->filter(fn(EventParticipant $p) => null === $endId || ($p->getId() > $startId && $p->getId() < $endId));
        $remaining = $eventParticipants->count();
        foreach ($eventParticipants as $eventParticipant) {
            $eventParticipants->removeElement($eventParticipant);
            if ($this->sendFeedBackMail($eventParticipant)) {
                $remaining--;
            }
        }

        return $remaining;
    }

    final public function sendFeedBackMail(EventParticipant $participant): bool
    {
        try {
            $event = $participant->getEvent();
            $participantContact = $participant->getContact();
            if (null === $event || null === $participantContact) {
                return false;
            }
            $isOrganization = !($participantContact instanceof Person);
            $contactPersons = $isOrganization ? $participantContact->getContactPersons() : new ArrayCollection([$participantContact]);
            $message = null;
            $remaining = $contactPersons->count();
            foreach ($contactPersons as $contactPerson) {
                assert($contactPerson instanceof Person);
                $email = $this->getEmptyEmail($contactPerson, 'Zpětná vazba');
                $email->htmlTemplate('@OswisOrgOswisCalendar/e-mail/event-participant-feedback.html.twig');
                $email->context($this->getMailData($participant, $event, $contactPerson, $isOrganization));
                try {
                    $this->mailer->send($email);
                    $remaining--;
                } catch (TransportExceptionInterface $e) {
                    $this->logger->error($e->getMessage());
                }
            }
            $this->em->flush();
            if ($remaining > 0) {
                $message = "Část zpětných vazeb se nepodařilo odeslat (chybí $remaining z ".$contactPersons->count().').';
                throw new OswisException($message);
            }

            return true;
        } catch (Exception $e) {
            $this->logger->error($e->getMessage());

            return false;
        }
    }

    public function getEventParticipantFlags(
        Event $event,
        ?EventParticipantType $participantType = null,
        ?EventParticipantFlag $flag = null,
        ?bool $includeDeleted = false,
        ?bool $includeNotActivated = true,
        ?int $recursiveDepth = 1
    ): Collection {
        $flags = $this->getEventParticipantFlagConnections(
            $event,
            $participantType,
            $includeDeleted,
            $includeNotActivated,
            $recursiveDepth
        )->map(
            fn(EventParticipantFlagConnection $connection) => $connection->getEventParticipantFlag()
        );
        if (null !== $flag) {
            return $flags->filter(fn(EventParticipantFlag $f) => $f->getId() === $flag->getId());
        }

        return $flags;
    }

    public function getEventParticipantFlagConnections(
        Event $event,
        ?EventParticipantType $participantType = null,
        ?bool $includeDeleted = false,
        ?bool $includeNotActivated = true,
        ?int $recursiveDepth = null
    ): Collection {
        $connections = new ArrayCollection();
        $opts = [
            EventParticipantRepository::CRITERIA_EVENT                 => $event,
            EventParticipantRepository::CRITERIA_PARTICIPANT_TYPE      => $participantType,
            EventParticipantRepository::CRITERIA_INCLUDE_DELETED       => $includeDeleted,
            EventParticipantRepository::CRITERIA_EVENT_RECURSIVE_DEPTH => $recursiveDepth,
        ];
        $participants = $this->getEventParticipants($opts, $includeNotActivated);
        foreach ($participants as $eventParticipant) {
            assert($eventParticipant instanceof EventParticipant);
            $eventParticipant->getParticipantFlagConnections()->map(
                fn(EventParticipantFlagConnection $flagConn) => !$connections->contains($flagConn) ? $connections->add($flagConn) : null
            );
        }

        return $connections;
    }

    public function getEventWebPartners(array $opts = []): Collection
    {
        $opts[EventParticipantRepository::CRITERIA_PARTICIPANT_TYPE_OF_TYPE] ??= EventParticipantType::TYPE_PARTNER;

        return $this->getEventParticipants($opts)->filter(
            fn(EventParticipant $ep) => $ep->hasFlagOfTypeOfType(EventParticipantFlagType::TYPE_PARTNER_HOMEPAGE)
        );
    }

    /**
     * Array of eventParticipants aggregated by flags (and aggregated by flagTypes).
     *
     * array[flagTypeSlug]['flagType']
     * array[flagTypeSlug]['flags'][flagSlug]['flag']
     * array[flagTypeSlug]['flags'][flagSlug]['participants']
     *
     * @param Event                     $event
     * @param EventParticipantType|null $participantType
     * @param bool|null                 $includeDeleted
     * @param bool|null                 $includeNotActivated
     * @param int|null                  $recursiveDepth Default is 1 for root events, 0 for others.
     *
     * @return array
     */
    final public function getEventParticipantsAggregatedByFlags(
        Event $event,
        ?EventParticipantType $participantType = null,
        ?bool $includeDeleted = false,
        ?bool $includeNotActivated = true,
        ?int $recursiveDepth = 1
    ): array {
        $output = [];
        $recursiveDepth ??= $event->getSuperEvent() ? 0 : 1;
        $opts = [
            EventParticipantRepository::CRITERIA_EVENT                 => $event,
            EventParticipantRepository::CRITERIA_PARTICIPANT_TYPE      => $participantType,
            EventParticipantRepository::CRITERIA_INCLUDE_DELETED       => $includeDeleted,
            EventParticipantRepository::CRITERIA_EVENT_RECURSIVE_DEPTH => $recursiveDepth,
        ];
        $participants = $this->getEventParticipants($opts, $includeNotActivated);
        if ($participantType) {
            foreach ($participants as $participant) {
                assert($participant instanceof EventParticipant);
                foreach ($participant->getParticipantFlagConnections() as $participantFlagConnection) {
                    assert($participantFlagConnection instanceof EventParticipantFlagInEventConnection);
                    $flag = $participantFlagConnection->getEventParticipantFlag();
                    if (null !== $flag) {
                        $flagType = $flag->getEventParticipantFlagType();
                        $flagTypeSlug = $flagType ? $flagType->getSlug() : '';
                        $output[$flagTypeSlug]['flags'][$flag->getSlug()]['participants'][] = $participant;
                        $output[$flagTypeSlug]['flags'][$flag->getSlug()]['flag'] ??= $flag;
                        $output[$flagTypeSlug]['flagType'] ??= $flagType;
                    }
                }
            }
        } else {
            foreach ($participants as $participant) {
                assert($participant instanceof EventParticipant);
                $participantType = $participant->getParticipantType();
                $participantTypeArray = [
                    'id'        => $participantType->getId(),
                    'name'      => $participantType->getName(),
                    'shortName' => $participantType->getShortName(),
                ];
                foreach ($participant->getParticipantFlagConnections() as $participantFlagConnection) {
                    assert($participantFlagConnection instanceof EventParticipantFlagConnection);
                    $flag = $participantFlagConnection->getEventParticipantFlag();
                    if (null !== $flag) {
                        $flagType = $flag->getEventParticipantFlagType();
                        $flagTypeSlug = $flagType ? $flagType->getSlug() : '';
                        $flagArray = [
                            'id'        => $flag->getId(),
                            'slug'      => $flag->getSlug(),
                            'name'      => $flag->getName(),
                            'shortName' => $flag->getShortName(),
                            'color'     => $flag->getColor(),
                        ];
                        $flagTypeArray = [
                            'id'        => $flagType->getId(),
                            'slug'      => $flagType->getSlug(),
                            'name'      => $flagType->getName(),
                            'shortName' => $flagType->getShortName(),
                        ];
                        $output[$participantType->getSlug()]['flagTypes'][$flagTypeSlug]['flags'][$flag->getSlug()]['participants'][] = $participant;
                        if (empty($output[$participantType->getSlug()]['flagTypes'][$flagTypeSlug]['flags'][$flag->getSlug()]['participantsCount'])) {
                            $output[$participantType->getSlug()]['flagTypes'][$flagTypeSlug]['flags'][$flag->getSlug()]['participantsCount'] = 1;
                        } else {
                            $output[$participantType->getSlug()]['flagTypes'][$flagTypeSlug]['flags'][$flag->getSlug()]['participantsCount']++;
                        }
                        $output[$participantType->getSlug()]['flagTypes'][$flagTypeSlug]['flagType'] ??= $flagTypeArray;
                        $output[$participantType->getSlug()]['flagTypes'][$flagTypeSlug]['flags'][$flag->getSlug()]['flag'] ??= $flagArray;
                        $output[$participantType->getSlug()]['participantType'] ??= $participantTypeArray;
                    }
                }
            }
        }

        return $output;
    }

    /**
     * Array of eventParticipants aggregated by flags (and aggregated by flagTypes).
     *
     * array[schoolSlug]['school']
     * array[schoolSlug]['participants'][]
     *
     * @param Event                     $event
     * @param EventParticipantType|null $participantType
     * @param bool|null                 $includeDeleted
     * @param bool|null                 $includeNotActivated
     * @param int|null                  $recursiveDepth Default is 1 for root events, 0 for others.
     *
     * @return array
     * @throws Exception
     */
    final public function getActiveEventParticipantsAggregatedBySchool(
        Event $event,
        ?EventParticipantType $participantType = null,
        ?bool $includeDeleted = false,
        ?bool $includeNotActivated = false,
        ?int $recursiveDepth = null
    ): array {
        $now = new DateTime();
        $recursiveDepth ??= $event->getSuperEvent() ? 0 : 1;
        $output = [];
        $opts = [
            EventParticipantRepository::CRITERIA_EVENT                 => $event,
            EventParticipantRepository::CRITERIA_PARTICIPANT_TYPE      => $participantType,
            EventParticipantRepository::CRITERIA_INCLUDE_DELETED       => $includeDeleted,
            EventParticipantRepository::CRITERIA_EVENT_RECURSIVE_DEPTH => $recursiveDepth,
        ];
        $participants = $this->getEventParticipants($opts, $includeNotActivated);
        if (null !== $participantType) {
            foreach ($participants as $participant) {
                assert($participant instanceof EventParticipant);
                $person = $participant->getContact();
                if ($person instanceof Person) { // Fix for organizations?
                    foreach ($person->getSchools($now) as $school) {
                        assert($school instanceof Organization);
                        $output[$school->getSlug()]['participants'][] = $participant;
                        $output[$school->getSlug()]['school'] ??= $school;
                    }
                }
            }
        } else {
            foreach ($participants as $participant) {
                assert($participant instanceof EventParticipant);
                $participantType = $participant->getParticipantType();
                $person = $participant->getContact();
                if ($person instanceof Person) { // Fix for organizations?
                    foreach ($person->getSchools() as $school) {
                        assert($school instanceof Organization);
                        $output[$participantType->getSlug()]['schools'][$school->getSlug()]['participants'][] = $participant;
                        $output[$participantType->getSlug()]['schools'][$school->getSlug()]['school'] ??= $school;
                        $output[$participantType->getSlug()]['participantType'] ??= $participantType;
                    }
                }
            }
        }

        return $output;
    }
}
