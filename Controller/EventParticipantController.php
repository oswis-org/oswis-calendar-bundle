<?php
/**
 * @noinspection MethodShouldBeFinalInspection
 * @noinspection RedundantDocCommentTagInspection
 * @noinspection PhpUnused
 */

namespace OswisOrg\OswisCalendarBundle\Controller;

use DateTime;
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
use OswisOrg\OswisAddressBookBundle\Repository\ContactDetailTypeRepository;
use OswisOrg\OswisAddressBookBundle\Service\AddressBookService;
use OswisOrg\OswisCalendarBundle\Entity\Event\Event;
use OswisOrg\OswisCalendarBundle\Entity\Event\EventRegistrationRange;
use OswisOrg\OswisCalendarBundle\Entity\EventParticipant\EventParticipant;
use OswisOrg\OswisCalendarBundle\Entity\EventParticipant\EventParticipantFlag;
use OswisOrg\OswisCalendarBundle\Entity\EventParticipant\EventParticipantFlagNewConnection;
use OswisOrg\OswisCalendarBundle\Entity\EventParticipant\EventParticipantFlagType;
use OswisOrg\OswisCalendarBundle\Entity\EventParticipant\EventParticipantNote;
use OswisOrg\OswisCalendarBundle\Entity\EventParticipant\EventParticipantType;
use OswisOrg\OswisCalendarBundle\Exception\EventCapacityExceededException;
use OswisOrg\OswisCalendarBundle\Provider\OswisCalendarSettingsProvider;
use OswisOrg\OswisCalendarBundle\Repository\EventParticipantTypeRepository;
use OswisOrg\OswisCalendarBundle\Repository\EventRepository;
use OswisOrg\OswisCalendarBundle\Service\EventParticipantService;
use OswisOrg\OswisCalendarBundle\Service\EventParticipantTypeService;
use OswisOrg\OswisCalendarBundle\Service\EventService;
use OswisOrg\OswisCoreBundle\Entity\NonPersistent\Nameable;
use OswisOrg\OswisCoreBundle\Exceptions\OswisException;
use OswisOrg\OswisCoreBundle\Exceptions\OswisNotFoundException;
use OswisOrg\OswisCoreBundle\Exceptions\PriceInvalidArgumentException;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Encoder\UserPasswordEncoderInterface;
use function assert;

class EventParticipantController extends AbstractController
{
    public EntityManagerInterface $em;

    public LoggerInterface $logger;

    public UserPasswordEncoderInterface $encoder;

    public EventParticipantService $participantService;

    public EventParticipantTypeService $participantTypeService;

    public AddressBookService $addressBookService;

    public EventService $eventService;

    public OswisCalendarSettingsProvider $calendarSettings;

    public function __construct(
        EntityManagerInterface $em,
        LoggerInterface $logger,
        UserPasswordEncoderInterface $encoder,
        EventService $eventService,
        EventParticipantTypeService $participantTypeService,
        AddressBookService $addressBookService,
        OswisCalendarSettingsProvider $calendarSettings
    ) {
        $this->em = $em;
        $this->logger = $logger;
        $this->encoder = $encoder;
        $this->eventService = $eventService;
        $this->participantTypeService = $participantTypeService;
        $this->addressBookService = $addressBookService;
        $this->participantService = $eventService->getEventParticipantService();
        $this->calendarSettings = $calendarSettings;
    }

    /**
     * Process registration verification and appUser account activation.
     *
     * @param string $token
     * @param int    $eventParticipantId
     *
     * @return Response
     */
    public function eventParticipantRegistrationConfirmAction(string $token, int $eventParticipantId): Response
    {
        // TODO: Check and refactor.
        try {
            if (empty($token) || empty($eventParticipantId)) {
                return $this->render(
                    '@OswisOrgOswisCalendar/web/pages/event-participant-registration-confirmation.html.twig',
                    [
                        'type'    => 'error',
                        'title'   => 'Chyba! URL nekompletní!',
                        'message' => 'Formát adresy pro ověření je chybný.
                            Zkuste odkaz otevřít znovu nebo jej zkopírovat celý do adresního řádku prohlížeče.
                            Pokud se to nepodaří, kontaktujte nás a společně to vyřešíme.',
                    ]
                );
            }
            $eventParticipant = $this->participantService->getRepository()->findOneBy(['id' => $eventParticipantId]);
            if (null === $eventParticipant || null === $eventParticipant->getContact()) {
                $error = null === $eventParticipant ? ', přihláška nenalezena' : '';
                $error .= !($eventParticipant->getContact() instanceof AbstractContact) ? ', účastník nenalezen' : '';

                return $this->render(
                    '@OswisOrgOswisCalendar/web/pages/event-participant-registration-confirmation.html.twig',
                    array(
                        'type'    => 'error',
                        'title'   => 'Chyba!',
                        'event'   => $eventParticipant->getEvent(),
                        'message' => "Aktivace se nezdařila. Kontaktujte nás, prosím. (token $token, přihláška č. $eventParticipantId$error)",
                    )
                );
            }
            $eventParticipant->removeEmptyEventParticipantNotes();
            if (null !== $eventParticipant->getContact()) {
                $eventParticipant->getContact()->removeEmptyDetails();
                $eventParticipant->getContact()->removeEmptyNotes();
            }
            $this->participantService->sendMail($eventParticipant, true, $token);

            return $this->render(
                '@OswisOrgOswisCalendar/web/pages/event-participant-registration-confirmation.html.twig',
                [
                    'type'    => 'success',
                    'title'   => 'Hotovo!',
                    'event'   => $eventParticipant->getEvent(),
                    'message' => 'Ověření uživatele proběhlo úspěšně.',
                ]
            );
        } catch (Exception $e) {
            $this->logger->notice('OSWIS_CONFIRM_ERROR: '.$e->getMessage());

            return $this->render(
                '@OswisOrgOswisCalendar/web/pages/event-participant-registration-confirmation.html.twig',
                [
                    'type'    => 'error',
                    'title'   => 'Neočekávaná chyba!',
                    'message' => 'Registraci a přihlášku se nepodařilo potvrdit. Kontaktujte nás a společně to vyřešíme.',
                ]
            );
        }
    }

