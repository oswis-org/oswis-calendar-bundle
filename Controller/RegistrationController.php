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
use OswisOrg\OswisAddressBookBundle\Entity\Person;
use OswisOrg\OswisAddressBookBundle\Entity\Position;
use OswisOrg\OswisAddressBookBundle\Service\AddressBookService;
use OswisOrg\OswisCalendarBundle\Entity\Event\Event;
use OswisOrg\OswisCalendarBundle\Entity\Event\RegistrationRange;
use OswisOrg\OswisCalendarBundle\Entity\NonPersistent\FlagsByType;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantContact;
use OswisOrg\OswisCalendarBundle\Entity\Participant\RegistrationFlag;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantNote;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantRange;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantCategory;
use OswisOrg\OswisCalendarBundle\Exception\EventCapacityExceededException;
use OswisOrg\OswisCalendarBundle\Exception\ParticipantNotFoundException;
use OswisOrg\OswisCalendarBundle\Form\Participant\RegistrationFormType;
use OswisOrg\OswisCalendarBundle\Repository\EventRepository;
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
                $error .= !($participant->getContact() instanceof AbstractContact) ? ', kontakt nenalezen' : '';
                $message = "Aktivace se nezdařila. Kontaktujte nás, prosím. (token $token, přihláška č. $participantId$error)";

                return $this->getResponse('error', 'Chyba!', true, $participant->getEvent(), null, $message);
            }
            $this->participantService->verify($participant, $token);

            return $this->getResponse('success', 'Hotovo!', true, $participant->getEvent(), null, 'Ověření uživatele proběhlo úspěšně.');
        } catch (Exception $e) {
            $this->logger->notice('OSWIS_CONFIRM_ERROR: '.$e->getMessage());
            $message = 'Registraci a přihlášku se nepodařilo potvrdit. Kontaktujte nás a společně to vyřešíme.';

            return $this->getResponse('error', 'Neočekávaná chyba!', true, null, null, $message);
        }
    }

    public function getResponse(
        ?string $type,
        string $title,
        bool $verification = false,
        ?Event $event = null,
        ?RegistrationRange $range = null,
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
     * @throws OswisException|OswisNotFoundException|ParticipantNotFoundException|EventCapacityExceededException
     */
    public function registration(Request $request, ?string $rangeSlug = null): Response
    {
        if (null === $rangeSlug) {
            return $this->redirectToDefaultEventRanges();
        }
        $range = $this->registrationsRangeService->getRangeBySlug($rangeSlug, true, true);
        if (null === $range || !($range instanceof RegistrationRange) || !$range->isPublicOnWeb()) {
            throw new OswisNotFoundException('Rozsah pro vytváření přihlášek nebyl nalezen.');
        }
        $participant = $this->getEmptyParticipant($range, null);
        try {
            $form = $this->createForm(RegistrationFormType::class, $participant);
            $form->handleRequest($request);
            if ($form->isSubmitted() && $form->isValid()) {
                $participant = $form->getData();
                assert($participant instanceof Participant);
                $participant = $this->participantService->create(
                    $participant,
                    $this->extractSelectedFlags($participant->getRange(), $form),
                    true
                );
                $eventName = $participant->getEvent() ? $participant->getEvent()->getShortName() : null;

                return $this->getResponse(
                    'success',
                    'Přihláška odeslána!',
                    false,
                    $participant->getEvent(),
                    $participant->getRange(),
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
    public function redirectToDefaultEventRanges(): Response
    {
        $defaultEvent = $this->eventService->getDefaultEvent();
        if (null === $defaultEvent) {
            throw new OswisNotFoundException('Výchozí událost nebyla nastavena. Vyberte konkrétní akci.');
        }

        return $this->redirectToRoute(
            'oswis_org_oswis_calendar_web_registration_ranges',
            ['eventSlug' => $defaultEvent->getSlug(), 'participantType' => ParticipantCategory::TYPE_ATTENDEE]
        );
    }

    /**
     * Create empty eventParticipant for use in forms.
     *
     * @param RegistrationRange    $range
     * @param AbstractContact|null $contact
     *
     * @return Participant
     * @throws InvalidArgumentException
     * @throws OswisException|ParticipantNotFoundException|EventCapacityExceededException
     */
    public function getEmptyParticipant(RegistrationRange $range, ?AbstractContact $contact = null): Participant
    {
        if (null === $range->getEvent()) {
            throw new ParticipantNotFoundException('Registrační rozsah nelze použít, protože nemá přiřazenou událost.');
        }
        if (null === $range->getParticipantType()) {
            throw new ParticipantNotFoundException('Registrační rozsah nelze použít, protože nemá přiřazený typ účastníka.');
        }

        return new Participant(
            new ParticipantContact($this->getContact($range->getEvent(), $contact)),
            new ParticipantRange($range),
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

    public function extractSelectedFlags(RegistrationRange $registrationsRange, FormInterface $form): array
    {
        $selectedFlags = new ArrayCollection();
        $formFlagsByType = $registrationsRange->getFlagsAggregatedByType(null, null, true, false);
        foreach ($formFlagsByType as $flagTypeSlug => $formFlagsOfType) {
            $oneFlag = $form["flag_$flagTypeSlug"] ? $form["flag_$flagTypeSlug"]->getData() : null;
            if ($oneFlag instanceof RegistrationFlag) {
                $selectedFlags->add($oneFlag);
            }
        }

        return FlagsByType::getFlagsAggregatedByType($selectedFlags);
    }

    /**
     * Finds correct registration range by event and participantType.
     *
     * @param Event               $event           Event.
     * @param ParticipantCategory $participantType Type of participant.
     * @param string|null         $participantTypeString
     *
     * @return RegistrationRange
     */
    public function getRange(Event $event, ?ParticipantCategory $participantType, ?string $participantTypeString): ?RegistrationRange
    {
        return $this->registrationsRangeService->getRange($event, $participantType, $participantTypeString, true, true);
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
