<?php

namespace Zakjakub\OswisCalendarBundle\Manager;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Mpdf\MpdfException;
use Psr\Log\LoggerInterface;
use rikudou\CzQrPayment\QrPayment;
use rikudou\CzQrPayment\QrPaymentOptions;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Exception\LogicException;
use Symfony\Component\Mime\Exception\RfcComplianceException;
use Symfony\Component\Security\Core\Encoder\UserPasswordEncoderInterface;
use Zakjakub\OswisAddressBookBundle\Entity\AbstractClass\AbstractContact;
use Zakjakub\OswisAddressBookBundle\Entity\Organization;
use Zakjakub\OswisAddressBookBundle\Entity\Person;
use Zakjakub\OswisCalendarBundle\Entity\Event\Event;
use Zakjakub\OswisCalendarBundle\Entity\EventAttendeeFlag;
use Zakjakub\OswisCalendarBundle\Entity\EventParticipant\EventParticipant;
use Zakjakub\OswisCalendarBundle\Entity\EventParticipant\EventParticipantType;
use Zakjakub\OswisCoreBundle\Entity\AppUser;
use Zakjakub\OswisCoreBundle\Exceptions\OswisException;
use Zakjakub\OswisCoreBundle\Exceptions\OswisUserNotUniqueException;
use Zakjakub\OswisCoreBundle\Provider\OswisCoreSettingsProvider;
use Zakjakub\OswisCoreBundle\Service\PdfGenerator;
use Zakjakub\OswisCoreBundle\Utils\EmailUtils;
use Zakjakub\OswisCoreBundle\Utils\StringUtils;
use function assert;
use function ucfirst;

/**
 * Class EventParticipantManager
 */
class EventParticipantManager
{
    public const DEFAULT_LIST_TITLE = 'Přehled přihlášek';

    protected EntityManagerInterface $em;

    protected ?LoggerInterface $logger;

    protected ?OswisCoreSettingsProvider $oswisCoreSettings;

    protected MailerInterface $mailer;

