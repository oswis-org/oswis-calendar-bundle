<?php
/**
 * @noinspection PhpUnused
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Controller\Participant;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use InvalidArgumentException;
use OswisOrg\OswisAddressBookBundle\Entity\AbstractClass\AbstractContact;
use OswisOrg\OswisCalendarBundle\Entity\Event\Event;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantCategory;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantFlagGroup;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantToken;
use OswisOrg\OswisCalendarBundle\Entity\Registration\RegistrationOffer;
use OswisOrg\OswisCalendarBundle\Exception\EventCapacityExceededException;
use OswisOrg\OswisCalendarBundle\Exception\FlagCapacityExceededException;
use OswisOrg\OswisCalendarBundle\Exception\FlagOutOfRangeException;
use OswisOrg\OswisCalendarBundle\Exception\ParticipantNotFoundException;
use OswisOrg\OswisCalendarBundle\Form\Participant\ParticipantType;
use OswisOrg\OswisCalendarBundle\Provider\OswisCalendarSettingsProvider;
use OswisOrg\OswisCalendarBundle\Repository\Event\EventRepository;
use OswisOrg\OswisCalendarBundle\Repository\Participant\ParticipantRepository;
use OswisOrg\OswisCalendarBundle\Service\Event\EventService;
use OswisOrg\OswisCalendarBundle\Service\Participant\ParticipantService;
use OswisOrg\OswisCalendarBundle\Service\Participant\ParticipantTokenService;
use OswisOrg\OswisCalendarBundle\Service\Registration\RegistrationOfferService;
use OswisOrg\OswisCoreBundle\Entity\AbstractClass\AbstractToken;
use OswisOrg\OswisCoreBundle\Entity\AppUser\AppUser;
use OswisOrg\OswisCoreBundle\Exceptions\NotFoundException;
use OswisOrg\OswisCoreBundle\Exceptions\NotImplementedException;
use OswisOrg\OswisCoreBundle\Exceptions\OswisException;
use OswisOrg\OswisCoreBundle\Exceptions\TokenInvalidException;
use OswisOrg\OswisCoreBundle\Provider\OswisCoreSettingsProvider;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Security\Core\Security;
use UnexpectedValueException;

class ParticipantController extends AbstractController
{
    public function __construct(
        public EventService             $eventService,
        public RegistrationOfferService $regRangeService,
        public ParticipantService       $participantService,
        protected EntityManagerInterface $entityManager,
        protected Security              $security,
        protected LoggerInterface       $logger,
        protected OswisCalendarSettingsProvider $calendarSettings,
    )
    {
    }

    public function getTokenService(): ParticipantTokenService
    {
        return $this->getParticipantService()->getTokenService();
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
     * @param Request $request
     * @param string|null $rangeSlug
     *
     * @return Response
     * @throws InvalidArgumentException
     * @throws EventCapacityExceededException
     * @throws FlagCapacityExceededException
     * @throws FlagOutOfRangeException
     * @throws ParticipantNotFoundException
     * @throws NotFoundException
     * @throws NotImplementedException
     * @throws OswisException
     */
    public function registration(Request $request, ?string $rangeSlug = null): Response
    {
        $this->logger->info("START REGISTRATION");
        if (null === $rangeSlug) {
            return $this->redirectToDefaultEventRanges();
        }
        //
        $range = $this->regRangeService->getRangeBySlug($rangeSlug, true, true);
        $this->logger->info("GOT RANGE");
        //
        if (!($range instanceof RegistrationOffer) || !$range->isPublicOnWeb()) {
            throw new NotFoundException('Rozsah pro vytváření přihlášek nebyl nalezen nebo není aktivní.');
        }
        $user = $this->security->getUser();
        if ($user instanceof AppUser) {
            try {
                $repository = $this->entityManager->getRepository(AbstractContact::class);
                $contact = $repository->findBy(['appUser' => $user->getId()])[0] ?? null;
            } catch (UnexpectedValueException) {
            }
        }
        $participant = $this->participantService->getEmptyParticipant($range, $contact ?? null);
        $this->logger->info("GOT EMPTY PARTICIPANT");
        try {
            $form = $this->createForm(ParticipantType::class, $participant);
            $form->handleRequest($request);
            if ($form->isSubmitted() && $form->isValid()) {
                $participant = $form->getData();
                assert($participant instanceof Participant);
                foreach ($participant->getFlagGroups(null, null, true) as $flagGroup) {
                    assert($flagGroup instanceof ParticipantFlagGroup);
                    $flagGroup->setParticipantFlags($flagGroup->getParticipantFlags(true), false, true);
                }
                $participant = $this->participantService->create($participant);
                $eventName = $participant->getEvent()?->getShortName();

                return $this->getResponse(
                    'success',
                    'Přihláška odeslána!',
                    false,
                    $participant->getEvent(),
                    $participant->getOffer(),
                    "Tvoje přihláška na akci $eventName byla úspěšně odeslána! Nyní je ještě nutné ji potvrdit kliknutím na odkaz v e-mailu, který jsme Ti právě zaslali.",
                    $form->createView()
                );
            }

            return $this->getResponse(
                'form',
                "Přihláška na akci " . $range->getEvent()?->getShortName(),
                false,
                $range->getEvent(),
                $range,
                null,
                $form->createView()
            );
        } catch (Exception $e) {
            $participant = $this->participantService->getEmptyParticipant($range);
            /** @phpstan-ignore-next-line */
            if (!isset($form)) {
                $form = $this->createForm(ParticipantType::class, $participant);
                $form->handleRequest($request);
            }
            $form->addError(new FormError('Nastala chyba. Zkuste to znovu nebo nás kontaktujte. ' . $e->getMessage()));
            $event = $range->getEvent();
            $eventName = $event?->getShortName();

            return $this->getResponse(
                'form',
                "Přihláška na akci $eventName",
                false,
                $event,
                $range,
                null,
                $form->createView(),
            );
        }
    }

    /**
     * @return Response
     * @throws NotFoundException
     */
    public function redirectToDefaultEventRanges(): Response
    {
        $defaultEvent = $this->eventService->getDefaultEvent();
        if (null === $defaultEvent) {
            throw new NotFoundException(
                'Vyberte si, prosím, konkrétní akci, na kterou se chcete přihlásit, ze seznamu událostí.'
            );
        }

        return $this->redirectToRoute(
            'oswis_org_oswis_calendar_web_registration_ranges',
            ['eventSlug' => $defaultEvent->getSlug(), 'participantType' => ParticipantCategory::TYPE_ATTENDEE]
        );
    }

    /**
     * @param string $eventSlug
     *
     * @return Event
     * @throws NotFoundException
     */
    public function getEvent(string $eventSlug): Event
    {
        $event = $this->eventService->getRepository()->getEvent([
            EventRepository::CRITERIA_ONLY_PUBLIC_ON_WEB => true,
            EventRepository::CRITERIA_SLUG => $eventSlug,
        ]);
        if (null === $event) {
            throw new NotFoundException('Akce nebyla nalezena.');
        }

        return $event;
    }

    public function getResponse(
        ?string   $type,
        string    $title,
        bool      $verification = false,
        ?Event    $event = null,
        ?RegistrationOffer $range = null,
        ?string   $message = null,
        ?FormView $formView = null
    ): Response
    {
        $template = '@OswisOrgOswisCalendar/web/pages/participant-registration-form.html.twig';
        if ($verification) {
            $template = '@OswisOrgOswisCalendar/web/pages/participant-registration-confirmation.html.twig';
        }

        return $this->render($template, [
            'form' => $formView,
            'title' => $title,
            'pageTitle' => $title,
            'event' => $event,
            'range' => $range,
            'message' => $message,
            'type' => $type,
            'registrationsActive' => $range && $range->isRangeActive(),
        ]);
    }

    /**
     * @param string|null $token
     * @param int|null $participantId
     *
     * @return Response
     * @throws TokenInvalidException|OswisException
     */
    public function processToken(?string $token = null, ?int $participantId = null): Response
    {
        $participantToken = $this->participantService->getVerifiedToken($token, $participantId);
        $type = $participantToken->getType();
        if (AbstractToken::TYPE_ACTIVATION === $type) {
            return $this->doActivation($participantToken);
        }
        throw new TokenInvalidException('nebyla vykonána žádná akce');
    }

    /**
     * @param ParticipantToken $participantToken
     *
     * @return Response
     * @throws OswisException
     */
    public function doActivation(ParticipantToken $participantToken): Response
    {
        $this->participantService->activate($participantToken->getParticipant(), $participantToken, true);

        return $this->participantActivated();
    }

    public function participantActivated(): Response
    {
        $title = 'Přihláška aktivována!';
        $message = 'Přihláška byla úspěšně ověřena.';

        $activatedRedirectUrl = $this->calendarSettings->getExternalRedirects()['participant_activated'];
        if ($activatedRedirectUrl) {
            $activatedRedirectUrl = str_replace(
                ['{title}', '{message}'],
                [$title, $message],
                $activatedRedirectUrl,
            );

            return $this->redirect($activatedRedirectUrl);
        }

        return $this->render('@OswisOrgOswisCore/web/pages/message.html.twig', [
            'title' => $title,
            'message' => $message,
        ]);
    }

    /**
     * @throws OswisException
     * @throws NotFoundException
     */
    public function resendActivationEmail(?int $participantId = null): Response
    {
        $this->participantService->requestActivation(
            $this->participantService->getParticipant([ParticipantRepository::CRITERIA_ID => $participantId])
        );

        return $this->getResponse(
            'success',
            'Ověřovací zpráva odeslána!',
            false,
            null,
            null,
            "Ověřovací zpráva ke Tvé přihlášce byla úspěšně odeslána! Nyní je ještě nutné ji potvrdit kliknutím na odkaz v e-mailu, který jsme Ti právě zaslali.",
        );
    }

    /**
     * Finds correct registration range by event and participantType.
     *
     * @param Event $event Event.
     * @param ParticipantCategory|null $participantCategory Type of participant.
     * @param string|null $participantType
     *
     * @return RegistrationOffer|null
     */
    public function getRange(
        Event   $event,
        ?ParticipantCategory $participantCategory,
        ?string $participantType
    ): ?RegistrationOffer
    {
        return $this->regRangeService->getRange($event, $participantCategory, $participantType, true, true);
    }

    /**
     * Renders page with list of registration ranges.
     *
     * If eventSlug is defined, renders page with registration ranges for this event and subEvents, if it's not
     * defined, renders list for all events.
     *
     * @param string|null $eventSlug Slug for selected event.
     * @param string|null $participantType Restriction by participant type.
     *
     * @return Response Page with registration ranges.
     * @throws Exception Error occurred when getting events.
     */
    public function showRanges(string $eventSlug = null, ?string $participantType = null): Response
    {
        $event = $eventSlug ? $this->eventService->getEvents(null, null, null, null, null, $eventSlug, false)[0] ?? null
            : null;
        if (!empty($eventSlug) && null === $event) {
            return $this->redirectToRoute('oswis_org_oswis_calendar_web_registration_ranges');
        }
        $events = $event instanceof Event ? new ArrayCollection([$event, ...$event->getSubEvents()])
            : $this->eventService->getEvents(null, null, null, null, null, null, false);
        $shortName = $event instanceof Event ? $event->getShortName() : '';
        $title = "Přihlášky na akc" . ($event instanceof Event ? "i $shortName" : "e");

        return $this->render('@OswisOrgOswisCalendar/web/pages/registration-ranges.html.twig', [
            'event' => $event,
            'ranges' => $this->regRangeService->getEventRegistrationRanges($events, $participantType, true),
            'title' => $title,
            'shortTitle' => 'Přihlášky',
        ]);
    }

    public function partnersFooter(): Response
    {
        return $this->render(
            '@OswisOrgOswisCalendar/web/parts/partners-footer.html.twig',
            ['footerPartners' => $this->participantService->getWebPartners()]
        );
    }

    /**
     * @param Request $request
     * @param OswisCoreSettingsProvider $coreSettings
     * @param int|null $limit
     * @param int|null $offset
     *
     * @return Response
     * @throws AccessDeniedHttpException
     */
    public function updateParticipants(
        Request $request,
        OswisCoreSettingsProvider $coreSettings,
        ?int    $limit = null,
        ?int    $offset = null
    ): Response
    {
        $coreSettings->checkAdminIP($request->getClientIp());
        foreach ($this->participantService->getParticipants([], true, $limit, $offset) as $participant) {
            if ($participant instanceof Participant) {
                $participant->updateCachedColumns();
            }
        }

        return $this->render("@OswisOrgOswisCore/web/pages/message.html.twig", [
            'title' => 'Přihlášky aktualizovány!',
            'message' => 'Přihlášky byly úspěšně aktualizovány.',
        ]);
    }
}
