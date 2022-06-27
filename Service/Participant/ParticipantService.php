<?php
/**
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Service\Participant;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityManagerInterface;
use OswisOrg\OswisAddressBookBundle\Entity\AbstractClass\AbstractContact;
use OswisOrg\OswisAddressBookBundle\Entity\ContactNote;
use OswisOrg\OswisAddressBookBundle\Service\AbstractContactService;
use OswisOrg\OswisCalendarBundle\Entity\Event\Event;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantCategory;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantNote;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantToken;
use OswisOrg\OswisCalendarBundle\Entity\ParticipantMail\ParticipantMail;
use OswisOrg\OswisCalendarBundle\Entity\ParticipantMail\ParticipantMailGroup;
use OswisOrg\OswisCalendarBundle\Entity\Registration\RegistrationFlagCategory;
use OswisOrg\OswisCalendarBundle\Entity\Registration\RegistrationOffer;
use OswisOrg\OswisCalendarBundle\Exception\EventCapacityExceededException;
use OswisOrg\OswisCalendarBundle\Exception\ParticipantNotFoundException;
use OswisOrg\OswisCalendarBundle\Repository\Participant\ParticipantRepository;
use OswisOrg\OswisCalendarBundle\Service\Registration\RegistrationFlagOfferService;
use OswisOrg\OswisCoreBundle\Entity\AppUser\AppUser;
use OswisOrg\OswisCoreBundle\Entity\AppUser\AppUserToken;
use OswisOrg\OswisCoreBundle\Exceptions\InvalidTypeException;
use OswisOrg\OswisCoreBundle\Exceptions\NotFoundException;
use OswisOrg\OswisCoreBundle\Exceptions\NotImplementedException;
use OswisOrg\OswisCoreBundle\Exceptions\OswisException;
use OswisOrg\OswisCoreBundle\Exceptions\TokenInvalidException;
use OswisOrg\OswisCoreBundle\Exceptions\UserNotUniqueException;
use OswisOrg\OswisCoreBundle\Service\AppUserService;
use OswisOrg\OswisCoreBundle\Service\AppUserTypeService;
use Psr\Log\LoggerInterface;

class ParticipantService
{
    public function __construct(
        protected readonly EntityManagerInterface $em,
        protected readonly ParticipantRepository $participantRepository,
        protected readonly LoggerInterface $logger,
        protected readonly AppUserService $appUserService,
        protected readonly ParticipantTokenService $tokenService,
        protected readonly ParticipantMailService $participantMailService,
        protected readonly AbstractContactService $abstractContactService,
        protected readonly RegistrationFlagOfferService $flagRangeService,
        protected readonly AppUserTypeService $appUserTypeService,
    ) {
    }

    public function getTokenService(): ParticipantTokenService
    {
        return $this->tokenService;
    }

    /**
     * @param  Participant|null  $participant
     *
     * @return Participant
     * @throws \OswisOrg\OswisCoreBundle\Exceptions\InvalidTypeException
     * @throws \OswisOrg\OswisCoreBundle\Exceptions\NotFoundException
     * @throws \OswisOrg\OswisCoreBundle\Exceptions\NotImplementedException
     * @throws \OswisOrg\OswisCoreBundle\Exceptions\OswisException
     * @throws \OswisOrg\OswisCoreBundle\Exceptions\UserNotFoundException
     * @throws \OswisOrg\OswisCoreBundle\Exceptions\UserNotUniqueException
     */
    public function create(?Participant $participant): Participant
    {
        $this->logger->debug("Will create new participant.");
        if (!($participant instanceof Participant)) {
            $this->logger->error("Participant was NOT created because Participant is missing.");
            throw new OswisException('Přihláška není kompletní nebo je poškozená.');
        }
        if (null === ($event = $participant->getEvent())) {
            $this->logger->error("Participant was NOT created because Event is missing.");
            throw new OswisException('Přihláška není kompletní nebo je poškozená. Není vybrána žádná událost.');
        }
        $eventName = $event->getName();
        if (null === ($contact = $participant->getContact())) {
            $this->logger->error("Participant was NOT created because Contact is missing.");
            throw new OswisException('Přihláška není kompletní nebo je poškozená. V přihlášce chybí kontakt.');
        }
        $participantMailAddress = $contact->getEmail();
        $participantName = $contact->getName();
        if (null === ($appUser = $participant->getAppUser())
            && $this->appUserService->alreadyExists($eMail = ''.$contact->getEmail())) {
            $this->logger->error("User not unique with string '$eMail' and participant was NOT created!");
            throw new UserNotUniqueException('Uživatel se stejným e-mailem nebo jménem již existuje!');
        }
        if (null === $appUser) {
            $this->logger->info("Creating new AppUser for participant with name '$participantName' and e-mail '$participantMailAddress'.");
            $appUserType = $this->appUserTypeService->getRepository()->findOneBy(['slug' => 'customer']);
            $newAppUser = new AppUser($contact->getName(), $participantMailAddress, $participantMailAddress, null,
                $appUserType,);
            $contact->setAppUser($this->appUserService->create($newAppUser, false, false, false));
            $this->logger->debug("New AppUser for participant '$participantMailAddress' created.");
        }
        if (null === $contact->getId()) {
            $contact->addNote(new ContactNote("Vytvořeno k přihlášce na akci '$eventName'."));
        }
        $participant->removeEmptyNotesAndDetails();
        $this->em->persist($participant);
        $participant->updateCachedColumns();
        $this->requestActivation($participant);
        $this->flagRangeService->updateUsages($participant);
        $this->em->flush();
        $this->logger->info($this->getLogMessage($participant));

        return $participant;
    }

    final public function getRepository(): ParticipantRepository
    {
        return $this->participantRepository;
    }

    /**
     * @param  Participant|null  $participant
     *
     * @throws OswisException|NotFoundException
     */
    public function requestActivation(?Participant $participant): void
    {
        if (null === $participant || null === $participant->getContact(false)) {
            $this->logger->error('Participant (empty) activation request FAILED.');
            throw new NotFoundException('Přihláška nenalezena.');
        }
        $this->em->persist($participant);
        $this->em->flush();
        $participantId = $participant->getId();
        $sent = 0;
        $contactPersonsCount = $participant->getContactPersons(false)->count();
        $this->logger->info("Will send verification e-mail to $contactPersonsCount contact persons of participant $participantId.");
        foreach ($participant->getContactPersons(false) as $contactPerson) {
            if (!($contactPerson instanceof AbstractContact) || null === ($appUser = $contactPerson->getAppUser())) {
                $this->logger->notice("Contact person is not AbstractPerson or doesn't have AppUser assigned.");
                /** @var object $contactPerson */
                $this->logger->notice("Contact person is of type ".get_class($contactPerson)." and is ".($contactPerson
                                                                                                         instanceof
                                                                                                         AbstractContact
                        ? '' : 'NOT')." AbstractContact");
                continue;
            }
            try {
                $this->requestActivationForUser($participant, $appUser);
                $sent++;
            } catch (OswisException|NotFoundException|NotImplementedException|InvalidTypeException $exception) {
                $this->logger->error("Participant ($participantId) activation request FAILED. "
                                     .$exception->getMessage());
            }
        }
        if ($sent < 1 && $contactPersonsCount > 0) {
            $this->logger->error("None activation e-mail was sent for participant ($participantId)!");
            throw new OswisException("Nepodařilo se odeslat aktivační e-mail k přihlášce.");
        }
    }

    /**
     * @param  Participant  $participant
     * @param  AppUser  $appUser
     *
     * @throws InvalidTypeException
     * @throws NotFoundException
     * @throws NotImplementedException
     * @throws OswisException
     */
    private function requestActivationForUser(Participant $participant, AppUser $appUser): void
    {
        $participantToken = $this->tokenService->create($participant, $appUser, AppUserToken::TYPE_ACTIVATION, false);
        $this->participantMailService->sendSummaryToUser($participant, $appUser,
            ParticipantMail::TYPE_ACTIVATION_REQUEST, $participantToken);
        $this->logger->info('Sent activation request for participant '
                            .$participant->getId()
                            .' to user '
                            .$appUser->getId()
                            .'.');
    }

    private function getLogMessage(Participant $participant): string
    {
        $id = $participant->getId();
        $contactName = $participant->getContact()?->getName() ?? '';
        $infoMessage = "Created participant (by service) with ID [$id] and contact name [$contactName]";
        $rangeName = $participant->getOffer()?->getName() ?? '';
        $infoMessage .= " to range [$rangeName].";

        return $infoMessage;
    }

    /**
     * @param  RegistrationOffer  $range
     * @param  Participant  $participant
     *
     * @throws EventCapacityExceededException
     */
    public function checkParticipantSuperEvent(RegistrationOffer $range, Participant $participant): void
    {
        if (true === $range->isSuperEventRequired()) {
            $included = false;
            $participantsOfContact = $this->getParticipants([
                ParticipantRepository::CRITERIA_CONTACT         => $participant->getContact(),
                ParticipantRepository::CRITERIA_INCLUDE_DELETED => false,
            ]);
            foreach ($participantsOfContact as $participantOfContact) {
                if ($participantOfContact instanceof Participant
                    && $range->isParticipantInSuperEvent($participantOfContact)) {
                    $included = true;
                }
            }
            if (!$included) {
                throw new EventCapacityExceededException('Pro přihlášku v tomto rozsahu je nutné se zúčastnit i nadřazené akce.');
            }
        }
    }

    /**
     * @param  array  $opts
     * @param  bool|null  $includeNotActivated
     * @param  int|null  $limit
     * @param  int|null  $offset
     *
     * @return \Doctrine\Common\Collections\Collection<Participant>
     */
    public function getParticipants(
        array $opts = [],
        ?bool $includeNotActivated = true,
        ?int $limit = null,
        ?int $offset = null
    ): Collection {
        return $this->getRepository()->getParticipants($opts, $includeNotActivated, $limit, $offset);
    }

    public function getParticipant(array $opts = [], ?bool $includeNotActivated = true): ?Participant
    {
        return $this->getRepository()->getParticipant($opts, $includeNotActivated);
    }

    /**
     * @param  \OswisOrg\OswisCalendarBundle\Entity\Participant\Participant|null  $participant
     * @param  ParticipantToken  $participantToken
     * @param  bool  $sendConfirmation
     *
     * @throws \OswisOrg\OswisCoreBundle\Exceptions\OswisException
     */
    public function activate(
        ?Participant $participant,
        ParticipantToken $participantToken,
        bool $sendConfirmation = true
    ): void {
        try {
            if (null === $participant || null === ($appUser = $participantToken->getAppUser())) {
                throw new NotFoundException('Uživatel nenalezen.');
            }
            $this->appUserService->activate($appUser, false);
            $participant->setUserConfirmed($appUser);
            if (true === $sendConfirmation) {
                $this->participantMailService->sendSummary($participant);
            }
            $this->em->persist($participant);
            $this->em->flush();
            $this->logger->info('Successfully activated participant ('.$participant->getId().').');
        } catch (OswisException|NotFoundException|InvalidTypeException $exception) {
            $this->logger->error('Participant ('
                                 .$participant?->getId()
                                 .') activation FAILED. '
                                 .$exception->getMessage());
            throw new OswisException("Aktivace přihlášky se nezdařila. ".$exception->getMessage());
        }
    }

    /**
     * Checks if contact is participant in event as given participantType.
     *
     * @param  Event  $event
     * @param  AbstractContact  $contact
     * @param  ParticipantCategory|null  $participantType
     *
     * @return bool
     */
    public function isContactContainedInEvent(
        Event $event,
        AbstractContact $contact,
        ParticipantCategory $participantType = null
    ): bool {
        $opts = [
            ParticipantRepository::CRITERIA_EVENT                => $event,
            ParticipantRepository::CRITERIA_PARTICIPANT_CATEGORY => $participantType,
            ParticipantRepository::CRITERIA_CONTACT              => $contact,
        ];

        return $this->getRepository()->countParticipants($opts) > 0;
    }

    public function countParticipants(array $opts = []): ?int
    {
        return $this->getRepository()->countParticipants($opts);
    }

    final public function getOrganizer(?Event $event): ?AbstractContact
    {
        if (null === $event) {
            return null;
        }
        $organizer = $this->getOrganizers($event)->map(fn(mixed $p) => (($p instanceof Participant) ? $p->getContact()
            : null))->first() ?: null;
        if (null === $organizer) {
            $organizer = $event->getSuperEvent() ? $this->getOrganizer($event->getSuperEvent()) : null;
        }

        return $organizer instanceof AbstractContact ? $organizer : null;
    }

    final public function getOrganizers(Event $event): Collection
    {
        return $this->getParticipantsByType($event, ParticipantCategory::TYPE_ORGANIZER);
    }

    public function getParticipantsByType(
        ?Event $event = null,
        ?string $participantType = ParticipantCategory::TYPE_ATTENDEE,
        ?bool $includeDeleted = false,
        ?bool $includeNotActivated = true,
        ?int $depth = 1
    ): Collection {
        return $this->getParticipants([
            ParticipantRepository::CRITERIA_EVENT                 => $event,
            ParticipantRepository::CRITERIA_PARTICIPANT_TYPE      => $participantType,
            ParticipantRepository::CRITERIA_INCLUDE_DELETED       => $includeDeleted,
            ParticipantRepository::CRITERIA_EVENT_RECURSIVE_DEPTH => $depth,
        ], $includeNotActivated);
    }

    public function getWebPartners(array $opts = []): Collection
    {
        $opts[ParticipantRepository::CRITERIA_PARTICIPANT_TYPE] ??= ParticipantCategory::TYPE_PARTNER;

        return $this->getParticipants($opts)->filter(fn(mixed $participant) => $participant instanceof Participant
                                                                               && $participant->hasFlag(null, true,
                null, RegistrationFlagCategory::TYPE_PARTNER_HOMEPAGE));
    }

    final public function containsAppUser(
        Event $event,
        AppUser $appUser,
        ParticipantCategory $participantType = null
    ): bool {
        $opts = [
            ParticipantRepository::CRITERIA_EVENT                => $event,
            ParticipantRepository::CRITERIA_PARTICIPANT_CATEGORY => $participantType,
            ParticipantRepository::CRITERIA_APP_USER             => $appUser,
        ];

        return $this->getRepository()->countParticipants($opts) > 0;
    }

    /**
     * @param  string|null  $token
     * @param  int|null  $participantId
     *
     * @return ParticipantToken
     * @throws \OswisOrg\OswisCoreBundle\Exceptions\TokenInvalidException
     */
    public function getVerifiedToken(?string $token, ?int $participantId): ParticipantToken
    {
        if (empty($token) || null === $participantId) {
            throw new TokenInvalidException('token neexistuje');
        }
        $participantToken = $this->getToken($token, $participantId);
        if (null === $participantToken) {
            throw new TokenInvalidException('zadaný token neexistuje');
        }
        $participantToken->use(true);

        return $participantToken;
    }

    public function getToken(string $token, int $participantId): ?ParticipantToken
    {
        return $this->tokenService->findToken($token, $participantId);
    }

    /**
     * Create empty eventParticipant for use in forms.
     *
     * @param  RegistrationOffer  $regRange
     * @param  AbstractContact|null  $contact
     *
     * @return Participant
     * @throws \InvalidArgumentException
     * @throws \OswisOrg\OswisCalendarBundle\Exception\EventCapacityExceededException
     * @throws \OswisOrg\OswisCalendarBundle\Exception\FlagCapacityExceededException
     * @throws \OswisOrg\OswisCalendarBundle\Exception\FlagOutOfRangeException
     * @throws \OswisOrg\OswisCalendarBundle\Exception\ParticipantNotFoundException
     * @throws \OswisOrg\OswisCoreBundle\Exceptions\NotImplementedException
     * @throws \OswisOrg\OswisCoreBundle\Exceptions\OswisException
     */
    public function getEmptyParticipant(RegistrationOffer $regRange, ?AbstractContact $contact = null): Participant
    {
        if (null === $regRange->getEvent()) {
            throw new ParticipantNotFoundException('Registrační rozsah nelze použít, protože nemá přiřazenou událost.');
        }
        if (null === $regRange->getParticipantCategory()) {
            throw new ParticipantNotFoundException('Registrační rozsah nelze použít, protože nemá přiřazený typ účastníka.');
        }
        $this->logger->info('Creating empty participant.');

        return new Participant($regRange,
            $this->abstractContactService->getContact($contact, ['participant-e-mail', 'participant-phone']),
            new ArrayCollection([new ParticipantNote(null, null, true)]),);
    }

    /**
     * @throws \OswisOrg\OswisCoreBundle\Exceptions\OswisException
     */
    public function sendAutoMails(?Event $event = null, ?string $type = null, int $limit = 100): void
    {
        foreach ($this->participantMailService->getAutoMailGroups($event, $type) ?? new ArrayCollection() as $group) {
            if (!($group instanceof ParticipantMailGroup)) {
                $this->logger->error("MailGroup is not MailGroup!");
                continue;
            }
            $participants = $this->getParticipants([
                ParticipantRepository::CRITERIA_INCLUDE_DELETED       => !$group->isOnlyActive(),
                ParticipantRepository::CRITERIA_EVENT                 => $group->getEvent(),
                ParticipantRepository::CRITERIA_EVENT_RECURSIVE_DEPTH => 4,
            ])->filter(fn(mixed $p) => $p instanceof Participant
                                       && !$p->hasEMailOfType($group->getType()),)->slice(0, $limit);
            foreach ($participants as $participant) {
                if ($participant instanceof Participant && $group->isApplicable($participant)) {
                    $this->participantMailService->sendMessage($participant, $group);
                }
            }
        }
    }

}
