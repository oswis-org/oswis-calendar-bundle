<?php

namespace Zakjakub\OswisCalendarBundle\Manager;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Psr\Log\LoggerInterface;
use rikudou\CzQrPayment\QrPayment;
use rikudou\CzQrPayment\QrPaymentOptions;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\NamedAddress;
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
use Zakjakub\OswisCoreBundle\Exceptions\RevisionMissingException;
use Zakjakub\OswisCoreBundle\Provider\OswisCoreSettingsProvider;
use Zakjakub\OswisCoreBundle\Utils\EmailUtils;
use Zakjakub\OswisCoreBundle\Utils\StringUtils;
use function assert;

/**
 * Class EventParticipantManager
 * @package Zakjakub\OswisCalendarBundle\Manager
 */
class EventParticipantManager
{
    /**
     * @var EntityManagerInterface
     */
    protected $em;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @var OswisCoreSettingsProvider
     */
    protected $oswisCoreSettings;

    /**
     * @var MailerInterface|null
     */
    protected $mailer;

    /**
     * EventParticipantManager constructor.
     *
     * @param EntityManagerInterface         $em
     * @param MailerInterface                $mailer
     * @param OswisCoreSettingsProvider|null $oswisCoreSettings
     * @param LoggerInterface|null           $logger
     */
    public function __construct(
        EntityManagerInterface $em,
        MailerInterface $mailer,
        OswisCoreSettingsProvider $oswisCoreSettings,
        ?LoggerInterface $logger = null
    ) {
        $this->em = $em;
        $this->logger = $logger;
        $this->oswisCoreSettings = $oswisCoreSettings;
        $this->mailer = $mailer;
        /// TODO: Encoder, createAppUser...
        /// TODO: Throw exceptions!
    }

