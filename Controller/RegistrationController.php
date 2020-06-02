<?php
/**
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
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantFlag;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantFlagType;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantNote;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantType;
use OswisOrg\OswisCalendarBundle\Exception\EventCapacityExceededException;
use OswisOrg\OswisCalendarBundle\Exception\OswisParticipantNotFoundException;
use OswisOrg\OswisCalendarBundle\Form\Participant\RegistrationFormType;
use OswisOrg\OswisCalendarBundle\Provider\OswisCalendarSettingsProvider;
use OswisOrg\OswisCalendarBundle\Repository\EventRepository;
use OswisOrg\OswisCalendarBundle\Repository\RegistrationsRangeRepository;
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
     * @param string|null $rangeSlug
     *
     * @return Response
     * @throws EventCapacityExceededException
     * @throws InvalidArgumentException
     * @throws OswisNotFoundException
     * @throws OswisParticipantNotFoundException
     */
    public function registration(Request $request, ?string $rangeSlug = null): Response
    {
        if (null === $rangeSlug) {
            return $this->redirectToDefaultRange();
        }
        $range = $this->getRangeBySlug($rangeSlug);
        if (null === $range) {
            throw new OswisNotFoundException('Rozsah pro vytváření přihlášek nebyl nalezen.');
        }
        $participant = $this->getEmptyParticipant($range, null);
        try {
            $form = $this->createForm(RegistrationFormType::class, $participant);
            $form->handleRequest($request);
            if ($form->isSubmitted() && $form->isValid()) {
                $participant = $form->getData();
                if (null === $participant || !($participant instanceof Participant) || null === ($range = $participant->getRegistrationsRange())) {
                    throw new OswisException('Přihláška není kompletní nebo je poškozená.');
                }
                $this->em->persist($participant);
                if (null === ($event = $participant->getEvent())) {
                    throw new OswisException('Přihláška není kompletní nebo je poškozená. Není vybrána žádná událost.');
                }
                if (!(($contact = $participant->getContact()) instanceof Person)) { // TODO: Organization?
                    throw new OswisException('Přihláška není kompletní nebo je poškozená. V přihlášce chybí kontakt.');
                }
                $participant->removeEmptyParticipantNotes();
                $contact->addNote(new ContactNote('Vytvořeno k přihlášce na akci ('.$event->getName().').'));
                $contact->removeEmptyDetails();
                $contact->removeEmptyNotes();
                $selectedFlags = $this->extractSelectedFlags($participant, $form);
                $range->simulateAdd($selectedFlags, null, null);
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
            $participant = $this->getEmptyParticipant($event);
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
     * @param RegistrationsRange   $range
     *
     * @param AbstractContact|null $contact
     *
     * @return Participant
     * @throws EventCapacityExceededException
     * @throws InvalidArgumentException
     * @throws OswisParticipantNotFoundException
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
            $this->getContact($range->getEvent(), $contact), $range, null, new ArrayCollection([new ParticipantNote()])
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
        $contactDetailTypeEmail = $contactDetailTypeRepository->findOneBy(['slug' => 'e-mail']);
        $contactDetailTypePhone = $contactDetailTypeRepository->findOneBy(['slug' => 'phone']);

        return $contact ?? new Person(
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

    /**
     * Finds correct registration range by event and participantType.
     *
     * @param Event           $event           Event.
     * @param ParticipantType $participantType Type of participant.
     *
     * @return RegistrationsRange
     */
    public function getRange(Event $event, ?ParticipantType $participantType): RegistrationsRange
    {
        return $this->registrationService->getRepository()->getRegistrationsRange(
            [
                RegistrationsRangeRepository::CRITERIA_EVENT            => $event,
                RegistrationsRangeRepository::CRITERIA_PARTICIPANT_TYPE => $participantType,
                RegistrationsRangeRepository::CRITERIA_ONLY_ACTIVE      => true,
                RegistrationsRangeRepository::CRITERIA_PUBLIC_ON_WEB    => true,
            ]
        );
    }

    public function getRangeBySlug(string $rangeSlug): RegistrationsRange
    {
        return $this->registrationService->getRepository()->getRegistrationsRange(
            [
                RegistrationsRangeRepository::CRITERIA_SLUG          => $rangeSlug,
                RegistrationsRangeRepository::CRITERIA_ONLY_ACTIVE   => true,
                RegistrationsRangeRepository::CRITERIA_PUBLIC_ON_WEB => true,
            ]
        );
    }

    /**
     * @return Response
     * @throws OswisNotFoundException
     */
    public function redirectToDefaultRange(): Response
    {
        $range = $this->registrationService->getRepository()->getRegistrationsRange(
            [
                RegistrationsRangeRepository::CRITERIA_EVENT                   => $this->eventService->getDefaultEvent(),
                RegistrationsRangeRepository::CRITERIA_PARTICIPANT_TYPE_STRING => ParticipantType::TYPE_ATTENDEE,
                RegistrationsRangeRepository::CRITERIA_ONLY_ACTIVE             => true,
                RegistrationsRangeRepository::CRITERIA_PUBLIC_ON_WEB           => true,
            ]
        );
        if (null === $range || empty($range->getSlug())) {
            throw new OswisNotFoundException('Výchozí rozsah pro vytváření přihlášek nebyl nalezen.');
        }

        return $this->redirectToRoute('oswis_org_oswis_calendar_web_registration', ['rangeSlug' => $range->getSlug()]);
    }

    public function extractSelectedFlags(RegistrationsRange $registrationsRange, FormInterface $form): array
    {
        $selectedFlags = new ArrayCollection();
        $formFlagsByType = $registrationsRange->getFlagsAggregatedByType(true, false);
        foreach ($formFlagsByType as $formFlagsOfType) {
            $flagTypeSlug = $formFlagsOfType['flagType'] && $formFlagsOfType['flagType'] instanceof ParticipantFlagType ? $formFlagsOfType['flagType']->getSlug() : 0;
            $oneFlag = $form["flag_$flagTypeSlug"] ? $form["flag_$flagTypeSlug"]->getData() : null;
            if ($oneFlag instanceof ParticipantFlag) {
                $selectedFlags->add($oneFlag);
            }
        }

        return FlagsAggregatedByType::getFlagsAggregatedByType($selectedFlags);
    }

}
