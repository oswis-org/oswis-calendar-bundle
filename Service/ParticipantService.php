<?php
/**
 * @noinspection PhpUnused
 * @noinspection PhpUnusedParameterInspection
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
use OswisOrg\OswisAddressBookBundle\Entity\ContactNote;
use OswisOrg\OswisAddressBookBundle\Entity\Organization;
use OswisOrg\OswisAddressBookBundle\Entity\Person;
use OswisOrg\OswisCalendarBundle\Entity\Event\Event;
use OswisOrg\OswisCalendarBundle\Entity\Event\RegistrationsRange;
use OswisOrg\OswisCalendarBundle\Entity\EventAttendeeFlag;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantFlag;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantFlagRange;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantFlagRangeConnection;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantFlagType;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantType;
use OswisOrg\OswisCalendarBundle\Exception\EventCapacityExceededException;
use OswisOrg\OswisCalendarBundle\Repository\ParticipantRepository;
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

class ParticipantService
{
    public const DEFAULT_LIST_TITLE = 'Přehled přihlášek';

    protected EntityManagerInterface $em;

    protected ?LoggerInterface $logger;

    protected ?OswisCoreSettingsProvider $coreSettings;

    protected MailerInterface $mailer;

    protected AppUserService $appUserService;

    protected ExportService $exportService;

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
        $this->encoder = $encoder;
    }

    /**
     * @param Participant $participant
     * @param array|null  $flags
     * @param bool        $onlyPublic
     * @param bool        $max
     *
     * @return Participant
     * @throws OswisException|EventCapacityExceededException
     */
    public function create(?Participant $participant, ?array $flags = null, bool $onlyPublic = false, bool $max = false): Participant
    {
        if (null === $participant || !($participant instanceof Participant)) {
            throw new OswisException('Přihláška není kompletní nebo je poškozená.');
        }
        if (null === ($range = $participant->getRange())) {
            throw new OswisException('Přihláška není kompletní nebo je poškozená. Není vybrán žádný rozsah přihlášek.');
        }
        if (null === ($event = $participant->getEvent())) {
            throw new OswisException('Přihláška není kompletní nebo je poškozená. Není vybrána žádná událost.');
        }
        if (null === ($contact = $participant->getContact())) {
            throw new OswisException('Přihláška není kompletní nebo je poškozená. V přihlášce chybí kontakt.');
        }
        $eventName = $event->getName();
        $this->removeEmptyNotesAndDetails($participant, $contact);
        $contact->addNote(new ContactNote("Vytvořeno k přihlášce na akci ($eventName)."));
        $this->checkParticipant($range, $participant, $flags, $onlyPublic, $max);
        $this->processFlagsAdd($participant, $range, $flags, $onlyPublic, $max);
        $this->em->persist($participant);
        $participant->updateCachedColumns();
        $mailSent = $this->sendMail($participant, true);
        $this->em->flush();
        $infoMessage = $this->getLogMessage($participant).' Mail '.(!$mailSent ? 'NOT' : '').' sent.';
        $this->logger->info($infoMessage);

        return $participant;
    }

    public function removeEmptyNotesAndDetails(Participant $participant, AbstractContact $contact): void
    {
        $participant->removeEmptyParticipantNotes();
        $contact->removeEmptyDetails();
        $contact->removeEmptyNotes();
    }

    /**
     * @param RegistrationsRange $range
     * @param Participant        $participant
     * @param array              $selectedFlags
     * @param bool               $onlyPublic
     * @param bool               $max
     *
     * @throws EventCapacityExceededException
     */
    public function checkParticipant(
        RegistrationsRange $range,
        Participant $participant,
        ?array $selectedFlags = null,
        bool $onlyPublic = true,
        bool $max = false
    ): void {
        $this->checkParticipantSuperEvent($range, $participant);
        if (is_array($selectedFlags)) {
            $range->simulateFlagsAdd($selectedFlags, $onlyPublic, $max);
        }
    }

    /**
     * @param RegistrationsRange $range
     * @param Participant        $participant
     *
     * @throws EventCapacityExceededException
     */
    public function checkParticipantSuperEvent(RegistrationsRange $range, Participant $participant): void
    {
        if (true === $range->isSuperEventRequired()) {
            $included = false;
            $participantsOfContact = $this->getParticipants(
                [
                    ParticipantRepository::CRITERIA_CONTACT         => $participant->getContact(),
                    ParticipantRepository::CRITERIA_INCLUDE_DELETED => false,
                ]
            );
            foreach ($participantsOfContact as $participantOfContact) {
                if ($participantOfContact instanceof Participant && $range->isParticipantInSuperEvent($participantOfContact)) {
                    $included = true;
                }
            }
            if (!$included) {
                throw new EventCapacityExceededException('Pro přihlášku v tomto rozsahu je nutné se zúčastnit i nadřazené akce.');
            }
        }
    }

    public function getParticipants(
        array $opts = [],
        ?bool $includeNotActivated = true,
        ?int $limit = null,
        ?int $offset = null
    ): Collection {
        return $this->getRepository()->getParticipants($opts, $includeNotActivated, $limit, $offset);
    }

    final public function getRepository(): ParticipantRepository
    {
        $repository = $this->em->getRepository(Participant::class);
        assert($repository instanceof ParticipantRepository);

        return $repository;
    }

    public function processFlagsAdd(
        Participant $participant,
        RegistrationsRange $range,
        ?array $flagsAggregatedByType = null,
        bool $onlyPublic = false,
        bool $max = false
    ): void {
        foreach ($flagsAggregatedByType as $flagsOfType) {
            foreach ($flagsOfType as $aggregatedFlag) {
                if ($flag = ($aggregatedFlag['flag'] instanceof ParticipantFlag ? $aggregatedFlag['flag'] : null)) {
                    for ($index = 0; $index < ($aggregatedFlag['count'] ?? 0); $index++) {
                        $flagRange = $range->getFlagRange($flag, $max, $onlyPublic);
                        $participant->addFlagRangeConnection(new ParticipantFlagRangeConnection($flagRange));
                    }
                }
            }
        }
    }

    /**
     * Sends summary e-mail (if user is already activated) or activation e-mail (if user is not activated).
     *
     * @param Participant|null $participant
     * @param bool             $new
     * @param string|null      $token
     *
     * @return bool
     * @throws OswisException
     */
    final public function sendMail(Participant $participant = null, ?bool $new = false, ?string $token = null): bool
    {
        if (!$participant || !$participant->getRange() || !$participant->getContact()) {
            throw new OswisException('Přihláška není kompletní nebo je poškozená.');
        }
        if ($participant->isDeleted()) {
            return $this->sendCancelConfirmations($participant);
        }
        if (!empty($token) || $participant->hasActivatedContactUser()) {
            return $this->doVerificationAndSendSummary($participant, $new, $token);
        }

        return $this->sendVerifications($participant);
    }

    final public function sendCancelConfirmations(Participant $participant): bool
    {
        try {
            $range = $participant->getRange();
            $event = $participant->getEvent();
            $participantContact = $participant->getContact();
            if (null === $range || null === $event || null === $participantContact) {
                return false;
            }
            $contactPersons = $participant->getContactPersons();
            $remaining = $contactPersons->count();
            foreach ($contactPersons as $contactPerson) {
                if (($contactPerson instanceof Person)) {
                    $remaining -= $this->sendCancelConfirmation($contactPerson, $participant, $event) ? 1 : 0;
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
     * @param Person      $person
     * @param Participant $participant
     * @param Event       $event
     *
     * @return bool
     * @throws LogicException
     * @throws RfcComplianceException
     */
    public function sendCancelConfirmation(Person $person, Participant $participant, Event $event): bool
    {
        $email = $this->getEmptyEmail($person, 'Zrušení přihlášky');
        $email->htmlTemplate('@OswisOrgOswisCalendar/e-mail/event-participant-delete.html.twig');
        $email->context($this->getMailData($participant, $event, $person, false));
        $this->em->persist($participant);
        try {
            $this->mailer->send($email);

            return true;
        } catch (TransportExceptionInterface $e) {
            $this->logger->error($e->getMessage());

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

    private function getMailData(Participant $participant, Event $event, Person $contactPerson, bool $isOrg = false): array
    {
        return [
            'participant'    => $participant,
            'event'          => $event,
            'contactPerson'  => $contactPerson,
            'f'              => $participant->isFormal(),
            'salutationName' => $contactPerson->getSalutationName(),
            'a'              => $contactPerson->getCzechSuffixA(),
            'isOrganization' => $isOrg,
            'oswis'          => $this->coreSettings->getArray(),
        ];
    }

    /**
     * @param Participant $participant
     * @param bool        $new
     * @param string|null $token
     *
     * @return bool
     * @throws OswisException
     */
    public function doVerificationAndSendSummary(Participant $participant, bool $new, ?string $token = null): bool
    {
        $result = false;
        foreach ($participant->getContactPersons() as $person) {
            if (!($person instanceof Person) || (!$participant->hasActivatedContactUser() && (empty($token) || null === $person->getAppUser()))) {
                continue;
            }
            if ($participant->hasActivatedContactUser() || $person->getAppUser()->activateByToken($token)) {
                if ($this->sendSummaries($participant, $new)) {
                    $result = true;
                }
            }
        }

        return $result;
    }

    /**
     * Send summary of participant. Includes user info if user exist.
     *
     * @param Participant $participant
     * @param bool        $new
     *
     * @return bool
     * @throws OswisException
     */
    final public function sendSummaries(Participant $participant, bool $new = false): bool
    {
        try {
            $contactPersons = $participant->getContactPersons();
            $remaining = $contactPersons->count();
            foreach ($contactPersons as $contactPerson) {
                if ($contactPerson instanceof Person) {
                    $remaining -= $this->sendSummary($contactPerson, $participant, $participant->getEvent(), $new) ? 1 : 0;
                }
            }
            $this->em->flush();
            if ($remaining > 0) {
                throw new OswisException("Část zpráv se nepodařilo odeslat (chybí $remaining z ".$contactPersons->count().').');
            }

            return true;
        } catch (Exception $exception) {
            $this->logger->error($exception->getMessage());
            throw new OswisException('Problém s odesláním shrnutí přihlášky. '.$exception->getMessage());
        }
    }

    /**
     * @param Person      $person
     * @param Participant $participant
     * @param Event       $event
     * @param bool        $new
     *
     * @return bool
     */
    public function sendSummary(Person $person, Participant $participant, Event $event, bool $new): bool
    {
        try {
            $password = null;
            if (null !== $person->getAppUser() && empty($person->getAppUser()->getPassword())) {
                $password = StringUtils::generatePassword();
                $person->getAppUser()->setPassword($this->encoder->encodePassword($person->getAppUser(), $password));
                $this->em->flush();
            }
            $participantContact = $participant->getContact();
            $participantContactAppUser = $participantContact ? $participantContact->getAppUser() : null;
            $participantContactSlug = $participantContact ? $participantContact->getSlug() : null;
            $mailData = $this->getMailData($participant, $event, $person, $person->isOrganization());
            $mailData['appUser'] = $participantContactAppUser;
            $mailData['password'] = $password;
            $mailData['bankAccount'] = $event->getBankAccountComplete();
            $email = $this->getEmptyEmail($person, !$new ? 'Změna přihlášky' : 'Shrnutí nové přihlášky');
            $qrComment = "$participantContactSlug, ID ".$participant->getId().', akce '.$event->getId();
            foreach (['depositQr' => true, 'restQr' => false] as $key => $isDeposit) {
                if ($qrPng = self::getQrPng($event, $participant, $qrComment, $isDeposit)) {
                    $email->embed($qrPng, $key, 'image/png');
                    $mailData[$key] = "cid:$key";
                }
            }
            $email->htmlTemplate('@OswisOrgOswisCalendar/e-mail/event-participant.html.twig')->context($mailData);
            $this->mailer->send($email);
            $participant->setMailConfirmationSend('event-participant-service');
            $this->em->persist($participant);

            return true;
        } catch (TransportExceptionInterface | LogicException | RfcComplianceException $exception) {
            $this->logger->error($exception->getMessage());

            return false;
        }
    }

    private static function getQrPng(Event $event, Participant $participant, string $qrComment, bool $isDeposit): ?string
    {
        try {
            return (new QrPayment(
                $event->getBankAccountNumber(), $event->getBankAccountBank(), [
                    QrPaymentOptions::VARIABLE_SYMBOL => $participant->getVariableSymbol(),
                    QrPaymentOptions::AMOUNT          => $isDeposit ? $participant->getDepositValue() : $participant->getPriceRest(),
                    QrPaymentOptions::CURRENCY        => 'CZK',
                    QrPaymentOptions::COMMENT         => $qrComment.', '.($isDeposit ? 'záloha' : 'doplatek'),
                ]
            ))->getQrImage(true)->writeString();
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * @param Participant $participant
     *
     * @return bool
     * @throws OswisException
     */
    final public function sendVerifications(Participant $participant): bool
    {
        try {
            $event = $participant->getEvent();
            if (null === $event) {
                return false;
            }
            $participantId = $participant->getId();
            $contactPersons = $participant->getContactPersons(null, false);
            $required = $contactPersons->count();
            $remaining = $contactPersons->count();
            $this->em->persist($participant);
            foreach ($contactPersons as $contactPerson) {
                if ($contactPerson instanceof Person) {
                    $remaining -= $this->sendVerification($contactPerson, $participant, $event, true) ? 1 : 0;
                }
            }
            if ($remaining > 0) {
                $message = "Část ověřovacích zpráv se nepodařilo odeslat (chybí $remaining z $required) (přihláška $participantId).";
                throw new OswisException($message);
            }
            $this->logger->info("Odesláno $required ověřovacích zpráv k přihlášce $participantId.");

            return true;
        } catch (Exception $e) {
            $this->logger->error($e->getMessage());
            throw new OswisException('Problém s odesláním ověřovacího e-mailu. '.$e->getMessage());
        }
    }

    /**
     * @param Person      $person
     * @param Participant $participant
     * @param Event       $event
     * @param bool        $new
     *
     * @return bool
     * @throws LogicException|RfcComplianceException
     * @throws OswisException|OswisUserNotUniqueException
     */
    public function sendVerification(Person $person, Participant $participant, Event $event, bool $new): bool
    {
        $appUserRepository = $this->appUserService->getRepository();
        if (null === $person->getAppUser()) {
            if (count($appUserRepository->findByEmail($person->getEmail())) > 0) {
                throw new OswisUserNotUniqueException('Zadaný e-mail je již použitý.');
            }
            $person->setAppUser(new AppUser($person->getName(), null, $person->getEmail()));
        }
        $person->getAppUser()->generateActivationRequestToken();
        $email = $this->getEmptyEmail($person, 'Ověření přihlášky');
        $email->context($this->getMailData($participant, $event, $person, $person->isOrganization()));
        $email->htmlTemplate('@OswisOrgOswisCalendar/e-mail/event-participant-verification.html.twig');
        try {
            $this->em->flush();
            $this->mailer->send($email);
            $this->em->flush();

            return true;
        } catch (TransportExceptionInterface $e) {
            $this->logger->error($e->getMessage());
            throw new OswisException('Odeslání ověřovacího e-mailu se nezdařilo ('.$e->getMessage().').');
        }
    }

    private function getLogMessage(Participant $participant): string
    {
        $infoMessage = 'CREATE: Created participant (by service) with ';
        $infoMessage .= 'ID ['.$participant->getId().']';
        $infoMessage .= ' and contact name ';
        $infoMessage .= '['.($participant->getContact() ? $participant->getContact()->getName() : '').']';
        try {
            $infoMessage .= ' to range ['.($participant->getRange() ? $participant->getRange()->getName() : '').'].';
        } catch (OswisException $e) {
        }

        return $infoMessage;
    }

    /**
     * @param Participant $participant
     * @param string|null $token
     *
     * @return bool
     * @throws OswisException
     */
    public function verify(Participant $participant, ?string $token = null): bool
    {
        $this->removeEmptyNotesAndDetails($participant, $participant->getContact());

        return $this->sendMail($participant, true, $token);
    }

    /**
     * Checks if contact is participant in event as given participantType.
     *
     * @param Event                $event
     * @param AbstractContact      $contact
     * @param ParticipantType|null $participantType
     *
     * @return bool
     */
    public function isContactContainedInEvent(Event $event, AbstractContact $contact, ParticipantType $participantType = null): bool
    {
        $opts = [
            ParticipantRepository::CRITERIA_EVENT            => $event,
            ParticipantRepository::CRITERIA_PARTICIPANT_TYPE => $participantType,
            ParticipantRepository::CRITERIA_CONTACT          => $contact,
        ];

        return $this->getRepository()->getParticipants($opts)->count() > 0;
    }

    final public function getOrganizer(Event $event): ?AbstractContact
    {
        $organizer = $this->getOrganizers($event)->map(fn(Participant $p) => $p->getContact())->first() ?: null;
        if (null === $organizer) {
            $organizer = $event->getSuperEvent() ? $this->getOrganizer($event->getSuperEvent()) : null;
        }

        return $organizer;
    }

    final public function getOrganizers(Event $event): Collection
    {
        return $this->getParticipantsByTypeString($event, ParticipantType::TYPE_ORGANIZER);
    }

    public function getParticipantsByTypeString(
        ?Event $event = null,
        ?string $participantTypeString = ParticipantType::TYPE_ATTENDEE,
        ?bool $includeDeleted = false,
        ?bool $includeNotActivated = true,
        ?int $depth = 1
    ): Collection {
        return $this->getParticipants(
            [
                ParticipantRepository::CRITERIA_EVENT                   => $event,
                ParticipantRepository::CRITERIA_PARTICIPANT_TYPE_STRING => $participantTypeString,
                ParticipantRepository::CRITERIA_INCLUDE_DELETED         => $includeDeleted,
                ParticipantRepository::CRITERIA_EVENT_RECURSIVE_DEPTH   => $depth,
            ],
            $includeNotActivated
        );
    }

    /**
     * @param Event                $event
     * @param ParticipantType|null $participantType
     * @param bool                 $detailed
     * @param string               $title
     *
     * @param int|null             $recursiveDepth
     *
     * @throws LogicException
     * @throws OswisException
     * @throws RfcComplianceException
     */
    public function sendParticipantList(
        Event $event,
        ?ParticipantType $participantType = null,
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
            'title'               => $title,
            'participantType'     => $participantType,
            'event'               => $event,
            'events'              => $events,
            'participantsService' => $this,
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

    public function getWebPartners(array $opts = []): Collection
    {
        $opts[ParticipantRepository::CRITERIA_PARTICIPANT_TYPE_STRING] ??= ParticipantType::TYPE_PARTNER;

        return $this->getParticipants($opts)->filter(
            fn(Participant $ep) => $ep->hasFlagOfTypeString(ParticipantFlagType::TYPE_PARTNER_HOMEPAGE)
        );
    }

    /**
     * Array of eventParticipants aggregated by flags (and aggregated by flagTypes).
     *
     * array[flagTypeSlug]['flagType']
     * array[flagTypeSlug]['flags'][flagSlug]['flag']
     * array[flagTypeSlug]['flags'][flagSlug]['participants']
     *
     * @param Event                $event
     * @param ParticipantType|null $participantType
     * @param bool|null            $includeDeleted
     * @param bool|null            $includeNotActivated
     * @param int|null             $recursiveDepth Default is 1 for root events, 0 for others.
     *
     * @return array
     */
    public function getParticipantsAggregatedByFlags(
        Event $event,
        ?ParticipantType $participantType = null,
        ?bool $includeDeleted = false,
        ?bool $includeNotActivated = true,
        ?int $recursiveDepth = 1
    ): array {
        $output = [];
        $recursiveDepth ??= $event->getSuperEvent() ? 0 : 1;
        $opts = [
            ParticipantRepository::CRITERIA_EVENT                 => $event,
            ParticipantRepository::CRITERIA_PARTICIPANT_TYPE      => $participantType,
            ParticipantRepository::CRITERIA_INCLUDE_DELETED       => $includeDeleted,
            ParticipantRepository::CRITERIA_EVENT_RECURSIVE_DEPTH => $recursiveDepth,
        ];
        $participants = $this->getParticipants($opts, $includeNotActivated);
        if ($participantType) {
            foreach ($participants as $participant) {
                assert($participant instanceof Participant);
                foreach ($participant->getFlagRangeConnections() as $participantFlagConnection) {
                    assert($participantFlagConnection instanceof ParticipantFlagRange);
                    $flag = $participantFlagConnection->getFlag();
                    if (null !== $flag) {
                        $flagType = $flag->getFlagType();
                        $flagTypeSlug = $flagType ? $flagType->getSlug() : '';
                        $output[$flagTypeSlug]['flags'][$flag->getSlug()]['participants'][] = $participant;
                        $output[$flagTypeSlug]['flags'][$flag->getSlug()]['flag'] ??= $flag;
                        $output[$flagTypeSlug]['flagType'] ??= $flagType;
                    }
                }
            }
        } else {
            foreach ($participants as $participant) {
                assert($participant instanceof Participant);
                $participantType = $participant->getParticipantType();
                $participantTypeArray = [
                    'id'        => $participantType->getId(),
                    'name'      => $participantType->getName(),
                    'shortName' => $participantType->getShortName(),
                ];
                foreach ($participant->getFlagRangeConnections() as $participantFlagConnection) {
                    assert($participantFlagConnection instanceof ParticipantFlagRangeConnection);
                    $flag = $participantFlagConnection->getFlag();
                    if (null !== $flag) {
                        $flagType = $flag->getFlagType();
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
     * @param Event                $event
     * @param ParticipantType|null $participantType
     * @param bool|null            $includeDeleted
     * @param bool|null            $includeNotActivated
     * @param int|null             $recursiveDepth Default is 1 for root events, 0 for others.
     *
     * @return array
     * @throws Exception
     */
    public function getActiveParticipantsAggregatedBySchool(
        Event $event,
        ?ParticipantType $participantType = null,
        ?bool $includeDeleted = false,
        ?bool $includeNotActivated = false,
        ?int $recursiveDepth = null
    ): array {
        $now = new DateTime();
        $recursiveDepth ??= $event->getSuperEvent() ? 0 : 1;
        $output = [];
        $opts = [
            ParticipantRepository::CRITERIA_EVENT                 => $event,
            ParticipantRepository::CRITERIA_PARTICIPANT_TYPE      => $participantType,
            ParticipantRepository::CRITERIA_INCLUDE_DELETED       => $includeDeleted,
            ParticipantRepository::CRITERIA_EVENT_RECURSIVE_DEPTH => $recursiveDepth,
        ];
        $participants = $this->getParticipants($opts, $includeNotActivated);
        if (null !== $participantType) {
            foreach ($participants as $participant) {
                assert($participant instanceof Participant);
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
                assert($participant instanceof Participant);
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

    final public function containsAppUser(Event $event, AppUser $appUser, ParticipantType $participantType = null): bool
    {
        return $this->getRepository()->getParticipants(
                [
                    ParticipantRepository::CRITERIA_EVENT            => $event,
                    ParticipantRepository::CRITERIA_PARTICIPANT_TYPE => $participantType,
                    ParticipantRepository::CRITERIA_APP_USER         => $appUser,
                ]
            )->count() > 0;
    }
}
