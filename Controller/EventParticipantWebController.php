<?php /** @noinspection PhpUnusedParameterInspection */

/**
 * @noinspection MethodShouldBeFinalInspection
 * @noinspection RedundantDocCommentTagInspection
 * @noinspection PhpUnused
 */

namespace Zakjakub\OswisCalendarBundle\Controller;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use InvalidArgumentException;
use LogicException;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Form;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Encoder\UserPasswordEncoderInterface;
use Zakjakub\OswisAddressBookBundle\Entity\AbstractClass\AbstractContact;
use Zakjakub\OswisAddressBookBundle\Entity\AddressBook\AddressBook;
use Zakjakub\OswisAddressBookBundle\Entity\ContactDetail;
use Zakjakub\OswisAddressBookBundle\Entity\ContactNote;
use Zakjakub\OswisAddressBookBundle\Entity\Person;
use Zakjakub\OswisAddressBookBundle\Entity\Position;
use Zakjakub\OswisAddressBookBundle\Form\ContactDetailType;
use Zakjakub\OswisAddressBookBundle\Repository\ContactDetailTypeRepository;
use Zakjakub\OswisAddressBookBundle\Service\AddressBookService;
use Zakjakub\OswisCalendarBundle\Entity\Event\Event;
use Zakjakub\OswisCalendarBundle\Entity\EventParticipant\EventParticipant;
use Zakjakub\OswisCalendarBundle\Entity\EventParticipant\EventParticipantFlag;
use Zakjakub\OswisCalendarBundle\Entity\EventParticipant\EventParticipantFlagNewConnection;
use Zakjakub\OswisCalendarBundle\Entity\EventParticipant\EventParticipantNote;
use Zakjakub\OswisCalendarBundle\Entity\EventParticipant\EventParticipantType;
use Zakjakub\OswisCalendarBundle\Exception\EventCapacityExceededException;
use Zakjakub\OswisCalendarBundle\Repository\EventRepository;
use Zakjakub\OswisCalendarBundle\Service\EventParticipantService;
use Zakjakub\OswisCalendarBundle\Service\EventParticipantTypeService;
use Zakjakub\OswisCalendarBundle\Service\EventService;
use Zakjakub\OswisCoreBundle\Entity\Nameable;
use Zakjakub\OswisCoreBundle\Exceptions\OswisException;
use Zakjakub\OswisCoreBundle\Exceptions\OswisNotFoundException;
use Zakjakub\OswisCoreBundle\Utils\SpamDateTimeEncoder;
use function assert;

class EventParticipantWebController extends AbstractController
{
    public EntityManagerInterface $em;

    public LoggerInterface $logger;

    public UserPasswordEncoderInterface $encoder;

    public EventParticipantService $participantService;

    public EventParticipantTypeService $participantTypeService;

    public AddressBookService $addressBookService;

    public EventService $eventService;

    public function __construct(
        EntityManagerInterface $em,
        LoggerInterface $logger,
        UserPasswordEncoderInterface $encoder,
        EventService $eventService,
        EventParticipantTypeService $participantTypeService,
        AddressBookService $addressBookService
    ) {
        $this->em = $em;
        $this->logger = $logger;
        $this->encoder = $encoder;
        $this->eventService = $eventService;
        $this->participantTypeService = $participantTypeService;
        $this->addressBookService = $addressBookService;
        $this->participantService = $eventService->getEventParticipantService();
    }