    /**
     * Partners for homepage.
     * @return Response
     */
    public function partnersFooter(): Response
    {
        return $this->render(
            '@OswisOrgOswisCalendar/web/parts/partners-footer.html.twig',
            [
                'footerPartners' => $this->participantService->getEventWebPartners(),
            ]
        );
    }

    /**
     * Show or process registration form.
     *
     * Route shows registration form or process it if form was sent.
     * Data from form is validated, user is created and than summary and activation e-mail is sent.
     *
     * @param Request     $request
     * @param string      $eventSlug
     * @param string|null $participantSlug
     *
     * @return Response
     * @throws EventCapacityExceededException
     * @throws InvalidArgumentException
     * @throws OswisNotFoundException
     */
    final public function eventParticipantRegistration(Request $request, ?string $eventSlug = null, ?string $participantSlug = null): Response
    {
        $defaultEvent = empty($eventSlug) ? $this->eventService->getDefaultEvent() : null;
        if (null !== $defaultEvent) {
            return $this->redirectToRoute('oswis_org_oswis_calendar_web_event_participant_registration', ['eventSlug' => $defaultEvent->getSlug()]);
        }
        if (null === $eventSlug) {
            return $this->redirectToRoute('oswis_org_oswis_calendar_web_event_registrations');
        }
        $event = $this->getEvent($eventSlug);
        $participant = $this->prepareEventParticipant($event, $this->getParticipantType($participantSlug));
        $participantType = $participant->getEventParticipantType();
        try {
            $range = $this->getRange($event, $participantType);
        } catch (PriceInvalidArgumentException $exception) {
            return $this->redirectToRoute(
                'oswis_org_oswis_calendar_web_event_registrations',
                [
                    'eventSlug'       => $event->getSlug(),
                    'participantType' => $participantType ? $participantType->getType() : null,
                ]
            );
        }
        try {
            $form = $this->createForm(\OswisOrg\OswisCalendarBundle\Form\EventParticipant\EventParticipantType::class, $participant);
            $form->handleRequest($request);
            if ($form->isSubmitted() && $form->isValid()) {
                $participant = $form->getData();
                assert($participant instanceof EventParticipant);
                $this->em->persist($participant);
                $person = $participant->getContact();
                assert($person instanceof Person);
                if (empty($participant)) {
                    throw new OswisException('Přihláška není kompletní nebo je poškozená. (1: $eventParticipant)');
                }
                if (!$participant->getEvent()) {
                    throw new OswisException('Přihláška není kompletní nebo je poškozená. (1: Event)');
                }
                if (!$participant->getContact()) {
                    throw new OswisException('Přihláška není kompletní nebo je poškozená. (1: Contact)');
                }
                $participant->getContact()->addNote(new ContactNote('Vytvořeno k přihlášce na akci ('.$event->getName().').'));
                $participant->removeEmptyEventParticipantNotes();
                $participant->getContact()->removeEmptyDetails();
                $participant->getContact()->removeEmptyNotes();
                $flagsRows = $participant->getEvent()->getAllowedFlagsAggregatedByType($participant->getEventParticipantType());
                foreach ($flagsRows as $flagsRow) {
                    $flagType = $flagsRow['flagType'];
                    assert($flagType instanceof EventParticipantFlagType);
                    $oneFlag = $form['flag_'.$flagType->getSlug()]->getData();
                    assert($oneFlag instanceof EventParticipantFlag);
                    if (null !== $oneFlag) {
                        $participant->addEventParticipantFlagConnection(new EventParticipantFlagNewConnection($oneFlag));
                    }
                }
                $this->participantService->sendMail($participant, true);

                return $this->render(
                    '@OswisOrgOswisCalendar/web/pages/event-participant-registration-form.html.twig',
                    [
                        'form'                => $form->createView(),
                        'title'               => 'Přihláška odeslána!',
                        'event'               => $event,
                        'pageTitle'           => 'Přihláška odeslána!',
                        'message'             => 'Tvoje přihláška byla úspěšně odeslána! Nyní je ovšem ještě nutné ji potvrdit kliknutím na odkaz v e-mailu, který jsme Ti právě zaslali.',
                        'type'                => 'success',
                        'registrationsActive' => true,
                    ]
                );
            }

            return $this->render(
                '@OswisOrgOswisCalendar/web/pages/event-participant-registration-form.html.twig',
                [
                    'form'                => $form->createView(),
                    'title'               => 'Přihlaš se na Seznamovák UP právě teď!',
                    'range'               => $range,
                    'event'               => $event,
                    'pageTitle'           => 'Přihláška na Seznamovák UP',
                    'message'             => '',
                    'type'                => 'form',
                    'registrationsActive' => true,
                    // 'year'       => $actualYear,
                    // 'verifyCode' => $verifyCodeNow,
                ]
            );
        } catch (Exception $e) {
            $participant = $this->prepareEventParticipant($event);
            if (!isset($form)) {
                $form = $this->createForm(\OswisOrg\OswisCalendarBundle\Form\EventParticipant\EventParticipantType::class, $participant);
                $form->handleRequest($request);
            }
            $form->addError(new FormError('Nastala chyba. Zkuste to znovu nebo nás kontaktujte.  '.$e->getMessage().''));

            return $this->render(
                '@OswisOrgOswisCalendar/web/pages/event-participant-registration-form.html.twig',
                [
                    'form'                => $form->createView(),
                    'title'               => 'Přihláška na akci '.$event->getName(),
                    'pageTitle'           => 'Přihláška na akci '.$event->getName(),
                    'event'               => $event,
                    'type'                => 'form',
                    'registrationsActive' => $event->isRegistrationsAllowed($participant->getEventParticipantType()),
                ]
            );
        }
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
     * Create empty eventParticipant for use in forms.
     *
     * @param Event                $event
     * @param EventParticipantType $participantType
     *
     * @return EventParticipant
     * @throws EventCapacityExceededException
     * @throws InvalidArgumentException
     */
    public function prepareEventParticipant(Event $event, ?EventParticipantType $participantType = null): EventParticipant
    {
        $contactDetailTypeRepository = $this->em->getRepository(ContactDetailType::class);
        assert($contactDetailTypeRepository instanceof ContactDetailTypeRepository);
        $addressBook = $this->getAddressBook($event);
        $participantType ??= $event->getParticipantTypes(EventParticipantType::TYPE_ATTENDEE)[0] ?? null;
        assert($participantType instanceof EventParticipantType);
        $contactDetailTypeEmail = $contactDetailTypeRepository->findOneBy(['slug' => 'e-mail']);
        $contactDetailTypePhone = $contactDetailTypeRepository->findOneBy(['slug' => 'phone']);
        $participantPerson = new Person(
            null,
            null,
            Person::TYPE_PERSON,
            null,
            new ArrayCollection([new ContactDetail($contactDetailTypeEmail), new ContactDetail($contactDetailTypePhone)]),
            null,
            new ArrayCollection([new Position(null, null, Position::TYPE_STUDENT)]),
            null,
            null !== $addressBook ? new ArrayCollection([$addressBook]) : null
        );

        return new EventParticipant(
            $participantPerson, $event, $participantType, null, new ArrayCollection([new EventParticipantNote()])
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

    public function getParticipantType(?string $slug): ?EventParticipantType
    {
        if (empty($slug)) {
            return null;
        }
        $opts = [
            EventParticipantTypeRepository::CRITERIA_ONLY_PUBLIC_ON_WEB => true,
            EventParticipantTypeRepository::CRITERIA_SLUG               => $slug,
        ];
        $type = $this->participantTypeService->getRepository()->getEventParticipantType($opts);
        if (null === $type) {
            $opts = [
                EventParticipantTypeRepository::CRITERIA_ONLY_PUBLIC_ON_WEB => true,
                EventParticipantTypeRepository::CRITERIA_TYPE_OF_TYPE       => $slug,
            ];
            $type = $this->participantTypeService->getRepository()->getEventParticipantType($opts);
        }

        return $type;
    }

    /**
     * Finds correct registration range from event by eventParticipantType and reference date and time.
     *
     * @param Event                $event           Event.
     * @param EventParticipantType $participantType Type of participant.
     * @param DateTime|null        $dateTime        Reference date and time.
     *
     * @return EventRegistrationRange
     * @throws PriceInvalidArgumentException
     */
    public function getRange(Event $event, ?EventParticipantType $participantType, ?DateTime $dateTime = null): EventRegistrationRange
    {
        $ranges = $event->getRegistrationRanges($participantType, $dateTime)->filter(fn(EventRegistrationRange $r) => $r->isPublicOnWeb());
        if ($ranges->count() < 1 || !($ranges->first() instanceof EventRegistrationRange)) {
            throw new PriceInvalidArgumentException('Přihlášky na tuto akci nyní nejsou povoleny.');
        }

        return $ranges->first();
    }
}