    public function __construct(EntityManagerInterface $em, MailerInterface $mailer, OswisCoreSettingsProvider $oswisCoreSettings, ?LoggerInterface $logger)
    {
        $this->em = $em;
        $this->logger = $logger;
        $this->oswisCoreSettings = $oswisCoreSettings;
        $this->mailer = $mailer;
        /// TODO: Encoder, createAppUser...
        /// TODO: Throw exceptions!
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
            $infoMessage = 'CREATE: Created event participant (by manager): ';
            $infoMessage .= $entity->getId().', ';
            $infoMessage .= ($entity->getContact() ? $entity->getContact()->getContactName() : '').', ';
            $infoMessage .= ($entity->getEvent() ? $entity->getEvent()->getName() : '').'.';
            $this->logger ? $this->logger->info($infoMessage) : null;

            return $entity;
        } catch (Exception $e) {
            $this->logger ? $this->logger->info('ERROR: Event event participant not created (by manager): '.$e->getMessage()) : null;

            return null;
        }
    }

    /**
     * Sends summary e-mail if user is already activated or activation e-mail if user is not activated.
     *
     * @param EventParticipant|null             $eventParticipant
     * @param UserPasswordEncoderInterface|null $encoder
     * @param bool                              $new
     * @param string|null                       $token
     *
     * @return bool
     * @throws OswisException
     */
    final public function sendMail(EventParticipant $eventParticipant = null, UserPasswordEncoderInterface $encoder = null, ?bool $new = false, ?string $token = null): bool
    {
        if (!$eventParticipant || !$eventParticipant->getEvent() || !$eventParticipant->getContact()) {
            throw new OswisException('Přihláška není kompletní nebo je poškozená.');
        }
        if ($eventParticipant->isDeleted()) {
            return $eventParticipant->getEMailDeleteConfirmationDateTime() ? true : $this->sendCancelConfirmation($eventParticipant);
        }
        if ($eventParticipant->hasActivatedContactUser()) {
            return $eventParticipant->getEMailConfirmationDateTime() ? true : $this->sendSummary($eventParticipant, $encoder, $new);
        }
        if ($token) {
            foreach ($eventParticipant->getContact()->getContactPersons() as $contactPerson) {
                assert($contactPerson instanceof Person);
                if ($contactPerson->getAppUser() && $contactPerson->getAppUser()->checkAndDestroyAccountActivationRequestToken($token)) {
                    return $this->sendSummary($eventParticipant, $encoder, $new);
                }
            }
        }

        return $this->sendVerification($eventParticipant);
    }

    /**
     * Send confirmation of delete.
     *
     * @param EventParticipant $eventParticipant
     *
     * @return bool
     */
    final public function sendCancelConfirmation(EventParticipant $eventParticipant): bool
    {
        try {
            if (!$eventParticipant || !$eventParticipant->getEvent() || !$eventParticipant->getContact()) {
                return false;
            }
            assert($eventParticipant instanceof EventParticipant);
            $event = $eventParticipant->getEvent();
            assert($event instanceof Event);
            $mailSettings = $this->oswisCoreSettings->getEmail();
            $eventParticipantContact = $eventParticipant->getContact();
            if ($eventParticipantContact instanceof Person) {
                $isOrganization = false;
                $contactPersons = new ArrayCollection([$eventParticipantContact]);
            } else {
                assert($eventParticipantContact instanceof Organization);
                $isOrganization = true;
                $contactPersons = $eventParticipantContact->getContactPersons();
            }
            $title = 'Zrušení přihlášky';
            $mailSuccessCount = 0;
            foreach ($contactPersons as $contactPerson) {
                assert($contactPerson instanceof Person);
                $password = null;
                $name = $contactPerson->getContactName() ?? ($contactPerson->getAppUser() ? $contactPerson->getAppUser()->getFullName() : '');
                $eMail = $contactPerson->getAppUser() ? $contactPerson->getAppUser()->getEmail() : $contactPerson->getEmail();
                $formal = $eventParticipant->getEventParticipantType() ? $eventParticipant->getEventParticipantType()->isFormal() : true;
                $mailData = array(
                    'eventParticipant' => $eventParticipant,
                    'event'            => $event,
                    'contactPerson'    => $contactPerson,
                    'f'                => $formal,
                    'salutationName'   => $contactPerson->getSalutationName(),
                    'a'                => $contactPerson->getCzechSuffixA(),
                    'isOrganization'   => $isOrganization,
                    'oswis'            => $this->oswisCoreSettings->getArray(),
                    'logo'             => 'cid:logo',
                );
                $archiveAddress = new Address($mailSettings['archive_address'] ?? '', self::mimeEnc($mailSettings['archive_name'] ?? '') ?? '');
                $email = new TemplatedEmail();
                $email->to(new Address($eMail ?? '', self::mimeEnc($name ?? '') ?? ''))->bcc($archiveAddress)->subject(self::mimeEnc($title));
                $email->htmlTemplate('@ZakjakubOswisCalendar/e-mail/event-participant-delete.html.twig')->context($mailData);
                /** @noinspection DisconnectedForeachInstructionInspection */
                $this->em->persist($eventParticipant);
                try {
                    $this->mailer->send($email);
                    $mailSuccessCount++;
                } catch (TransportExceptionInterface $e) {
                    $this->logger->error($e->getMessage());
                    // throw new OswisException('Odeslání e-mailu se nezdařilo ('.$e->getMessage().').');
                }
            }
            $this->em->flush();
            if ($mailSuccessCount < $contactPersons->count()) {
                throw new OswisException("Část zpráv se nepodařilo odeslat (odesláno $mailSuccessCount z ".$contactPersons->count().').');
            }

            return true;
        } catch (Exception $e) {
            $this->logger->error('Problém s odesláním potvrzení o zrušení přihlášky. '.$e->getMessage());

            return false;
        }
    }

    private static function mimeEnc(string $content): string
    {
        return EmailUtils::mime_header_encode($content);
    }

    /**
     * Send summary of eventParticipant. Includes appUser info is appUser exist.
     *
     * @param EventParticipant                  $eventParticipant
     * @param bool                              $new
     * @param UserPasswordEncoderInterface|null $encoder
     *
     * @return bool
     * @throws OswisException
     */
    final public function sendSummary(EventParticipant $eventParticipant, UserPasswordEncoderInterface $encoder = null, bool $new = false): bool
    {
        try {
            if (!$eventParticipant || !$eventParticipant->getEvent() || !$eventParticipant->getContact()) {
                return false;
            }
            assert($eventParticipant instanceof EventParticipant);
            $event = $eventParticipant->getEvent();
            assert($event instanceof Event);
            $em = $this->em;
            $mailSettings = $this->oswisCoreSettings->getEmail();
            $eventParticipantContact = $eventParticipant->getContact();
            $qrContactName = $eventParticipantContact ? $eventParticipantContact->getContactName() : '';
            $qrPaymentComment = $qrContactName.', ID '.$eventParticipant->getId().', '.$event->getName();
            $formal = $eventParticipant->getEventParticipantType() ? $eventParticipant->getEventParticipantType()->isFormal() : true;
            try {
                $depositPaymentQr = new QrPayment(
                    $event->getBankAccountNumber(), $event->getBankAccountBank(), [
                        QrPaymentOptions::VARIABLE_SYMBOL => $eventParticipant->getVariableSymbol(),
                        QrPaymentOptions::AMOUNT          => $eventParticipant->getPriceDeposit(),
                        QrPaymentOptions::CURRENCY        => 'CZK',
                        // QrPaymentOptions::DUE_DATE        => date('Y-m-d', strtotime('+5 days')),
                        QrPaymentOptions::COMMENT         => $qrPaymentComment.', záloha',
                    ]
                );
                $depositPaymentQrPng = $depositPaymentQr->getQrImage(true)->writeString();
            } catch (Exception $e) {
                $depositPaymentQr = null;
                $depositPaymentQrPng = null;
            }
            try {
                $restPaymentQr = new QrPayment(
                    $event->getBankAccountNumber(), $event->getBankAccountBank(), [
                        QrPaymentOptions::VARIABLE_SYMBOL => $eventParticipant->getVariableSymbol(),
                        QrPaymentOptions::AMOUNT          => $eventParticipant->getPriceRest(),
                        QrPaymentOptions::CURRENCY        => 'CZK',
                        // QrPaymentOptions::DUE_DATE        => new DateTime('2019-07-31 23:59:59'),
                        QrPaymentOptions::COMMENT         => $qrPaymentComment.', doplatek',
                    ]
                );
                $restPaymentQrPng = $restPaymentQr->getQrImage(true)->writeString();
            } catch (Exception $e) {
                $restPaymentQr = null;
                $restPaymentQrPng = null;
            }
            if ($eventParticipantContact instanceof Person) {
                $isOrganization = false;
                $contactPersons = new ArrayCollection([$eventParticipantContact]);
            } else {
                assert($eventParticipantContact instanceof Organization);
                $isOrganization = true;
                $contactPersons = $eventParticipantContact->getContactPersons();
            }
            $title = 'Změna přihlášky';
            if ($new) {
                $title = 'Shrnutí nové přihlášky';
            }
            $mailSuccessCount = 0;
            foreach ($contactPersons as $contactPerson) {
                assert($contactPerson instanceof Person);
                $password = null;
                $name = $contactPerson->getContactName() ?? ($contactPerson->getAppUser() ? $contactPerson->getAppUser()->getFullName() : '');
                $eMail = $contactPerson->getAppUser() ? $contactPerson->getAppUser()->getEmail() : $contactPerson->getEmail();
                if ($encoder) {
                    $password = StringUtils::generatePassword();
                    $contactPerson->getAppUser()->setPassword($encoder->encodePassword($contactPerson->getAppUser(), $password));
                    $em->flush();
                }
                $mailData = array(
                    'eventParticipant' => $eventParticipant,
                    'event'            => $event,
                    'contactPerson'    => $contactPerson,
                    'f'                => $formal,
                    'salutationName'   => $contactPerson->getSalutationName(),
                    'a'                => $contactPerson->getCzechSuffixA(),
                    'isOrganization'   => $isOrganization,
                    'appUser'          => $eventParticipantContact->getAppUser(),
                    'password'         => $password,
                    'oswis'            => $this->oswisCoreSettings->getArray(),
                    'bankAccount'      => $event->getBankAccountComplete(),
                    'logo'             => 'cid:logo',
                    'depositQr'        => 'cid:depositQr',
                    'restQr'           => 'cid:restQr',
                );
                $archiveAddress = new Address(
                    $mailSettings['archive_address'] ?? '', self::mimeEnc($mailSettings['archive_name'] ?? '') ?? ''
                );
                $email = new TemplatedEmail();
                $email->to(new Address($eMail ?? '', self::mimeEnc($name ?? '') ?? ''));
                $email->bcc($archiveAddress);
                $email->subject(self::mimeEnc($title));
                $email->htmlTemplate('@ZakjakubOswisCalendar/e-mail/event-participant.html.twig');
                $email->context($mailData);
                if ($depositPaymentQrPng) {
                    $email->embed($depositPaymentQrPng, 'depositQr', 'image/png');
                }
                if ($restPaymentQrPng) {
                    $email->embed($restPaymentQrPng, 'restQr', 'image/png');
                }
                try {
                    $this->mailer->send($email);
                    $em->persist($eventParticipant);
                    $mailSuccessCount++;
                } catch (TransportExceptionInterface $e) {
                    $this->logger->error($e->getMessage());
                    $this->logger->error($e->getTraceAsString());
                    throw new OswisException('Odeslání e-mailu se nezdařilo ('.$e->getMessage().').');
                }
            }
            $em->flush();
            if ($mailSuccessCount < $contactPersons->count()) {
                throw new OswisException("Část zpráv se nepodařilo odeslat (odesláno $mailSuccessCount z ".$contactPersons->count().').');
            }

            return true;
        } catch (Exception $e) {
            $this->logger->error($e->getMessage());
            $this->logger->error($e->getTraceAsString());
            throw new OswisException('Problém s odesláním shrnutí přihlášky.  '.$e->getMessage());
        }
    }

    /**
     * @param EventParticipant $eventParticipant
     *
     * @return bool
     * @throws OswisException
     */
    final public function sendVerification(
        EventParticipant $eventParticipant = null
    ): bool {
        try {
            if (!$eventParticipant || !$eventParticipant->getEvent() || !$eventParticipant->getContact()) {
                return false;
            }
            $event = $eventParticipant->getEvent();
            $eventParticipantContact = $eventParticipant->getContact();
            $formal = $eventParticipant->getEventParticipantType() ? $eventParticipant->getEventParticipantType()->isFormal() : true;
            $em = $this->em;
            $mailSettings = $this->oswisCoreSettings->getEmail();
            if ($eventParticipantContact instanceof Person) {
                $isOrganization = false;
                $contactPersons = new ArrayCollection([$eventParticipantContact]);
            } else {
                assert($eventParticipantContact instanceof Organization);
                $isOrganization = true;
                $contactPersons = $eventParticipantContact->getContactPersons();
            }
            $title = 'Ověření přihlášky';
            $mailSuccessCount = 0;
            foreach ($contactPersons as $contactPerson) {
                assert($contactPerson instanceof Person);
                $name = $contactPerson->getContactName() ?? ($contactPerson->getAppUser() ? $contactPerson->getAppUser()->getFullName() : '');
                $eMail = $contactPerson->getAppUser() ? $contactPerson->getAppUser()->getEmail() : $contactPerson->getEmail();
                if (!$contactPerson->getAppUser()) {
                    if (count($em->getRepository(AppUser::class)->findByEmail($eMail)) > 0) {
                        throw new OswisUserNotUniqueException('Zadaný e-mail je již použitý.');
                    }
                    $contactPerson->setAppUser(new AppUser($name, null, $eMail));
                }
                $contactPerson->getAppUser()->generateAccountActivationRequestToken();
                $em->flush();
                $mailData = array(
                    'eventParticipant' => $eventParticipant,
                    'event'            => $event,
                    'contactPerson'    => $contactPerson,
                    'f'                => $formal,
                    'salutationName'   => $contactPerson->getSalutationName(),
                    'a'                => $contactPerson->getCzechSuffixA(),
                    'isOrganization'   => $isOrganization,
                    'logo'             => 'cid:logo',
                    'oswis'            => $this->oswisCoreSettings->getArray(),
                );
                $email = (new TemplatedEmail())->to(new Address($eMail ?? '', self::mimeEnc($name ?? '') ?? ''))->bcc(
                    new Address(
                        $mailSettings['archive_address'] ?? '', self::mimeEnc($mailSettings['archive_name'] ?? '') ?? ''
                    )
                )->subject(self::mimeEnc($title))->htmlTemplate('@ZakjakubOswisCalendar/e-mail/event-participant-verification.html.twig')->context($mailData);
                $em->persist($eventParticipant);
                try {
                    $this->mailer->send($email);
                    $mailSuccessCount++;
                } catch (TransportExceptionInterface $e) {
                    $this->logger->error($e->getMessage());
                    throw new OswisException('Odeslání ověřovacího e-mailu se nezdařilo ('.$e->getMessage().').');
                }
            }
            $em->flush();
            if ($mailSuccessCount < $contactPersons->count()) {
                throw new OswisException("Část ověřovacích zpráv se nepodařilo odeslat (odesláno $mailSuccessCount z ".$contactPersons->count().').');
            }

            return true;
        } catch (Exception $e) {
            $this->logger->error($e->getMessage());
            $this->logger->error($e->getTraceAsString());
            throw new OswisException('Problém s odesláním ověřovacího e-mailu.  '.$e->getMessage());
        }
    }

    /** @noinspection PhpUnused */
    /**
     * Calls method for update of active revision in container.
     */
    final public function updateActiveRevisions(): void
    {
        $eventParticipants = $this->em->getRepository(EventParticipant::class)->findAll();
        foreach ($eventParticipants as $eventParticipant) {
            assert($eventParticipant instanceof EventParticipant);
            // $eventParticipant->updateActiveRevision();
            $eventParticipant->destroyRevisions();
            $this->em->persist($eventParticipant);
        }
        $this->em->flush();
    }

    /**
     * @param PdfGenerator              $pdfGenerator
     * @param Event|null                $event
     * @param EventParticipantType|null $eventParticipantType
     * @param bool                      $detailed
     * @param string                    $title
     *
     * @throws OswisException
     * @throws LogicException
     * @throws RfcComplianceException
     */
    /** @noinspection MethodShouldBeFinalInspection */
    public function sendEventParticipantList(
        PdfGenerator $pdfGenerator,
        Event $event,
        ?EventParticipantType $eventParticipantType = null,
        bool $detailed = false,
        string $title = null
    ): void {
        $templatePdf = '@ZakjakubOswisCalendar/documents/pages/event-participant-list.html.twig';
        $templateEmail = '@ZakjakubOswisCalendar/e-mail/event-participant-list.html.twig';
        if (!$title) {
            $title = self::DEFAULT_LIST_TITLE.' ('.$event->getShortName().')';
        }
        $events = new ArrayCollection([$event]);
        foreach ($event->getSubEvents() as $subEvent) {
            if (!$events->contains($subEvent)) {
                $events->add($subEvent);
            }
        }
        // $events->add($event);
        // $error = '';
        // foreach ($events as $oneEvent) {
        //     $error .= ' (' . $oneEvent->getName() . ') ';
        // }
        // error_log($error);
        $data = [
            'title'                => $title,
            'eventParticipantType' => $eventParticipantType,
            'event'                => $event,
            'events'               => $events,
        ];
        $paper = PdfGenerator::DEFAULT_PAPER_FORMAT;
        $pdfString = null;
        $message = null;
        try {
            $pdfString = $pdfGenerator->generatePdfAsString('Přehled přihlášek', $templatePdf, $data, $paper, $detailed);
        } catch (MpdfException $e) {
            $pdfString = null;
            $message = 'Vygenerování PDF se nezdařilo (MpdfException). '.$e->getMessage().'<br><br>'.$e->getTraceAsString();
        } catch (Exception $e) {
            $pdfString = null;
            $message = 'Vygenerování PDF se nezdařilo (Exception). '.$e->getMessage().'<br><br>'.$e->getTraceAsString();
        }
        $mailData = [
            'data'    => $data,
            'message' => $message,
            'logo'    => 'cid:logo',
            'oswis'   => $this->oswisCoreSettings->getArray(),
        ];
        $mailSettings = $this->oswisCoreSettings->getEmail();
        $archive = new Address(
            $mailSettings['archive_address'] ?? '', self::mimeEnc($mailSettings['archive_name'] ?? '') ?? ''
        );
        $mail = (new TemplatedEmail())->to($archive)->subject(self::mimeEnc($title))->htmlTemplate($templateEmail)->context($mailData);
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
        PdfGenerator $pdfGenerator,
        Event $event,
        ?string $eventParticipantTypeOfType = null,
        int $recursiveDepth = 0,
        ?int $count = 0,
        ?string $source = null,
        ?bool $force = false
    ): int {
        $successCount = 0;
        $eventParticipants = $event->getEventParticipantsByTypeOfType(
            $eventParticipantTypeOfType,
            false,
            false,
            $recursiveDepth
        )->filter(
            static function (EventParticipant $eventParticipant) {
                return $eventParticipant->getInfoMailSentCount() < 1;
            }
        );
        $i = 0;
        foreach ($eventParticipants as $eventParticipant) {
            $eventParticipants->removeElement($eventParticipant);
            if ($this->sendInfoMail($pdfGenerator, $eventParticipant, $source, $force)) {
                $successCount++;
            }
            $i++;
            if ($i >= $count) {
                break;
            }
        }

        return $successCount;
    }

    final public function sendInfoMail(
        PdfGenerator $pdfGenerator,
        EventParticipant $eventParticipant,
        ?string $source = null,
        ?bool $force = false
    ): bool {
        try {
            $em = $this->em;
            $event = $eventParticipant->getEvent();
            $eventParticipantContact = $eventParticipant->getContact();
            if (!$eventParticipant || !$event || !$eventParticipantContact) {
                return false;
            }
            if (!$force && $eventParticipant->getInfoMailSentCount() > 0) {
                return false;
            }
            $formal = $eventParticipant->getEventParticipantType() ? $eventParticipant->getEventParticipantType()->isFormal() : true;
            $mailSettings = $this->oswisCoreSettings->getEmail();
            if ($eventParticipantContact instanceof Person) {
                $isOrganization = false;
                $contactPersons = new ArrayCollection([$eventParticipantContact]);
            } else {
                assert($eventParticipantContact instanceof Organization);
                $isOrganization = true;
                $contactPersons = $eventParticipantContact->getContactPersons();
            }
            $title = 'Než vyrazíš na '.($event->getName() ?? 'akci');
            $pdfData = [
                'title'            => $title,
                'eventParticipant' => $eventParticipant,
                'event'            => $event,
            ];
            $pdfString = null;
            $message = null;
            try {
                $pdfTitle = 'Shrnutí přihlášky';
                $contactName = $eventParticipant->getContact() ? $eventParticipant->getContact()->getContactName() : 'Nepojmenovaný účastník';
                $pdfTitle .= $contactName ? ' - '.$contactName : null;
                $pdfTitle .= $eventParticipant->getEvent() && $eventParticipant->getEvent()->getName() ? ' ('.$eventParticipant->getEvent()->getName().')' : null;
                $pdfString = $pdfGenerator->generatePdfAsString(
                    $pdfTitle,
                    '@ZakjakubOswisCalendar/documents/pages/event-participant-info-before-event.html.twig',
                    $pdfData,
                    PdfGenerator::DEFAULT_PAPER_FORMAT,
                    false,
                    null,
                    null
                );
            } catch (MpdfException $e) {
                $pdfString = null;
                $message = 'Vygenerování PDF se nezdařilo (MpdfException). '.$e->getMessage().'<br><br>'.$e->getTraceAsString();
                $this->logger->error($message);
            } catch (Exception $e) {
                $pdfString = null;
                $message = 'Vygenerování PDF se nezdařilo (Exception). '.$e->getMessage().'<br><br>'.$e->getTraceAsString();
                $this->logger->error($message);
            }
            $fileName = 'Shrnuti_';
            $fileName .= ucfirst(iconv('utf-8', 'us-ascii//TRANSLIT', StringUtils::removeAccents($eventParticipantContact->getFamilyName())));
            $fileName .= ucfirst(iconv('utf-8', 'us-ascii//TRANSLIT', StringUtils::removeAccents($eventParticipantContact->getGivenName()))).'.pdf';
            $mailSuccessCount = 0;
            foreach ($contactPersons as $contactPerson) {
                assert($contactPerson instanceof Person);
                $name = $contactPerson->getContactName() ?? ($contactPerson->getAppUser() ? $contactPerson->getAppUser()->getFullName() : '');
                $eMail = $contactPerson->getAppUser() ? $contactPerson->getAppUser()->getEmail() : $contactPerson->getEmail();
                $mailData = array(
                    'eventParticipant' => $eventParticipant,
                    'event'            => $event,
                    'contactPerson'    => $contactPerson,
                    'f'                => $formal,
                    'salutationName'   => $contactPerson->getSalutationName(),
                    'a'                => $contactPerson->getCzechSuffixA(),
                    'isOrganization'   => $isOrganization,
                    'logo'             => 'cid:logo',
                    'oswis'            => $this->oswisCoreSettings->getArray(),
                );
                $archive = new Address(
                    $mailSettings['archive_address'] ?? '', self::mimeEnc($mailSettings['archive_name'] ?? '') ?? ''
                );
                $to = new Address($eMail ?? '', self::mimeEnc($name ?? '') ?? '');
                $email = (new TemplatedEmail())->to($to)->bcc($archive)->subject(self::mimeEnc($title))->htmlTemplate(
                    '@ZakjakubOswisCalendar/e-mail/event-participant-info-before-event.html.twig'
                )->context($mailData);
                if ($pdfString) {
                    $email->attach($pdfString, $fileName, 'application/pdf');
                }
                try {
                    $this->mailer->send($email);
                    $mailSuccessCount++;
                    $eventParticipant->setInfoMailSent($source);
                    $em->persist($eventParticipant);
                } catch (TransportExceptionInterface $e) {
                    $this->logger->error($e->getMessage());
                    throw new OswisException('Odeslání ověřovacího e-mailu se nezdařilo ('.$e->getMessage().').');
                }
            }
            $em->flush();
            if ($mailSuccessCount < $contactPersons->count()) {
                throw new OswisException("Část ověřovacích zpráv se nepodařilo odeslat (odesláno $mailSuccessCount z ".$contactPersons->count().').');
            }

            return true;
        } catch (Exception $e) {
            $this->logger->error($e->getMessage());
            $this->logger->error($e->getTraceAsString());

            return false;
        }
    }

    final public function sendFeedBackMails(
        Event $event,
        ?string $eventParticipantTypeOfType = null,
        int $recursiveDepth = 0,
        ?int $startId = 0,
        ?int $endId = 0
    ): int {
        $successCount = 0;
        $eventParticipants = $event->getEventParticipantsByTypeOfType(
            $eventParticipantTypeOfType,
            false,
            false,
            $recursiveDepth
        )->filter(
            static function (EventParticipant $eventParticipant) use ($startId, $endId) {
                return $eventParticipant->getId() > $startId && $eventParticipant->getId() < $endId;
            }
        );
        foreach ($eventParticipants as $eventParticipant) {
            $eventParticipants->removeElement($eventParticipant);
            if ($this->sendFeedBackMail($eventParticipant)) {
                $successCount++;
            }
        }

        return $successCount;
    }

    final public function sendFeedBackMail(EventParticipant $eventParticipant): bool
    {
        try {
            $em = $this->em;
            $event = $eventParticipant->getEvent();
            $eventParticipantContact = $eventParticipant->getContact();
            if (!$eventParticipant || !$event || !$eventParticipantContact) {
                return false;
            }
            $formal = $eventParticipant->getEventParticipantType() ? $eventParticipant->getEventParticipantType()->isFormal() : true;
            $mailSettings = $this->oswisCoreSettings->getEmail();
            if ($eventParticipantContact instanceof Person) {
                $isOrganization = false;
                $contactPersons = new ArrayCollection([$eventParticipantContact]);
            } else {
                assert($eventParticipantContact instanceof Organization);
                $isOrganization = true;
                $contactPersons = $eventParticipantContact->getContactPersons();
            }
            $title = 'Zpětná vazba';
            $message = null;
            $mailSuccessCount = 0;
            foreach ($contactPersons as $contactPerson) {
                assert($contactPerson instanceof Person);
                $name = $contactPerson->getContactName() ?? ($contactPerson->getAppUser() ? $contactPerson->getAppUser()->getFullName() : '');
                $eMail = $contactPerson->getAppUser() ? $contactPerson->getAppUser()->getEmail() : $contactPerson->getEmail();
                $mailData = array(
                    'eventParticipant' => $eventParticipant,
                    'event'            => $event,
                    'contactPerson'    => $contactPerson,
                    'f'                => $formal,
                    'salutationName'   => $contactPerson->getSalutationName(),
                    'a'                => $contactPerson->getCzechSuffixA(),
                    'isOrganization'   => $isOrganization,
                    'logo'             => 'cid:logo',
                    'oswis'            => $this->oswisCoreSettings->getArray(),
                );
                $archive = new Address(
                    $mailSettings['archive_address'] ?? '', self::mimeEnc($mailSettings['archive_name'] ?? '') ?? ''
                );
                $to = new Address($eMail ?? '', self::mimeEnc($name ?? '') ?? '');
                $email = (new TemplatedEmail())->to($to)->bcc($archive)->subject(self::mimeEnc($title))->htmlTemplate(
                    '@ZakjakubOswisCalendar/e-mail/event-participant-feedback.html.twig'
                )->context($mailData);
                try {
                    $email->attachFromPath('../assets/assets/le.pdf', 'le-voucher.pdf', 'application/pdf');
                    $this->mailer->send($email);
                    $mailSuccessCount++;
                } catch (TransportExceptionInterface $e) {
                    $this->logger->error($e->getMessage());
                    throw new OswisException('Odeslání zpětné vazby se nezdařilo ('.$e->getMessage().').');
                }
            }
            $em->flush();
            if ($mailSuccessCount < $contactPersons->count()) {
                throw new OswisException("Část zpětných vazeb se nepodařilo odeslat (odesláno $mailSuccessCount z ".$contactPersons->count().').');
            }

            return true;
        } catch (Exception $e) {
            $this->logger->error($e->getMessage());
            $this->logger->error($e->getTraceAsString());

            return false;
        }
    }
}
