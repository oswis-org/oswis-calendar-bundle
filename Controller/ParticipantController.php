<?php
/**
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Controller;

use Doctrine\Common\Collections\ArrayCollection;
use Exception;
use InvalidArgumentException;
use OswisOrg\OswisCalendarBundle\Entity\Event\Event;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantCategory;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantToken;
use OswisOrg\OswisCalendarBundle\Entity\Registration\RegRange;
use OswisOrg\OswisCalendarBundle\Exception\EventCapacityExceededException;
use OswisOrg\OswisCalendarBundle\Exception\ParticipantNotFoundException;
use OswisOrg\OswisCalendarBundle\Form\Participant\ParticipantType;
use OswisOrg\OswisCalendarBundle\Repository\EventRepository;
use OswisOrg\OswisCalendarBundle\Service\EventService;
use OswisOrg\OswisCalendarBundle\Service\ParticipantService;
use OswisOrg\OswisCalendarBundle\Service\ParticipantTokenService;
use OswisOrg\OswisCalendarBundle\Service\RegRangeService;
use OswisOrg\OswisCoreBundle\Exceptions\NotFoundException;
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

class ParticipantController extends AbstractController
{
    public RegRangeService $regRangeService;

    public ParticipantService $participantService;

    public EventService $eventService;

    protected LoggerInterface $logger;

    public function __construct(EventService $eventService, RegRangeService $regRangeService, ParticipantService $participantService, LoggerInterface $logger)
    {
        $this->eventService = $eventService;
        $this->participantService = $participantService;
        $this->regRangeService = $regRangeService;
        $this->logger = $logger;
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
     * @param Request     $request
     * @param string|null $rangeSlug
     *
     * @return Response
     * @throws InvalidArgumentException
     * @throws OswisException|NotFoundException|ParticipantNotFoundException|EventCapacityExceededException
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
        if (null === $range || !($range instanceof RegRange) || !$range->isPublicOnWeb()) {
            throw new NotFoundException('Rozsah pro vytváření přihlášek nebyl nalezen nebo není aktivní.');
        }
        $participant = $this->participantService->getEmptyParticipant($range, null);
        $this->logger->info("GOT EMPTY PARTICIPANT");
        try {
            $form = $this->createForm(ParticipantType::class, $participant);
            $form->handleRequest($request);
            if ($form->isSubmitted() && $form->isValid()) {
                $participant = $form->getData();
                assert($participant instanceof Participant);
                $participant = $this->participantService->create($participant);
                $eventName = $participant->getEvent() ? $participant->getEvent()->getShortName() : null;

                return $this->getResponse(
                    'success',
                    'Přihláška odeslána!',
                    false,
                    $participant->getEvent(),
                    $participant->getRegRange(),
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
            $participant = $this->participantService->getEmptyParticipant($range);
            if (!isset($form)) {
                $form = $this->createForm(ParticipantType::class, $participant);
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
     * @throws NotFoundException
     */
    public function redirectToDefaultEventRanges(): Response
    {
        $defaultEvent = $this->eventService->getDefaultEvent();
        if (null === $defaultEvent) {
            throw new NotFoundException('Vyberte si, prosím, konkrétní akci, na kterou se chcete přihlásit, ze seznamu událostí.');
        }

        return $this->redirectToRoute(
            'oswis_org_oswis_calendar_web_registration_ranges',
            ['eventSlug' => $defaultEvent->getSlug(), 'participantType' => ParticipantCategory::TYPE_ATTENDEE]
        );
    }

    public function getResponse(
        ?string $type,
        string $title,
        bool $verification = false,
        ?Event $event = null,
        ?RegRange $range = null,
        ?string $message = null,
        ?FormView $formView = null
    ): Response {
        $template = '@OswisOrgOswisCalendar/web/pages/participant-registration-form.html.twig';
        if ($verification) {
            $template = '@OswisOrgOswisCalendar/web/pages/participant-registration-confirmation.html.twig';
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

    /**
     * @param string|null $token
     * @param int|null    $participantId
     *
     * @return Response
     * @throws TokenInvalidException|OswisException
     */
    public function processToken(?string $token = null, ?int $participantId = null): Response
    {
        $participantToken = $this->participantService->getVerifiedToken($token, $participantId);
        $type = $participantToken->getType();
        if (ParticipantToken::TYPE_ACTIVATION === $type) {
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
        return $this->render(
            '@OswisOrgOswisCore/web/pages/message.html.twig',
            [
                'title'   => 'Přihláška aktivována!',
                'message' => 'Přihláška byla úspěšně ověřena.',
            ]
        );
    }

    /**
     * Finds correct registration range by event and participantType.
     *
     * @param Event                    $event               Event.
     * @param ParticipantCategory|null $participantCategory Type of participant.
     * @param string|null              $participantType
     *
     * @return RegRange
     */
    public function getRange(Event $event, ?ParticipantCategory $participantCategory, ?string $participantType): ?RegRange
    {
        return $this->regRangeService->getRange($event, $participantCategory, $participantType, true, true);
    }

    /**
     * @param string $eventSlug
     *
     * @return Event
     * @throws NotFoundException
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
            throw new NotFoundException('Akce nebyla nalezena.');
        }

        return $event;
    }

    /**
     * Renders page with list of registration ranges.
     *
     * If eventSlug is defined, renders page with registration ranges for this event and subEvents, if it's not defined, renders list for all events.
     *
     * @param string|null $eventSlug       Slug for selected event.
     * @param string|null $participantType Restriction by participant type.
     *
     * @return Response Page with registration ranges.
     * @throws Exception Error occurred when getting events.
     */
    public function showRanges(string $eventSlug = null, ?string $participantType = null): Response
    {
        $event = $eventSlug ? $this->eventService->getEvents(null, null, null, null, null, $eventSlug, false)[0] ?? null : null;
        if (!empty($eventSlug) && null === $event) {
            return $this->redirectToRoute('oswis_org_oswis_calendar_web_registration_ranges');
        }
        $events = $event instanceof Event ? new ArrayCollection([$event, ...$event->getSubEvents()]) : $this->eventService->getEvents(null, null, null, null, null, null, false);
        $shortTitle = 'Přihlášky';
        $title = $shortTitle.' na akc'.(null === $event ? 'e' : 'i '.$event->getShortName());

        return $this->render(
            '@OswisOrgOswisCalendar/web/pages/registration-ranges.html.twig',
            [
                'event'      => $event,
                'ranges'     => $this->regRangeService->getEventRegistrationRanges($events, $participantType, true),
                'title'      => $title,
                'shortTitle' => $shortTitle,
            ]
        );
    }

    public function partnersFooter(): Response
    {
        return $this->render('@OswisOrgOswisCalendar/web/parts/partners-footer.html.twig', ['footerPartners' => $this->participantService->getWebPartners()]);
    }

    /**
     * @param Request                   $request
     * @param OswisCoreSettingsProvider $coreSettings
     * @param int|null                  $limit
     * @param int|null                  $offset
     *
     * @return Response
     * @throws AccessDeniedHttpException
     */
    public function updateParticipants(Request $request, OswisCoreSettingsProvider $coreSettings, ?int $limit = null, ?int $offset = null): Response
    {
        $coreSettings->checkAdminIP($request->getClientIp());
        foreach ($this->participantService->getParticipants([], true, $limit, $offset) as $participant) {
            if ($participant instanceof Participant) {
                $participant->updateCachedColumns();
            }
        }

        return $this->render(
            "@OswisOrgOswisCore/web/pages/message.html.twig",
            [
                'title'   => 'Přihlášky aktualizovány!',
                'message' => 'Přihlášky byly úspěšně aktualizovány.',
            ]
        );
    }
}