    /**
     * Process registration verification and appUser account activation.
     *
     * @param string $token
     * @param int    $eventParticipantId
     *
     * @return Response
     * @throws LogicException
     */
    public function eventParticipantRegistrationConfirmAction(string $token, int $eventParticipantId): Response
    {
        // TODO: Check and refactor.
        try {
            if (!$token || !$eventParticipantId) {
                return $this->render(
                    '@ZakjakubOswisCalendar/web/pages/event-participant-registration-confirmation.html.twig',
                    array(
                        'type'    => 'error',
                        'title'   => 'Chyba! URL nekompletní!',
                        'message' => 'Formát adresy pro ověření je chybný.
                            Zkuste odkaz otevřít znovu nebo jej zkopírovat celý do adresního řádku prohlížeče.
                            Pokud se to nepodaří, kontaktujte nás a společně to vyřešíme.',
                    )
                );
            }
            $eventParticipant = $this->participantService->getRepository()->findOneBy(['id' => $eventParticipantId]);
            if (null === $eventParticipant || null === $eventParticipant->getContact()) {
                $error = null === $eventParticipant ? ', přihláška nenalezena' : '';
                $error .= !($eventParticipant->getContact() instanceof AbstractContact) ? ', účastník nenalezen' : '';

                return $this->render(
                    '@ZakjakubOswisCalendar/web/pages/event-participant-registration-confirmation.html.twig',
                    array(
                        'type'    => 'error',
                        'title'   => 'Chyba!',
                        'message' => "Aktivace se nezdařila. Kontaktujte nás, prosím. (token $token, přihláška č. $eventParticipantId$error)",
                    )
                );
            }
            $eventParticipant->removeEmptyEventParticipantNotes();
            if ($eventParticipant->getContact()) {
                $eventParticipant->getContact()->removeEmptyContactDetails();
                $eventParticipant->getContact()->removeEmptyNotes();
            }
            $this->participantService->sendMail($eventParticipant, true, $token);

            return $this->render(
                '@ZakjakubOswisCalendar/web/pages/event-participant-registration-confirmation.html.twig',
                array(
                    'type'    => 'success',
                    'title'   => 'Hotovo!',
                    'message' => 'Ověření uživatele proběhlo úspěšně.',
                )
            );
        } catch (Exception $e) {
            $this->logger->notice('OSWIS_CONFIRM_ERROR: '.$e->getMessage());

            return $this->render(
                '@ZakjakubOswisCalendar/web/pages/event-participant-registration-confirmation.html.twig',
                array(
                    'type'    => 'error',
                    'title'   => 'Neočekávaná chyba!',
                    'message' => 'Registraci a přihlášku se nepodařilo potvrdit. Kontaktujte nás a společně to vyřešíme.',
                )
            );
        }
    }

    /**
     * Partners for homepage.
     * @return Response
     * @throws LogicException
     */
    public function partnersFooter(): Response
    {
        $data = [
            'footerPartners' => $this->participantService->getEventWebPartners(),
        ];

        return $this->render('@ZakjakubOswisCalendar/web/parts/partners-footer.html.twig', $data);
    }

