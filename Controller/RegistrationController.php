<?php
/**
 * @noinspection PhpUnused
 * @noinspection MethodShouldBeFinalInspection
 * @noinspection RedundantDocCommentTagInspection
 */

namespace OswisOrg\OswisCalendarBundle\Controller;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use InvalidArgumentException;
use OswisOrg\OswisAddressBookBundle\Entity\AbstractClass\AbstractContact;
use OswisOrg\OswisAddressBookBundle\Entity\AddressBook\AddressBook;
use OswisOrg\OswisAddressBookBundle\Entity\ContactDetail;
use OswisOrg\OswisAddressBookBundle\Entity\ContactDetailType;
use OswisOrg\OswisAddressBookBundle\Entity\ContactNote;
use OswisOrg\OswisAddressBookBundle\Entity\Person;
use OswisOrg\OswisAddressBookBundle\Entity\Position;
use OswisOrg\OswisAddressBookBundle\Service\AddressBookService;
use OswisOrg\OswisCalendarBundle\Entity\Event\Event;
use OswisOrg\OswisCalendarBundle\Entity\Event\RegistrationsRange;
use OswisOrg\OswisCalendarBundle\Entity\NonPersistent\FlagsAggregatedByType;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantContactConnection;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantFlag;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantNote;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantRangeConnection;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantType;
use OswisOrg\OswisCalendarBundle\Exception\EventCapacityExceededException;
use OswisOrg\OswisCalendarBundle\Exception\OswisParticipantNotFoundException;
use OswisOrg\OswisCalendarBundle\Form\Participant\RegistrationFormType;
use OswisOrg\OswisCalendarBundle\Repository\EventRepository;
use OswisOrg\OswisCalendarBundle\Repository\ParticipantRepository;
use OswisOrg\OswisCalendarBundle\Service\EventService;
use OswisOrg\OswisCalendarBundle\Service\ParticipantService;
use OswisOrg\OswisCalendarBundle\Service\RegistrationsRangeService;
use OswisOrg\OswisCoreBundle\Entity\NonPersistent\Nameable;
use OswisOrg\OswisCoreBundle\Exceptions\OswisException;
use OswisOrg\OswisCoreBundle\Exceptions\OswisNotFoundException;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class RegistrationController extends AbstractController
{
    public EntityManagerInterface $em;

    public LoggerInterface $logger;

    public RegistrationsRangeService $registrationsRangeService;

    public ParticipantService $participantService;

    public AddressBookService $addressBookService;

    public EventService $eventService;

    public function __construct(
        EntityManagerInterface $em,
        LoggerInterface $logger,
        EventService $eventService,
        RegistrationsRangeService $registrationService,
        AddressBookService $addressBookService,
        ParticipantService $participantService
    ) {
        $this->em = $em;
        $this->logger = $logger;
        $this->eventService = $eventService;
        $this->addressBookService = $addressBookService;
        $this->registrationsRangeService = $registrationService;
        $this->participantService = $participantService;
    }

    /**
     * Process registration verification and appUser account activation.
     *
     * @param string $token
     * @param int    $participantId
     *
     * @return Response
     */
    public function verification(string $token, int $participantId): Response
    {
        // TODO: Check and refactor.
        try {
            if (empty($token) || empty($participantId)) {
                return $this->getResponse(
                    'error',
                    'Chyba! URL nekompletní!',
                    true,
                    null,
                    null,
                    'Formát adresy pro ověření je chybný.
                            Zkuste odkaz otevřít znovu nebo jej zkopírovat celý do adresního řádku prohlížeče.
                            Pokud se to nepodaří, kontaktujte nás a společně to vyřešíme.',
                );
            }
            $participant = $this->getParticipantService()->getRepository()->findOneBy(['id' => $participantId]);
            if (null === $participant || null === $participant->getContact()) {
                $error = null === $participant ? ', přihláška nenalezena' : '';
                $error .= !($participant->getContact() instanceof AbstractContact) ? ', účastník nenalezen' : '';

                return $this->getResponse(
                    'error',
                    'Chyba!',
                    true,
                    $participant->getEvent(),
                    null,
                    "Aktivace se nezdařila. Kontaktujte nás, prosím. (token $token, přihláška č. $participantId$error)"
                );
            }
            $participant->removeEmptyParticipantNotes();
            if (null !== $participant->getContact()) {
                $participant->getContact()->removeEmptyDetails();
                $participant->getContact()->removeEmptyNotes();
            }
            $this->getParticipantService()->sendMail($participant, true, $token);

            return $this->getResponse(
                'success',
                'Hotovo!',
                true,
                $participant->getEvent(),
                null,
                'Ověření uživatele proběhlo úspěšně.'
            );
        } catch (Exception $e) {
            $this->logger->notice('OSWIS_CONFIRM_ERROR: '.$e->getMessage());

            return $this->getResponse(
                'error',
                'Neočekávaná chyba!',
                true,
                null,
                null,
                'Registraci a přihlášku se nepodařilo potvrdit. Kontaktujte nás a společně to vyřešíme.'
            );
        }
    }

    public function getResponse(
        ?string $type,
        string $title,
        bool $verification = false,
        ?Event $event = null,
        ?RegistrationsRange $range = null,
        ?string $message = null,
        ?FormView $formView = null
    ): Response {
        $template = '@OswisOrgOswisCalendar/web/pages/event-participant-registration-form.html.twig';
        if ($verification) {
            $template = '@OswisOrgOswisCalendar/web/pages/event-participant-registration-confirmation.html.twig';
        }

        return $this->render(
            $template,
            [
                'form'                => $formView,
                'title'               => $title,
                'pageTitle'           => $title,
                'event'               => $event,
                'range'               => $range,
                'message'             => $message,
                'type'                => $type,
                'registrationsActive' => $range && $range->isRangeActive(),
            ]
        );
    }

    public function getParticipantService(): ParticipantService
    {
        return $this->participantService;
    }

    /**
     * Show or process registration form.
     *
     * Route shows registration form or process it if form was sent.
     * Data from form is validated, user is created and than summary and activation e-mail is sent.
     *
     * @param Request     $request
     * @param string|null $rangeSlug
     *
     * @return Response
     * @throws InvalidArgumentException
     * @throws OswisException|OswisNotFoundException|OswisParticipantNotFoundException|EventCapacityExceededException
     */
    public function registration(Request $request, ?string $rangeSlug = null): Response
    {
        if (null === $rangeSlug) {
            return $this->redirectToDefaultRange();
        }
        $range = $this->registrationsRangeService->getRangeBySlug($rangeSlug, true, true);
        if (null === $range || !($range instanceof RegistrationsRange) || !$range->isPublicOnWeb()) {
            throw new OswisNotFoundException('Rozsah pro vytváření přihlášek nebyl nalezen.');
        }
        $participant = $this->getEmptyParticipant($range, null);
        try {
            $form = $this->createForm(RegistrationFormType::class, $participant);
            $form->handleRequest($request);
            if ($form->isSubmitted() && $form->isValid()) {
                $participant = $form->getData();
                if (null === $participant || !($participant instanceof Participant)) {
                    throw new OswisException('Přihláška není kompletní nebo je poškozená.');
                }
                if (null === ($event = $participant->getEvent())) {
                    throw new OswisException('Přihláška není kompletní nebo je poškozená. Není vybrána žádná událost.');
                }
                if (!(($contact = $participant->getContact()) instanceof Person)) { // TODO: Organization?
                    throw new OswisException('Přihláška není kompletní nebo je poškozená. V přihlášce chybí kontakt.');
                }
                $eventName = $event->getShortName();
                $this->removeEmptyNotesAndDetails($participant, $contact);
                $contact->addNote(new ContactNote('Vytvořeno k přihlášce na akci ('.$event->getName().').'));
                $this->checkParticipant($range, $participant, $this->extractSelectedFlags($range, $form), true, false);
                $this->em->persist($participant);
                $this->getParticipantService()->sendMail($participant, true);

                return $this->getResponse(
                    'success',
                    'Přihláška odeslána!',
                    false,
                    $event,
                    $range,
                    "Tvoje přihláška na akci $eventName byla úspěšně odeslána! Nyní je ještě nutné ji potvrdit kliknutím na odkaz v e-mailu, který jsme Ti právě zaslali.",
                    $form->createView()
                );
            }

            return $this->getResponse(
                'form',
                "Přihláška na akci ".($range->getEvent() ? $range->getEvent()->getShortName() : null),
                false,
                $range->getEvent(),
                $range,
                null,
                $form->createView()
            );
        } catch (Exception $e) {
            $participant = $this->getEmptyParticipant($range);
            if (!isset($form)) {
                $form = $this->createForm(RegistrationFormType::class, $participant);
                $form->handleRequest($request);
            }
            $form->addError(new FormError('Nastala chyba. Zkuste to znovu nebo nás kontaktujte. '.$e->getMessage().''));
            $event = $range->getEvent();
            $eventName = $event ? $event->getShortName() : null;

            return $this->getResponse('form', "Přihláška na akci $eventName", false, $event, $range, null, $form->createView());
        }
    }

    /**
     * @return Response
     * @throws OswisNotFoundException
     */
    public function redirectToDefaultRange(): Response
    {
        $range = $this->getRange($this->eventService->getDefaultEvent(), null, ParticipantType::TYPE_ATTENDEE);
        if (null === $range) {
            throw new OswisNotFoundException('Termín pro vytváření přihlášek neexistuje.');
        }

        return $this->redirectToRoute('oswis_org_oswis_calendar_web_registration', ['rangeSlug' => $range->getSlug()]);
    }

    /**
     * Finds correct registration range by event and participantType.
     *
     * @param Event           $event           Event.
     * @param ParticipantType $participantType Type of participant.
     * @param string|null     $participantTypeString
     *
     * @return RegistrationsRange
     */
    public function getRange(Event $event, ?ParticipantType $participantType, ?string $participantTypeString): ?RegistrationsRange
    {
        return $this->registrationsRangeService->getRange($event, $participantType, $participantTypeString, true, true);
    }

    /**
     * Create empty eventParticipant for use in forms.
     *
     * @param RegistrationsRange   $range
     * @param AbstractContact|null $contact
     *
     * @return Participant
     * @throws InvalidArgumentException
     * @throws OswisException|OswisParticipantNotFoundException|EventCapacityExceededException
     */
    public function getEmptyParticipant(RegistrationsRange $range, ?AbstractContact $contact = null): Participant
    {
        if (null === $range->getEvent()) {
            throw new OswisParticipantNotFoundException('Registrační rozsah nelze použít, protože nemá přiřazenou událost.');
        }
        if (null === $range->getParticipantType()) {
            throw new OswisParticipantNotFoundException('Registrační rozsah nelze použít, protože nemá přiřazený typ účastníka.');
        }

        return new Participant(
            new ParticipantContactConnection($this->getContact($range->getEvent(), $contact)),
            new ParticipantRangeConnection($range),
            null,
            new ArrayCollection([new ParticipantNote()])
        );
    }

    /**
     * @param Event                $event
     * @param AbstractContact|null $contact
     *
     * @return AbstractContact
     * @throws InvalidArgumentException
     */
    public function getContact(Event $event, ?AbstractContact $contact = null): AbstractContact
    {
        $addressBook = $this->getAddressBook($event);
        $contactDetailTypeRepository = $this->em->getRepository(ContactDetailType::class);
        $detailTypeEmail = $contactDetailTypeRepository->findOneBy(['slug' => 'e-mail']);
        assert($detailTypeEmail instanceof ContactDetailType);
        $detailTypePhone = $contactDetailTypeRepository->findOneBy(['slug' => 'phone']);
        assert($detailTypePhone instanceof ContactDetailType);

        return $contact ?? new Person(
                null,
                null,
                Person::TYPE_PERSON,
                null,
                new ArrayCollection([new ContactDetail($detailTypeEmail), new ContactDetail($detailTypePhone)]),
                null,
                new ArrayCollection([new Position(null, null, Position::TYPE_STUDENT)]),
                null,
                null !== $addressBook ? new ArrayCollection([$addressBook]) : null
            );
    }

    public function getAddressBook(Event $event): ?AddressBook
    {
        $addressBook = $this->addressBookService->getRepository()->findOneBy(['slug' => $event->getSlug()]);
        if (null === $addressBook) {
            $addressBook = $this->addressBookService->create(
                new Nameable(
                    'Akce '.$event->getName(), $event->getShortName(), 'Automatický adresář pro akci '.$event->getName(), null, $event->getSlug()
                )
            );
        }

        return $addressBook;
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
        array $selectedFlags,
        bool $onlyPublic = true,
        bool $max = false
    ): void {
        $this->checkParticipantSuperEvent($range, $participant);
        $range->simulateParticipantAdd($max);
        $range->simulateFlagsAdd($selectedFlags, $onlyPublic, $max);
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
            $participantsOfContact = $this->participantService->getParticipants(
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

    public function extractSelectedFlags(RegistrationsRange $registrationsRange, FormInterface $form): array
    {
        $selectedFlags = new ArrayCollection();
        $formFlagsByType = $registrationsRange->getFlagsAggregatedByType(null, null, true, false);
        foreach ($formFlagsByType as $flagTypeSlug => $formFlagsOfType) {
            $oneFlag = $form["flag_$flagTypeSlug"] ? $form["flag_$flagTypeSlug"]->getData() : null;
            if ($oneFlag instanceof ParticipantFlag) {
                $selectedFlags->add($oneFlag);
            }
        }

        return FlagsAggregatedByType::getFlagsAggregatedByType($selectedFlags);
    }

    /**
     * @param string $eventSlug
     *
     * @return Event
     * @throws OswisNotFoundException
     */
    public function getEvent(string $eventSlug): Event
    {
        $event = $this->eventService->getRepository()->getEvent(
            [
                EventRepository::CRITERIA_ONLY_PUBLIC_ON_WEB => true,
                EventRepository::CRITERIA_SLUG               => $eventSlug,
            ]
        );
        if (null === $event) {
            throw new OswisNotFoundException('Akce nebyla nalezena.');
        }

        return $event;
    }

    /**
     * Renders page with list of registration ranges.
     *
     * If eventSlug is defined, renders page with registration ranges for this event and subEvents, if it's not defined, renders list for all events.
     *
     * @param string      $eventSlug       Slug for selected event.
     * @param string|null $participantType Restriction by participant type.
     *
     * @return Response Page with registration ranges.
     * @throws Exception Error occurred when getting events.
     */
    public function showRanges(string $eventSlug = null, ?string $participantType = null): Response
    {
        $event = $eventSlug ? $this->eventService->getEvents(null, null, null, null, null, $eventSlug, false)[0] ?? null : null;
        if (!empty($eventSlug) && empty($event)) {
            return $this->redirectToRoute('oswis_org_oswis_calendar_web_registration_ranges');
        }
        $events = $event instanceof Event ? new ArrayCollection([$event, ...$event->getSubEvents()]) : $this->eventService->getEvents(null, null, null, null, null, null, false);
        $shortTitle = 'Přihlášky';
        $title = $shortTitle.' na akc'.(null === $event ? 'e' : 'i '.$event->getShortName());
        $context = [
            'event'      => $event,
            'events'     => $this->registrationsRangeService->getEventRegistrationRanges($events, $participantType, true),
            'title'      => $title,
            'shortTitle' => $shortTitle,
        ];

        return $this->render('@OswisOrgOswisCalendar/web/pages/event-registration-ranges.html.twig', $context);
    }
}