    /**
     * @param AbstractContact|null      $contact
     * @param Event|null                $event
     * @param EventParticipantType|null $eventParticipantType
     * @param Collection|null           $eventContactFlagConnections
     * @param Collection|null           $eventParticipantNotes
     *
     * @return EventParticipant
     */
    final public function create(
        ?AbstractContact $contact = null,
        ?Event $event = null,
        ?EventParticipantType $eventParticipantType = null,
        ?Collection $eventContactFlagConnections = null,
        ?Collection $eventParticipantNotes = null
    ): EventParticipant {
        try {
            $em = $this->em;
            $entity = new EventParticipant($contact, $event, $eventParticipantType, $eventContactFlagConnections, $eventParticipantNotes);
            $em->persist($entity);
            $em->flush();
            $infoMessage = 'CREATE: Created event participant (by manager): '.$entity->getId()
                .', '.$entity->getContact()->getContactName()
                .', '.($entity->getEvent() ? $entity->getEvent()->getName() : '').'.';
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
     *
     * @param bool                              $new
     * @param string|null                       $token
     *
     * @return bool
     * @throws OswisException
     * @throws RevisionMissingException
     */
    final public function sendMail(
        EventParticipant $eventParticipant = null,
        UserPasswordEncoderInterface $encoder = null,
        ?bool $new = false,
        ?string $token = null
    ): bool {
        if (!$eventParticipant || !$eventParticipant->getEvent() || !$eventParticipant->getContact()) {
            return false;
        }
        if ($eventParticipant->hasActivatedContactUser()) {
            return $this->sendSummary($eventParticipant, $encoder, $new);
        }

        if ($token) {
            foreach ($eventParticipant->getContact()->getContactPersons() as $contactPerson) {
                assert($contactPerson instanceof Person);
                if ($contactPerson->getAppUser() && $contactPerson->getAppUser()->checkAndDestroyAccountActivationRequestToken($token)) {
                    $this->sendSummary($eventParticipant, $encoder, $new);
                    break;
                }
            }
        }

        return $this->sendVerification($eventParticipant);
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
    final public function sendSummary(
        EventParticipant $eventParticipant,
        UserPasswordEncoderInterface $encoder = null,
        bool $new = false
    ): bool {
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
            $qrPaymentComment = $eventParticipantContact->getContactName().', ID '.$eventParticipant->getId().', '.$event->getName();

            $depositPaymentQr = new QrPayment(
                $event->getBankAccountNumber(), $event->getBankAccountNumber(),
                [
                    QrPaymentOptions::VARIABLE_SYMBOL => $eventParticipant->getVariableSymbol(),
                    QrPaymentOptions::AMOUNT          => $eventParticipant->getPriceDeposit(),
                    QrPaymentOptions::CURRENCY        => 'CZK',
                    // QrPaymentOptions::DUE_DATE        => date('Y-m-d', strtotime('+5 days')),
                    QrPaymentOptions::COMMENT         => $qrPaymentComment.', záloha',
                ]
            );
            $restPaymentQr = new QrPayment(
                $event->getBankAccountNumber(), $event->getBankAccountNumber(),
                [
                    QrPaymentOptions::VARIABLE_SYMBOL => $eventParticipant->getVariableSymbol(),
                    QrPaymentOptions::AMOUNT          => $eventParticipant->getPriceRest(),
                    QrPaymentOptions::CURRENCY        => 'CZK',
                    // QrPaymentOptions::DUE_DATE        => new DateTime('2019-07-31 23:59:59'),
                    QrPaymentOptions::COMMENT         => $qrPaymentComment.', doplatek',
                ]
            );
            /** @noinspection PhpUndefinedMethodInspection */
            $depositPaymentQrPng = $depositPaymentQr->getQrImage(true)->writeString();
            /** @noinspection PhpUndefinedMethodInspection */
            $restPaymentQrPng = $restPaymentQr->getQrImage(true)->writeString();

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

                $archiveAddress = new NamedAddress(
                    $mailSettings['archive_address'] ?? '',
                    self::mimeEnc($mailSettings['archive_name'] ?? '') ?? ''
                );

                $email = (new TemplatedEmail())
                    ->to(new NamedAddress($eMail ?? '', self::mimeEnc($name ?? '') ?? ''))
                    ->bcc($archiveAddress)
                    ->subject(self::mimeEnc($title))
                    ->htmlTemplate('@ZakjakubOswisCalendar/e-mail/event-participant.html.twig')
                    ->embed($depositPaymentQrPng, 'depositQr')
                    ->embed($restPaymentQrPng, 'restQr')
                    ->context($mailData);
                $em->persist($eventParticipant);
                try {
                    $this->mailer->send($email);
                    $mailSuccessCount++;
                } catch (TransportExceptionInterface $e) {
                    $this->logger->error($e->getMessage());
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
            throw new OswisException('Problém s odesláním shrnutí přihlášky.  '.$e->getMessage());
        }
    }

    private static function mimeEnc(string $content): string
    {
        return EmailUtils::mime_header_encode($content);
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
                    if ($em->getRepository(AppUser::class)->findByEmail($eMail)->count() > 0) {
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
                    'salutationName'   => $contactPerson->getSalutationName(),
                    'a'                => $contactPerson->getCzechSuffixA(),
                    'isOrganization'   => $isOrganization,
                    'logo'             => 'cid:logo',
                    'oswis'            => $this->oswisCoreSettings->getArray(),
                );

                $email = (new TemplatedEmail())
                    ->to(new NamedAddress($eMail ?? '', self::mimeEnc($name ?? '') ?? ''))
                    ->bcc(
                        new NamedAddress(
                            $mailSettings['archive_address'] ?? '',
                            self::mimeEnc($mailSettings['archive_name'] ?? '') ?? ''
                        )
                    )
                    ->subject(self::mimeEnc($title))
                    ->htmlTemplate('@ZakjakubOswisCalendar/e-mail/event-participant-verification.html.twig')
                    ->context($mailData);
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

    /**
     * @param EventParticipant|null $eventParticipant
     *
     * @throws OswisException
     */
    final public function checkEventParticipantCompleteness(?EventParticipant $eventParticipant): void
    {
        try {
            if ($eventParticipant && $eventParticipant->getEvent() && $eventParticipant->getContact()) {
                return;
            }
        } catch (RevisionMissingException $exception) {
            throw new OswisException('Nastal problém s přihláškou (revize nebyla nalezena).');
        }

        throw new OswisException('Přihláška není kompletní.');
    }

    /**
     * Send confirmation of delete.
     *
     * @param EventParticipant $eventParticipant
     *
     * @return bool
     * @throws OswisException
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
            $em = $this->em;
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

                $mailData = array(
                    'eventParticipant' => $eventParticipant,
                    'event'            => $event,
                    'contactPerson'    => $contactPerson,
                    'salutationName'   => $contactPerson->getSalutationName(),
                    'a'                => $contactPerson->getCzechSuffixA(),
                    'isOrganization'   => $isOrganization,
                    'oswis'            => $this->oswisCoreSettings->getArray(),
                    'logo'             => 'cid:logo',
                );

                $archiveAddress = new NamedAddress(
                    $mailSettings['archive_address'] ?? '',
                    self::mimeEnc($mailSettings['archive_name'] ?? '') ?? ''
                );

                $email = (new TemplatedEmail())
                    ->to(new NamedAddress($eMail ?? '', self::mimeEnc($name ?? '') ?? ''))
                    ->bcc($archiveAddress)
                    ->subject(self::mimeEnc($title))
                    ->htmlTemplate('@ZakjakubOswisCalendar/e-mail/event-participant-delete.html.twig')
                    ->context($mailData);
                $em->persist($eventParticipant);
                try {
                    $this->mailer->send($email);
                    $mailSuccessCount++;
                } catch (TransportExceptionInterface $e) {
                    $this->logger->error($e->getMessage());
                    // throw new OswisException('Odeslání e-mailu se nezdařilo ('.$e->getMessage().').');
                }
            }
            $em->flush();
            if ($mailSuccessCount < $contactPersons->count()) {
                throw new OswisException("Část zpráv se nepodařilo odeslat (odesláno $mailSuccessCount z ".$contactPersons->count().').');
            }

            return true;
        } catch (Exception $e) {
            $this->logger->error($e->getMessage());
            throw new OswisException('Problém s odesláním potvrzení o zrušení přihlášky.  '.$e->getMessage());
        }
    }

    final public function updateActiveRevisions(): void
    {
        $eventParticipants = $this->em->getRepository(EventParticipant::class)->findAll();
        foreach ($eventParticipants as $eventParticipant) {
            assert($eventParticipant instanceof EventParticipant);
            $eventParticipant->updateActiveRevision();
        }
        $this->em->flush();
    }


}
