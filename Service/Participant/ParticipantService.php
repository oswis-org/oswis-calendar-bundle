<?php
/**
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Service\Participant;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use OswisOrg\OswisAddressBookBundle\Entity\AbstractClass\AbstractContact;
use OswisOrg\OswisAddressBookBundle\Entity\ContactNote;
use OswisOrg\OswisAddressBookBundle\Service\AbstractContactService;
use OswisOrg\OswisCalendarBundle\Entity\Event\Event;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantCategory;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantNote;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantToken;
use OswisOrg\OswisCalendarBundle\Entity\ParticipantMail\ParticipantMail;
use OswisOrg\OswisCalendarBundle\Entity\Registration\RegistrationFlagCategory;
use OswisOrg\OswisCalendarBundle\Entity\Registration\RegistrationOffer;
use OswisOrg\OswisCalendarBundle\Exception\EventCapacityExceededException;
use OswisOrg\OswisCalendarBundle\Exception\FlagCapacityExceededException;
use OswisOrg\OswisCalendarBundle\Exception\FlagOutOfRangeException;
use OswisOrg\OswisCalendarBundle\Exception\ParticipantNotFoundException;
use OswisOrg\OswisCalendarBundle\Exception\ReturningParticipantException;
use OswisOrg\OswisCalendarBundle\Repository\Participant\ParticipantRepository;
use OswisOrg\OswisCalendarBundle\Service\Registration\RegistrationFlagOfferService;
use OswisOrg\OswisCalendarBundle\Service\Registration\RegistrationOfferService;
use OswisOrg\OswisCoreBundle\Entity\AppUser\AppUser;
use OswisOrg\OswisCoreBundle\Entity\AppUser\AppUserToken;
use OswisOrg\OswisCoreBundle\Exceptions\InvalidTypeException;
use OswisOrg\OswisCoreBundle\Exceptions\NotFoundException;
use OswisOrg\OswisCoreBundle\Exceptions\NotImplementedException;
use OswisOrg\OswisCoreBundle\Exceptions\OswisException;
use OswisOrg\OswisCoreBundle\Exceptions\TokenInvalidException;
use OswisOrg\OswisCoreBundle\Exceptions\UserNotFoundException;
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
        protected readonly RegistrationOfferService $registrationOfferService,
        protected readonly AppUserTypeService $appUserTypeService,
        protected readonly ParticipantFilterEvaluator $filterEvaluator,
    ) {
    }

    public function getTokenService(): ParticipantTokenService
    {
        return $this->tokenService;
    }

    /**
     * Po přesunu účastníka na jinou nabídku (`setOffer`) sjednotí "po-zápisovou" logiku,
     * kterou jinak dělá jen ParticipantSubscriber (ten běží pouze na API requestech): pošle
     * oznámení o změně přihlášky a přepočítá obsazenost příznaků i obou dotčených nabídek
     * (zdrojové i cílové), aby kapacita obou ročníků zůstala správná.
     *
     * Volat AŽ PO `em->flush()` přesunu — diff změn (ParticipantChangeService) čte verzované
     * záznamy, které vzniknou teprve zápisem. Chyby jen loguje, nikdy nevyhazuje.
     */
    public function applyPostMoveSideEffects(Participant $participant, ?RegistrationOffer $oldOffer = null): void
    {
        try {
            $this->participantMailService->notifyParticipantChanged($participant);
        } catch (\Throwable $e) {
            $this->logger->error(sprintf(
                'applyPostMoveSideEffects: oznámení o změně selhalo pro účastníka #%d: %s',
                $participant->getId() ?? 0,
                $e->getMessage(),
            ));
        }
        try {
            $this->flagRangeService->updateUsages($participant);
            $newOffer = $participant->getOffer();
            if (null !== $newOffer) {
                $this->registrationOfferService->updateUsage($newOffer);
            }
            if (null !== $oldOffer && $oldOffer !== $newOffer) {
                $this->registrationOfferService->updateUsage($oldOffer);
            }
        } catch (\Throwable $e) {
            $this->logger->error(sprintf(
                'applyPostMoveSideEffects: přepočet obsazenosti selhal pro účastníka #%d: %s',
                $participant->getId() ?? 0,
                $e->getMessage(),
            ));
        }
    }

    /**
     * @param Participant|null $participant
     *
     * @return Participant
     * @throws InvalidTypeException
     * @throws NotFoundException
     * @throws NotImplementedException
     * @throws OswisException
     * @throws UserNotFoundException
     * @throws UserNotUniqueException
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

        // Double-submit dedup: iOS Safari (and slow connections in general)
        // sometimes POST the form twice. If a Participant with the same
        // e-mail + offer was just persisted in the last 60 seconds, return
        // it instead of creating a duplicate. The client-side onsubmit
        // handler catches most of these, but a server-side guard is needed
        // for genuine races where both POSTs reach PHP-FPM in parallel.
        $offer = $participant->getOffer();
        if (null !== $participantMailAddress && '' !== $participantMailAddress && null !== $offer) {
            $recent = $this->participantRepository->createQueryBuilder('p')
                ->innerJoin('p.participantContacts', 'pc')
                ->innerJoin('pc.contact', 'c')
                ->innerJoin('c.details', 'd')
                ->andWhere('LOWER(d.content) = LOWER(:mail)')
                ->andWhere('p.offer = :offer')
                ->andWhere('p.createdAt > :since')
                ->andWhere('p.deletedAt IS NULL')
                ->setParameter('mail', $participantMailAddress)
                ->setParameter('offer', $offer)
                ->setParameter('since', new \DateTime('-60 seconds'))
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();
            if ($recent instanceof Participant) {
                $this->logger->warning(
                    "Duplicate registration detected for '$participantMailAddress' on offer "
                    .'#'.($offer->getId() ?? '?')." — returning existing participant #".($recent->getId() ?? '?').'.',
                );

                return $recent;
            }
        }

        if (null === ($appUser = $participant->getAppUser())
            && $this->appUserService->alreadyExists($eMail = ''.$contact->getEmail())) {
            // Returning participant — they already have an AppUser from a previous year.
            // Instead of throwing a hard "duplicate user" error and forcing admin to send
            // a login link manually (the workflow up to 2025), send a single-use magic-link
            // email automatically and let the user resume registration with one click.
            $existingAppUser = $this->appUserService->getRepository()->findOneBy(['email' => $eMail]);
            $rangeSlug = $participant->getOffer()?->getSlug();
            if ($existingAppUser instanceof AppUser && null !== $rangeSlug) {
                // Mirror the target ParticipantCategory tone (formal vs informal)
                // so the magic-link email matches the rest of the registration
                // flow's tone (Seznamovák = informal, business event = formal).
                $formal = (bool) ($participant->getOffer()->getParticipantCategory()?->isFormal() ?? false);
                $this->appUserService->sendRegistrationLoginLink($existingAppUser, $rangeSlug, $formal);
                $this->logger->info(
                    "Returning participant collision for '$eMail' — magic-link sent for range '$rangeSlug'.",
                );

                throw new ReturningParticipantException(
                    'Tento e-mail u nás z dřívějška už máme. Právě jsme Ti poslali e-mail '
                    .'s odkazem pro pokračování v přihlášce — klikni na něj a vrátíš se '
                    .'na formulář s předvyplněnými údaji o sobě.',
                );
            }
            $this->logger->error("User not unique with string '$eMail' and participant was NOT created!");
            throw new UserNotUniqueException('Uživatel se stejným e-mailem nebo jménem již existuje!');
        }
        if (null === $appUser) {
            $this->logger->info(
                "Creating new AppUser for participant with name '$participantName' and e-mail '$participantMailAddress'."
            );
            $appUserType = $this->appUserTypeService->getRepository()->findOneBy(['slug' => 'customer']);
            $newAppUser = new AppUser(
                $contact->getName(), $participantMailAddress, $participantMailAddress, null, $appUserType,
            );
            $contact->setAppUser($this->appUserService->create($newAppUser, false, false, false));
            $this->logger->debug("New AppUser for participant '$participantMailAddress' created.");
        }
        if (null === $contact->getId()) {
            $contact->addNote(new ContactNote("Vytvořeno k přihlášce na akci '$eventName'."));
        }
        $participant->removeEmptyNotesAndDetails();

        // Persist + lock-protected capacity recheck happen atomically.
        // The capacity check inside Participant::setParticipantRegistration runs during
        // API Platform deserialization (no lock) and only catches obviously-full ranges
        // for friendly errors. Concurrent registrations would still race; serialize them
        // here on the RegistrationOffer row.
        return $this->em->wrapInTransaction(function () use ($participant): Participant {
            $regRange = $participant->getOffer();
            if (null !== $regRange && null !== $regRange->getId()) {
                $this->em->lock($regRange, LockMode::PESSIMISTIC_WRITE);
                $this->em->refresh($regRange);
                $remaining = $regRange->getRemainingCapacity();
                if (null !== $remaining && $remaining <= 0) {
                    throw new EventCapacityExceededException($regRange->getName());
                }
            }
            $this->em->persist($participant);
            $participant->updateCachedColumns();
            $this->requestActivation($participant);
            // Flush the new participant (and, by cascade, its ParticipantRegistration + flags)
            // BEFORE recomputing the cached usage counters. Doctrine ORM 3 does NOT auto-flush
            // before a DQL query, so updateUsage()/updateUsages() COUNT the DB directly: counting
            // before this flush silently omits the row we just added, writing base_usage one too
            // low — which lets the *next* registration overbook by one. Verified empirically.
            $this->em->flush();
            $this->flagRangeService->updateUsages($participant);
            if (null !== $regRange) {
                $this->registrationOfferService->updateUsage($regRange);
            }
            $this->em->flush();
            $this->logger->info($this->getLogMessage($participant));

            return $participant;
        });
    }

    final public function getRepository(): ParticipantRepository
    {
        return $this->participantRepository;
    }

    /**
     * @param Participant|null $participant
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
        $this->logger->info(
            "Will send verification e-mail to $contactPersonsCount contact persons of participant $participantId."
        );
        foreach ($participant->getContactPersons(false) as $contactPerson) {
            if (!($contactPerson instanceof AbstractContact) || null === ($appUser = $contactPerson->getAppUser())) {
                $this->logger->notice("Contact person is not AbstractPerson or doesn't have AppUser assigned.");
                /** @var object $contactPerson */
                $this->logger->notice(
                    "Contact person is of type "
                    .get_class($contactPerson)
                    ." and is "
                    .($contactPerson
                    instanceof
                    AbstractContact ? '' : 'NOT')
                    ." AbstractContact"
                );
                continue;
            }
            try {
                $this->requestActivationForUser($participant, $appUser);
                $sent++;
            } catch (OswisException|NotFoundException|NotImplementedException|InvalidTypeException $exception) {
                $this->logger->error(
                    "Participant ($participantId) activation request FAILED. ".$exception->getMessage()
                );
            }
        }
        if ($sent < 1 && $contactPersonsCount > 0) {
            $this->logger->error("None activation e-mail was sent for participant ($participantId)!");
            throw new OswisException("Nepodařilo se odeslat aktivační e-mail k přihlášce.");
        }
    }

    /**
     * @param Participant $participant
     * @param AppUser $appUser
     *
     * @throws InvalidTypeException
     * @throws NotFoundException
     * @throws NotImplementedException
     * @throws OswisException
     */
    private function requestActivationForUser(Participant $participant, AppUser $appUser): void
    {
        $participantToken = $this->tokenService->create($participant, $appUser, AppUserToken::TYPE_ACTIVATION, false);
        $this->participantMailService->sendSummaryToUser(
            $participant,
            $appUser,
            ParticipantMail::TYPE_ACTIVATION_REQUEST,
            $participantToken
        );
        $this->logger->info(
            'Sent activation request for participant '.$participant->getId().' to user '.$appUser->getId().'.'
        );
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
     * @param RegistrationOffer $range
     * @param Participant $participant
     *
     * @throws EventCapacityExceededException
     */
    public function checkParticipantSuperEvent(RegistrationOffer $range, Participant $participant): void
    {
        if (true === $range->isSuperEventRequired()) {
            $included = false;
            $participantsOfContact = $this->getParticipants([
                ParticipantRepository::CRITERIA_CONTACT => $participant->getContact(),
                ParticipantRepository::CRITERIA_INCLUDE_DELETED => false,
            ]);
            foreach ($participantsOfContact as $participantOfContact) {
                if ($range->isParticipantInSuperEvent($participantOfContact)) {
                    $included = true;
                }
            }
            if (!$included) {
                throw new EventCapacityExceededException(
                    'Pro přihlášku v tomto rozsahu je nutné se zúčastnit i nadřazené akce.'
                );
            }
        }
    }

    /**
     * @param array    $opts
     * @param bool|null $includeNotActivated
     * @param int|null $limit
     * @param int|null $offset
     *
     * @return Collection<int, Participant>
     */
    public function getParticipants(
        array $opts = [],
        ?bool $includeNotActivated = true,
        ?int $limit = null,
        ?int $offset = null
    ): Collection
    {
        return $this->getRepository()->getParticipants($opts, $includeNotActivated, $limit, $offset);
    }

    public function getParticipant(array $opts = [], ?bool $includeNotActivated = true): ?Participant
    {
        return $this->getRepository()->getParticipant($opts, $includeNotActivated);
    }

    /**
     * Restore a soft-deleted participant.
     *
     * Sets deletedAt back to null. Does NOT cascade-restore child collections
     * (flag groups, registrations) — those keep their original deletedAt timestamps.
     * Admin must restore them separately if needed.
     */
    public function restore(Participant $participant): void
    {
        if (!$participant->isDeleted()) {
            return;
        }
        $participant->setDeletedAt(null);
        $this->em->persist($participant);
        $this->em->flush();
        $this->logger->info("Participant ({$participant->getId()}) restored from soft-delete.");
    }

    /**
     * Soft-delete a participant (set deletedAt to now). Reversible via {@see restore()}.
     *
     * Child collections (flag groups, registrations) are left untouched — they keep their
     * own deletedAt state, mirroring the asymmetric restore() behaviour.
     */
    public function delete(Participant $participant): void
    {
        if ($participant->isDeleted()) {
            return;
        }
        $participant->delete();
        $this->em->persist($participant);
        $this->em->flush();
        $this->logger->info("Participant ({$participant->getId()}) soft-deleted.");
    }

    /**
     * @param Participant|null $participant
     * @param ParticipantToken $participantToken
     * @param bool $sendConfirmation
     *
     * @throws OswisException
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
            $this->logger->error(
                'Participant ('.$participant?->getId().') activation FAILED. '.$exception->getMessage()
            );
            throw new OswisException("Aktivace přihlášky se nezdařila. ".$exception->getMessage());
        }
    }

    /**
     * Checks if contact is participant in event as given participantType.
     *
     * @param Event           $event
     * @param AbstractContact $contact
     * @param ParticipantCategory|null $participantType
     *
     * @return bool
     */
    public function isContactContainedInEvent(
        Event $event,
        AbstractContact $contact,
        ?ParticipantCategory $participantType = null
    ): bool {
        $opts = [
            ParticipantRepository::CRITERIA_EVENT => $event,
            ParticipantRepository::CRITERIA_PARTICIPANT_CATEGORY => $participantType,
            ParticipantRepository::CRITERIA_CONTACT => $contact,
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
        $organizer = $this->getOrganizers($event)->map(
            fn (mixed $p) => (($p instanceof Participant) ? $p->getContact() : null)
        )->first() ?: null;
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
            ParticipantRepository::CRITERIA_EVENT => $event,
            ParticipantRepository::CRITERIA_PARTICIPANT_TYPE => $participantType,
            ParticipantRepository::CRITERIA_INCLUDE_DELETED => $includeDeleted,
            ParticipantRepository::CRITERIA_EVENT_RECURSIVE_DEPTH => $depth,
        ], $includeNotActivated);
    }

    public function getWebPartners(array $opts = []): Collection
    {
        $opts[ParticipantRepository::CRITERIA_PARTICIPANT_TYPE] ??= ParticipantCategory::TYPE_PARTNER;

        return $this->getParticipants($opts)->filter(
            static fn (Participant $participant) => $participant->hasFlag(
                null,
                true,
                null,
                RegistrationFlagCategory::TYPE_PARTNER_HOMEPAGE
            )
        );
    }

    final public function containsAppUser(
        Event $event,
        AppUser $appUser,
        ?ParticipantCategory $participantType = null
    ): bool {
        $opts = [
            ParticipantRepository::CRITERIA_EVENT => $event,
            ParticipantRepository::CRITERIA_PARTICIPANT_CATEGORY => $participantType,
            ParticipantRepository::CRITERIA_APP_USER => $appUser,
        ];

        return $this->getRepository()->countParticipants($opts) > 0;
    }

    /**
     * @param string|null $token
     * @param int|null $participantId
     *
     * @return ParticipantToken
     * @throws TokenInvalidException
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
     * @param RegistrationOffer $regRange
     * @param AbstractContact|null $contact
     *
     * @return Participant
     * @throws InvalidArgumentException
     * @throws EventCapacityExceededException
     * @throws FlagCapacityExceededException
     * @throws FlagOutOfRangeException
     * @throws ParticipantNotFoundException
     * @throws NotImplementedException
     * @throws OswisException
     */
    public function getEmptyParticipant(RegistrationOffer $regRange, ?AbstractContact $contact = null): Participant
    {
        if (null === $regRange->getEvent()) {
            throw new ParticipantNotFoundException('Registrační rozsah nelze použít, protože nemá přiřazenou událost.');
        }
        if (null === $regRange->getParticipantCategory()) {
            throw new ParticipantNotFoundException(
                'Registrační rozsah nelze použít, protože nemá přiřazený typ účastníka.'
            );
        }
        $this->logger->info('Creating empty participant.');

        return new Participant(
            $regRange,
            $this->abstractContactService->getContact($contact, ['participant-e-mail', 'participant-phone']),
            new ArrayCollection([new ParticipantNote(null, null, true)]),
        );
    }

    /**
     * Drain auto-mail groups: for each active group, send its mail to participants of the group's
     * event (+ sub-events) who have NOT received it yet. Recipients come from an id-only query with
     * SQL-side dedup + a true LIMIT ({@see ParticipantRepository::findUnmailedParticipantIds}) — no
     * whole-cohort hydration and no lazy-collection N+1 (the old load-all → filter → slice path).
     * Per-participant isolation: one failing recipient never aborts the batch. Returns a summary so
     * the cron command / admin trigger can report + surface failures (no longer silently swallowed).
     *
     * $limit caps send ATTEMPTS (successful or failed) per group per call — bounded SMTP work for
     * the cron; failed recipients stay unmailed and are retried next run. Candidate ids are paged
     * with an id cursor:
     * participants the group never applies to (filter expression, inactive) stay "unmailed" forever,
     * so a single LIMIT window would fill up with them and stall the campaign — the cursor walks past
     * them to recipients further in the cohort.
     *
     * A group with a syntactically broken filter expression is skipped whole and reported (the
     * entity's fail-closed evaluation would otherwise silently scan the cohort and send nothing).
     *
     * @return array{sent: int, failed: int, errors: list<string>}
     */
    public function sendAutoMails(?Event $event = null, ?string $type = null, int $limit = 100): array
    {
        $sent = 0;
        $failed = 0;
        $errors = [];
        foreach ($this->participantMailService->getAutoMailGroups($event, $type) ?? new ArrayCollection() as $group) {
            $groupEvent = $group->getEvent();
            $groupType = $group->getType();
            if (!$groupEvent instanceof Event || null === $groupType) {
                continue;
            }
            if (null !== ($filterError = $this->filterEvaluator->validate($group->getFilterExpression()))) {
                $errors[] = sprintf(
                    'Skupina „%s" (%s) přeskočena — neplatný filtr: %s',
                    $group->getName() ?? '?',
                    $groupType,
                    $filterError,
                );
                continue;
            }
            $remaining = max(1, $limit);
            $afterId = 0;
            while ($remaining > 0) {
                $ids = $this->participantRepository->findUnmailedParticipantIds(
                    $groupEvent,
                    $groupType,
                    max(1, $limit),
                    4,
                    !$group->isOnlyActive(),
                    $afterId,
                );
                if ([] === $ids) {
                    break;
                }
                foreach ($ids as $id) {
                    $afterId = $id;
                    $participant = $this->em->find(Participant::class, $id);
                    if (!$participant instanceof Participant || !$group->isApplicable($participant)) {
                        continue;
                    }
                    try {
                        $this->participantMailService->sendMessage($participant, $group);
                        ++$sent;
                    } catch (\Throwable $e) {
                        ++$failed;
                        $errors[] = sprintf('#%d: %s', $id, $e->getMessage());
                    }
                    if (--$remaining <= 0) {
                        break;
                    }
                }
            }
        }

        return ['sent' => $sent, 'failed' => $failed, 'errors' => $errors];
    }

}
