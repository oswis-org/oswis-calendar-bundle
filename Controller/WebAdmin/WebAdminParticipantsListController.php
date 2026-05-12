<?php
/**
 * @noinspection PhpUnused
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Controller\WebAdmin;

use DateTime;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantCategory;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantNote;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantPayment;
use OswisOrg\OswisCalendarBundle\Repository\Event\EventRepository;
use OswisOrg\OswisCalendarBundle\Repository\Participant\ParticipantRepository;
use OswisOrg\OswisCalendarBundle\Repository\Registration\RegistrationOfferRepository;
use OswisOrg\OswisCalendarBundle\Service\Event\EventSeriesService;
use OswisOrg\OswisCalendarBundle\Service\Event\EventService;
use OswisOrg\OswisCalendarBundle\Service\Participant\ParticipantCategoryService;
use OswisOrg\OswisCalendarBundle\Service\Participant\ParticipantService;
use OswisOrg\OswisCalendarBundle\Service\Registration\RegistrationOfferService;
use OswisOrg\OswisCoreBundle\Exceptions\NotFoundException;
use OswisOrg\OswisCoreBundle\Interfaces\AddressBook\ContactInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;

class WebAdminParticipantsListController extends AbstractController
{
    private const GENDER_APPROX_KEY = 'Pohlaví (odhad)';

    public function __construct(
        public EventService $eventService,
        public ParticipantService $participantService,
        public ParticipantCategoryService $participantCategoryService,
        public RegistrationOfferService $participantRegistrationService,
        public EntityManagerInterface $em,
        public EventSeriesService $eventSeriesService,
    ) {
    }

    public function showParticipants(
        ?string $eventSlug = null,
        ?string $participantCategorySlug = null,
        bool $includeDeleted = true,
    ): Response {
        return $this->render(
            "@OswisOrgOswisCalendar/web_admin/participants.html.twig",
            $this->getParticipantsData($eventSlug, $participantCategorySlug, $includeDeleted),
        );
    }

    public function getParticipantsData(
        ?string $eventSlug = null,
        ?string $participantCategorySlug = null,
        bool $includeDeleted = true
    ): array {
        $data = [];
        $data['participantCategory']
            = $this->participantCategoryService->getParticipantTypeBySlug($participantCategorySlug);
        $data['event'] = $this->eventService->getRepository()->getEvent([EventRepository::CRITERIA_SLUG => $eventSlug]);
        $data['participants'] = $this->participantService->getParticipants([
            ParticipantRepository::CRITERIA_INCLUDE_DELETED => $includeDeleted,
            ParticipantRepository::CRITERIA_EVENT => $data['event'],
            ParticipantRepository::CRITERIA_PARTICIPANT_CATEGORY => $data['participantCategory'],
            ParticipantRepository::CRITERIA_EVENT_RECURSIVE_DEPTH => 2,
        ]);
        $data['title'] = "Přehled účastníků :: ADMIN";
        $participantsArray = $data['participants']->toArray();
        usort($participantsArray, static function (Participant $a, Participant $b) {
            return strcoll($a->getSortableName(), $b->getSortableName());
        });
        $data['participants'] = new ArrayCollection($participantsArray);

        return $data;
    }

    /**
     * @throws InvalidArgumentException
     */
    public function showParticipantsCsv(
        ?string $eventSlug = null,
        ?string $participantCategorySlug = null,
        bool $includeDeleted = false,
    ): Response {
        $fileName = "participants";
        $fileName .= $eventSlug ? ('_'.$eventSlug) : '';
        $fileName .= $participantCategorySlug ? ('_'.$participantCategorySlug) : '';
        $fileName .= '_'.str_replace('T', '_', (new DateTime())->format('c'));
        $fileName .= '.csv';

        return $this->render(
            "@OswisOrgOswisCalendar/web_admin/participants.csv.twig",
            $this->getParticipantsData($eventSlug, $participantCategorySlug, $includeDeleted),
            new Response(headers: [
                'Content-Type' => 'text/csv',
                'Content-Disposition' => "attachment; filename=\"{$fileName}\"",
            ]),
        );
    }

    public function showPayments(): Response
    {
        return $this->render("@OswisOrgOswisCalendar/web_admin/payments.html.twig", [
            'payments' => $this->em->getRepository(ParticipantPayment::class)->findAll(),
            'title' => "Přehled plateb účastníků :: ADMIN",
        ]);
    }

    public function showYearsCompare(?string $eventSeriesSlug = null): Response
    {
        $events = $this->eventService->getRepository()->getEvents([
            EventRepository::CRITERIA_SERIES_SLUG => $eventSeriesSlug,
            EventRepository::CRITERIA_TYPE_STRING => 'year-of-event',
        ]);

        return $this->render("@OswisOrgOswisCalendar/web_admin/years-compare.html.twig", [
            'title' => "Srovnání ročníků :: ADMIN",
            'events' => $events,
        ]);
    }


    /**
     * @param string|null $eventSlug
     *
     * @return Response
     * @throws NotFoundException
     */
    public function showEvent(?string $eventSlug = null): Response
    {
        $event = $this->eventService->getRepository()->getEvent([EventRepository::CRITERIA_SLUG => $eventSlug]);
        $defaultEvent = $this->eventService->getDefaultEvent();
        $event ??= $defaultEvent;
        $isDefaultEvent = $event === $defaultEvent;
        if (null === $event) {
            throw new NotFoundException("Událost '$eventSlug' nenalezena.");
        }
        $participants = $this->participantService->getParticipants([
            ParticipantRepository::CRITERIA_INCLUDE_DELETED => false,
            ParticipantRepository::CRITERIA_EVENT => $event,
            ParticipantRepository::CRITERIA_PARTICIPANT_TYPE => ParticipantCategory::TYPE_ATTENDEE,
            ParticipantRepository::CRITERIA_EVENT_RECURSIVE_DEPTH => 1,
        ]);
        $occupancy = [
            'event' => $event,
            'occupancy' => $participants->count(),
            'subEvents' => [],
        ];
        foreach ($event->getSubEvents() as $subEvent) {
            $occupancy['subEvents'][] = [
                'event' => $subEvent,
                'occupancy' => $this->participantService->countParticipants([
                    ParticipantRepository::CRITERIA_INCLUDE_DELETED => false,
                    ParticipantRepository::CRITERIA_PARTICIPANT_TYPE => ParticipantCategory::TYPE_ATTENDEE,
                    ParticipantRepository::CRITERIA_EVENT => $subEvent,
                    ParticipantRepository::CRITERIA_EVENT_RECURSIVE_DEPTH => 1,
                ]),
            ];
            // Do some recursion??
        }
        $flagsUsageByRange = [];
        $flagsUsageByFlag = [];
        $otherAggregations = [];
        /** @var array<string, int> $paymentsAggregation */
        $paymentsAggregation = [];
        $paymentsAggregation['Celkem cena (s příznaky)'] = 0;
        $paymentsAggregation['Celkem základní cena (bez příznaků)'] = 0;
        $paymentsAggregation['Celkem záloha (s příznaky)'] = 0;
        $paymentsAggregation['Zaplacená cena'] = 0;
        $paymentsAggregation['Celkem cena za příznaky'] = 0;
        $paymentsAggregation['Celkem záloha za příznaky'] = 0;
        $paymentsAggregation['Zbývající záloha (s příznaky)'] = 0;
        $paymentsAggregation['Zbývající cena (s příznaky)'] = 0;
        foreach ($participants as $participant) {
            foreach ($participant->getParticipantFlags(null, null, true) as $participantFlag) {
                $flagRange = $participantFlag->getFlagOffer();
                if (null === $flagRange) {
                    continue;
                }
                $participantFlagGroup = $participantFlag->getParticipantFlagGroup();
                $flagGroupRange = $participantFlagGroup?->getFlagGroupOffer();
                $flagGroupRangeId = $flagGroupRange?->getId() ?? 0;
                $flag = $flagRange->getFlag();
                $flagId = $flag ? $flag->getId() : 0;
                $flagCategory = $flagRange->getCategory();
                $flagCategoryId = $flagCategory ? $flagCategory->getId() : 0;
                // Flags usage by range.
                $flagsUsageByRange[$flagGroupRangeId]['flagCategory'] = $flagCategory;
                $flagsUsageByRange[$flagGroupRangeId]['flagGroupRange'] = $flagGroupRange;
                $flagsUsageByRange[$flagGroupRangeId]['items'][$flagRange->getId()]['entity'] = $flagRange;
                $flagsUsageByRange[$flagGroupRangeId]['items'][$flagRange->getId()]['usage'] ??= 0;
                $flagsUsageByRange[$flagGroupRangeId]['items'][$flagRange->getId()]['usage']++;
                // Flags usage by flag.
                $flagsUsageByFlag[$flagCategoryId]['flagCategory'] = $flagCategory;
                $flagsUsageByFlag[$flagCategoryId]['items'][$flagId]['entity'] = $flag;
                $flagsUsageByFlag[$flagCategoryId]['items'][$flagId]['usage'] ??= 0;
                $flagsUsageByFlag[$flagCategoryId]['items'][$flagId]['usage']++;
            }
            // Other aggregations.
            if ($participant->getRemainingDeposit() <= 0) {
                $otherAggregations['Platby']['Zaplacená záloha'] ??= 0;
                $otherAggregations['Platby']['Zaplacená záloha']++;
            }
            if ($participant->getRemainingPrice() <= 0) {
                $otherAggregations['Platby']['Zaplacena celá částka'] ??= 0;
                $otherAggregations['Platby']['Zaplacena celá částka']++;
            }
            if ($participant->hasActivatedContactUser()) {
                $otherAggregations['Uživatelský účet']['Účet ověřen'] ??= 0;
                $otherAggregations['Uživatelský účet']['Účet ověřen']++;
            }
            if ($participant->getNotes()->filter(static fn (ParticipantNote $note) => !empty($note->getTextValue()))->count() > 0) {
                $otherAggregations['Poznámky']['S poznámkou'] ??= 0;
                $otherAggregations['Poznámky']['S poznámkou']++;
            }
            if ($participant->isFormal()) {
                $otherAggregations['Nastavení IS']['Formální oslovení (ručně u přihlášky)'] ??= 0;
                $otherAggregations['Nastavení IS']['Formální oslovení (ručně u přihlášky)']++;
            }
            switch ($participant->getContact()?->getGender()) {
                case ContactInterface::GENDER_MALE:
                    $otherAggregations[self::GENDER_APPROX_KEY]['👨 Muž'] ??= 0;
                    $otherAggregations[self::GENDER_APPROX_KEY]['👨 Muž']++;
                    break;
                case ContactInterface::GENDER_FEMALE:
                    $otherAggregations[self::GENDER_APPROX_KEY]['👩 Žena'] ??= 0;
                    $otherAggregations[self::GENDER_APPROX_KEY]['👩 Žena']++;
                    break;
                default:
                    $otherAggregations[self::GENDER_APPROX_KEY]['👤 Neurčeno'] ??= 0;
                    $otherAggregations[self::GENDER_APPROX_KEY]['👤 Neurčeno']++;
                    break;
            }
            // Payments aggregation.
            $paymentsAggregation['Celkem cena (s příznaky)'] += $participant->getPrice();
            $paymentsAggregation['Celkem základní cena (bez příznaků)'] += $participant->getPrice()
                - $participant->getFlagsPrice();
            $paymentsAggregation['Celkem záloha (s příznaky)'] += $participant->getDepositValue();
            $paymentsAggregation['Zaplacená cena'] += $participant->getPaidPrice();
            $paymentsAggregation['Celkem cena za příznaky'] += $participant->getFlagsPrice();
            $paymentsAggregation['Celkem záloha za příznaky'] += $participant->getFlagsDepositValue();
            $paymentsAggregation['Zbývající záloha (s příznaky)'] += $participant->getRemainingDeposit();
            $paymentsAggregation['Zbývající cena (s příznaky)'] += $participant->getRemainingPrice();
        }
        $regRanges = $this->participantRegistrationService->getRepository()->getRegistrationsRanges(
            [RegistrationOfferRepository::CRITERIA_EVENT => $event]
        );
        $newRegRanges = [];
        foreach ($regRanges as $regRange) {
            $newRegRanges = [
                ...$newRegRanges,
                ...$this->participantRegistrationService->getRepository()->getRegistrationsRanges(
                    [RegistrationOfferRepository::CRITERIA_REQUIRED_REG_RANGE => $regRange],
                ),
            ];
        }
        foreach ($newRegRanges as $newRegRange) {
            if (!$regRanges->contains($newRegRange)) {
                $regRanges->add($newRegRange);
            }
        }
        ksort($flagsUsageByRange);
        ksort($flagsUsageByFlag);

        return $this->render('@OswisOrgOswisCalendar/web_admin/event.html.twig', [
            'title' => "Přehled události :: ADMIN",
            'event' => $event,
            'occupancy' => $occupancy,
            'flagsUsageByRange' => $flagsUsageByRange,
            'flagsUsageByFlag' => $flagsUsageByFlag,
            'offers' => $regRanges,
            'otherAggregations' => $otherAggregations,
            'paymentsAggregation' => $paymentsAggregation,
            'isDefaultEvent' => $isDefaultEvent,
        ]);
    }
}