    /**
     * Show or process registration form.
     *
     * Route shows registration form or process it if form was sent.
     * Data from form is validated, user is created and than summary and activation e-mail is sent.
     *
     * @param Request                      $request
     * @param UserPasswordEncoderInterface $encoder
     * @param string                       $eventSlug
     *
     * @return Response
     * @throws EventCapacityExceededException
     * @throws InvalidArgumentException
     * @throws LogicException
     * @throws OswisNotFoundException
     */
    final public function eventParticipantRegistration(Request $request, UserPasswordEncoderInterface $encoder, string $eventSlug): Response
    {
        $event = $this->getEvent($eventSlug);
        $participant = $this->prepareEventParticipant($event);
        try {
            $form = $this->createForm(\Zakjakub\OswisCalendarBundle\Form\EventParticipantType::class, $participant);
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
                $participant->getContact()->removeEmptyContactDetails();
                $participant->getContact()->removeEmptyNotes();
                $flagsRows = $participant->getEvent()->getAllowedFlagsAggregatedByType($participant->getEventParticipantType());
                foreach ($flagsRows as $flagsRow) {
                    $flagType = $flagsRow['flagType'];
                    assert($flagType instanceof EventParticipantType);
                    $oneFlag = $form[$flagType->getSlug()]->getData();
                    assert($oneFlag instanceof EventParticipantFlag);
                    if (null !== $oneFlag) {
                        $participant->addEventParticipantFlagConnection(new EventParticipantFlagNewConnection($oneFlag));
                    }
                }
                $this->participantService->sendMail($participant, true);

                return $this->render(
                    '@ZakjakubOswisCalendar/web/pages/event-participant-registration-form.html.twig',
                    array(
                        'form'                => $form->createView(),
                        'title'               => 'Přihláška odeslána!',
                        'pageTitle'           => 'Přihláška odeslána!',
                        'message'             => 'Tvoje přihláška na letošní Seznamovák Univerzity Palackého byla úspěšně odeslána! 
                                        Nyní je ovšem ještě nutné ji potvrdit kliknutím na odkaz v e-mailu, který jsme Ti právě zaslali.',
                        'type'                => 'success',
                        'registrationsActive' => true,
                    )
                );
            }

            return $this->render(
                '@ZakjakubOswisCalendar/web/pages/event-participant-registration-form.html.twig',
                array(
                    'form'                => $form->createView(),
                    'title'               => 'Přihlaš se na Seznamovák UP právě teď!',
                    'pageTitle'           => 'Přihláška na Seznamovák UP',
                    'message'             => '',
                    'type'                => 'form',
                    'registrationsActive' => true,
                    // 'year'       => $actualYear,
                    // 'verifyCode' => $verifyCodeNow,
                )
            );
        } catch (Exception $e) {
            $participant = $this->prepareEventParticipant($event);
            if (!isset($form)) {
                $form = $this->createForm(\Zakjakub\OswisCalendarBundle\Form\EventParticipantType::class, $participant);
                $form->handleRequest($request);
            }
            $form->addError(new FormError('Nastala chyba. Zkuste to znovu nebo nás kontaktujte.  '.$e->getMessage().''));

            return $this->render(
                '@ZakjakubOswisCalendar/web/pages/event-participant-registration-form.html.twig',
                array(
                    'form'                => $form->createView(),
                    'title'               => 'Přihláška na akci '.$event->getName(),
                    'pageTitle'           => 'Přihláška na akci '.$event->getName(),
                    'type'                => 'form',
                    'registrationsActive' => $event->isRegistrationsAllowed($participant->getEventParticipantType()),
                )
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
        $opts = [
            EventRepository::CRITERIA_ONLY_PUBLIC_ON_WEB => true,
            EventRepository::CRITERIA_SLUG               => $eventSlug,
        ];
        $event = $this->eventService->getRepository()->getEvent($opts);
        if (null === $event) {
            throw new OswisNotFoundException('Akce nebyla nalezena.');
        }

        return $event;
    }

    /**
     * Create empty eventParticipant for use in forms.
     *
     * @param Event $event
     *
     * @return EventParticipant
     * @throws EventCapacityExceededException
     * @throws InvalidArgumentException
     */
    public function prepareEventParticipant(Event $event): EventParticipant
    {
        $contactDetailTypeRepository = $this->em->getRepository(ContactDetailType::class);
        assert($contactDetailTypeRepository instanceof ContactDetailTypeRepository);
        $addressBook = $this->getAddressBook($event);
        $participantType = $event->getParticipantTypes(EventParticipantType::TYPE_ATTENDEE)->first();
        assert($participantType instanceof EventParticipantType);
        $contactDetailTypeEmail = $contactDetailTypeRepository->findOneBy(['slug' => 'e-mail']);
        $contactDetailTypePhone = $contactDetailTypeRepository->findOneBy(['slug' => 'phone']);
        $participantPerson = new Person(
            null,
            null,
            null,
            Person::TYPE_PERSON,
            null,
            new ArrayCollection([new ContactDetail($contactDetailTypeEmail), new ContactDetail($contactDetailTypePhone)]),
            null,
            new ArrayCollection([new Position(null, null, Position::TYPE_STUDENT)]),
            null,
            new ArrayCollection([$addressBook])
        );

        return new EventParticipant(
            $participantPerson, $event, $participantType, null, new ArrayCollection([new EventParticipantNote()])
        );
    }

    public function getAddressBook(Event $event): AddressBook
    {
        $addressBook = $this->addressBookService->getRepository()->findOneBy(['slug' => $event->getSlug()]);
        if (null === $addressBook) {
            $addressBook = $this->addressBookService->create(
                new Nameable('Akce '.$event->getName(), $event->getShortName(), 'Automatický adresář pro akci '.$event->getName(), null, $event->getSlug())
            );
        }

        return $addressBook;
    }

    final public function checkSpamInForm(Form $form, LoggerInterface $logger, SpamDateTimeEncoder $spamDateTimeEncoder): void
    {
        /*
        if ($form->get('verification')->getData() !== '42') {
            $logger->notice('JS SPAM: '.$form->get('verification')->getData());
            $form->addError(
                new FormError(
                    'Registrace byla vyhodnocena jako automatizovaný spam (pravděpodobně používáte nepodporovaný prohlížeč). :/'
                )
            );
            throw new InvalidArgumentException('Spam detected (x991).');
        }

        if (!empty($form->get('url')->getData())) {
            $logger->notice('URL FIELD SPAM: '.$form->get('url')->getData());
            $form->addError(new FormError('Registrace byla vyhodnocena jako automatizovaný spam :/'));
            throw new InvalidArgumentException('Spam detected (x992).');
        }
        if ($spamDateTimeEncoder->isSpam($form->get('verificationCode')->getData(), $logger)) {
            $logger->notice('TIME SPAM: '.$form->get('verificationCode')->getData());
            $form->addError(new FormError('Registrace byla vyhodnocena jako automatizovaný spam :/ Zkuste přihlášku odeslat ještě jednou.'));
            throw new \InvalidArgumentException('Spam detected (x993).');
        }
        */
    }
}