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
use OswisOrg\OswisCalendarBundle\Entity\Event\RegistrationsRange;
use OswisOrg\OswisCalendarBundle\Entity\EventParticipant\Participant;
use OswisOrg\OswisCalendarBundle\Entity\EventParticipant\ParticipantFlag;
use OswisOrg\OswisCalendarBundle\Entity\EventParticipant\ParticipantFlagConnection;
use OswisOrg\OswisCalendarBundle\Entity\EventParticipant\ParticipantFlagType;
use OswisOrg\OswisCalendarBundle\Entity\EventParticipant\ParticipantNote;
use OswisOrg\OswisCalendarBundle\Entity\EventParticipant\ParticipantType;
use OswisOrg\OswisCalendarBundle\Exception\EventCapacityExceededException;
use OswisOrg\OswisCalendarBundle\Form\Participant\RegistrationFormType;
use OswisOrg\OswisCalendarBundle\Provider\OswisCalendarSettingsProvider;
use OswisOrg\OswisCalendarBundle\Repository\EventRepository;
use OswisOrg\OswisCalendarBundle\Repository\ParticipantTypeRepository;
use OswisOrg\OswisCalendarBundle\Service\EventService;
use OswisOrg\OswisCalendarBundle\Service\ParticipantService;
use OswisOrg\OswisCalendarBundle\Service\ParticipantTypeService;
use OswisOrg\OswisCalendarBundle\Service\RegistrationService;
use OswisOrg\OswisCoreBundle\Entity\NonPersistent\Nameable;
use OswisOrg\OswisCoreBundle\Exceptions\OswisException;
use OswisOrg\OswisCoreBundle\Exceptions\OswisNotFoundException;
use OswisOrg\OswisCoreBundle\Exceptions\PriceInvalidArgumentException;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Encoder\UserPasswordEncoderInterface;
use function assert;

class RegistrationController extends AbstractController
{
    public EntityManagerInterface $em;

    public LoggerInterface $logger;

    public UserPasswordEncoderInterface $encoder;

    public RegistrationService $registrationService;

    public ParticipantTypeService $participantTypeService;

    public AddressBookService $addressBookService;

    public EventService $eventService;

    public OswisCalendarSettingsProvider $calendarSettings;

    public function __construct(
        EntityManagerInterface $em,
        LoggerInterface $logger,
        UserPasswordEncoderInterface $encoder,
        EventService $eventService,
        ParticipantTypeService $participantTypeService,
        RegistrationService $registrationService,
        AddressBookService $addressBookService,
        OswisCalendarSettingsProvider $calendarSettings
    ) {
        $this->em = $em;
        $this->logger = $logger;
        $this->encoder = $encoder;
        $this->eventService = $eventService;
        $this->participantTypeService = $participantTypeService;
        $this->addressBookService = $addressBookService;
        $this->registrationService = $registrationService;
        $this->calendarSettings = $calendarSettings;
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
            $participant = $this->getParticipantService()->getRepository()->findOneBy(['id' => $participantId]);
            if (null === $participant || null === $participant->getContact()) {
                $error = null === $participant ? ', přihláška nenalezena' : '';
                $error .= !($participant->getContact() instanceof AbstractContact) ? ', účastník nenalezen' : '';

                return $this->render(
                    '@OswisOrgOswisCalendar/web/pages/event-participant-registration-confirmation.html.twig',
                    array(
                        'type'    => 'error',
                        'title'   => 'Chyba!',
                        'event'   => $participant->getRegistrationsRange(),
                        'message' => "Aktivace se nezdařila. Kontaktujte nás, prosím. (token $token, přihláška č. $participantId$error)",
                    )
                );
            }
            $participant->removeEmptyParticipantNotes();
            if (null !== $participant->getContact()) {
                $participant->getContact()->removeEmptyDetails();
                $participant->getContact()->removeEmptyNotes();
            }
            $this->getParticipantService()->sendMail($participant, true, $token);

            return $this->render(
                '@OswisOrgOswisCalendar/web/pages/event-participant-registration-confirmation.html.twig',
                [
                    'type'    => 'success',
                    'title'   => 'Hotovo!',
                    'event'   => $participant->getRegistrationsRange(),
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

    public function getParticipantService(): ParticipantService
    {
        return $this->registrationService->getParticipantService();
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
    public function registration(Request $request, ?string $eventSlug = null, ?string $participantSlug = null): Response
    {
        $defaultEvent = empty($eventSlug) ? $this->eventService->getDefaultEvent() : null;
        if (null !== $defaultEvent) {
            return $this->redirectToRoute('oswis_org_oswis_calendar_web_participant_registration', ['eventSlug' => $defaultEvent->getSlug()]);
        }
        if (null === $eventSlug) {
            return $this->redirectToRoute('oswis_org_oswis_calendar_web_event_registrations');
        }
        $event = $this->getEvent($eventSlug);
        $participant = $this->prepareParticipant($event, $this->getParticipantType($participantSlug));
        $participantType = $participant->getParticipantType();
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
            $form = $this->createForm(RegistrationFormType::class, $participant);
            $form->handleRequest($request);
            if ($form->isSubmitted() && $form->isValid()) {
                $participant = $form->getData();
                if (null === $participant || !($participant instanceof Participant)) {
                    throw new OswisException('Přihláška není kompletní nebo je poškozená. (1: $eventParticipant)');
                }
                $this->em->persist($participant);
                $contact = $participant->getContact();
                if (!$participant->getRegistrationsRange()) {
                    throw new OswisException('Přihláška není kompletní nebo je poškozená. (1: Event)');
                }
                if (!($contact instanceof Person)) { // TODO: Organization?
                    throw new OswisException('Přihláška není kompletní nebo je poškozená. (1: Contact)');
                }
                $participant->removeEmptyParticipantNotes();
                $contact->addNote(new ContactNote('Vytvořeno k přihlášce na akci ('.$event->getName().').'));
                $contact->removeEmptyDetails();
                $contact->removeEmptyNotes();
                $this->processFlags($participant, $form);
                $this->registrationService->simulateRegistration($participant);
                $this->getParticipantService()->sendMail($participant, true);

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
            $participant = $this->prepareParticipant($event);
            if (!isset($form)) {
                $form = $this->createForm(RegistrationFormType::class, $participant);
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
                    'registrationsActive' => $event->isRegistrationsAllowed($participant->getParticipantType()),
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
     * @param Event           $event
     * @param ParticipantType $participantType
     *
     * @return Participant
     * @throws EventCapacityExceededException
     * @throws InvalidArgumentException
     */
    public function prepareParticipant(Event $event, ?ParticipantType $participantType = null): Participant
    {
        $contactDetailTypeRepository = $this->em->getRepository(ContactDetailType::class);
        assert($contactDetailTypeRepository instanceof ContactDetailTypeRepository);
        $addressBook = $this->getAddressBook($event);
        $participantType ??= $event->getParticipantTypes(ParticipantType::TYPE_ATTENDEE)[0] ?? null;
        assert($participantType instanceof ParticipantType);
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

        return new Participant(
            $participantPerson, $event, $participantType, null, new ArrayCollection([new ParticipantNote()])
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

    public function getParticipantType(?string $slug): ?ParticipantType
    {
        if (empty($slug)) {
            return null;
        }
        $opts = [
            ParticipantTypeRepository::CRITERIA_ONLY_PUBLIC_ON_WEB => true,
            ParticipantTypeRepository::CRITERIA_SLUG               => $slug,
        ];
        $type = $this->participantTypeService->getRepository()->getParticipantType($opts);
        if (null === $type) {
            $opts = [
                ParticipantTypeRepository::CRITERIA_ONLY_PUBLIC_ON_WEB => true,
                ParticipantTypeRepository::CRITERIA_TYPE_OF_TYPE       => $slug,
            ];
            $type = $this->participantTypeService->getRepository()->getParticipantType($opts);
        }

        return $type;
    }

    /**
     * Finds correct registration range from event by eventParticipantType and reference date and time.
     *
     * @param Event           $event           Event.
     * @param ParticipantType $participantType Type of participant.
     * @param DateTime|null   $dateTime        Reference date and time.
     *
     * @return RegistrationsRange
     * @throws PriceInvalidArgumentException
     */
    public function getRange(Event $event, ?ParticipantType $participantType, ?DateTime $dateTime = null): RegistrationsRange
    {
        $ranges = $event->getRegistrationRanges($participantType, $dateTime)->filter(fn(RegistrationsRange $r) => $r->isPublicOnWeb());
        if ($ranges->count() < 1 || !($ranges->first() instanceof RegistrationsRange)) {
            throw new PriceInvalidArgumentException('Přihlášky na tuto akci nyní nejsou povoleny.');
        }

        return $ranges->first();
    }

    /**
     * @param Participant   $participant
     * @param FormInterface $form
     *
     * @throws EventCapacityExceededException
     */
    public function processFlags(Participant $participant, FormInterface $form): void
    {
        $allowedFlagsByType = $participant->getRegistrationsRange() ? $participant->getRegistrationsRange()->getAllowedFlagsAggregatedByType($participant->getParticipantType()) : [];
        foreach ($allowedFlagsByType as $flagsRow) {
            $flagTypeSlug = $flagsRow['flagType'] && $flagsRow['flagType'] instanceof ParticipantFlagType ? $flagsRow['flagType']->getSlug() : 0;
            $oneFlag = $form["flag_$flagTypeSlug"] ? $form["flag_$flagTypeSlug"]->getData() : null;
            if ($oneFlag instanceof ParticipantFlag) {
                $participant->addParticipantFlagConnection(new ParticipantFlagConnection($oneFlag));
            }
        }
    }


}
