<?php
/**
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Controller\WebAdmin;

use Doctrine\Common\Collections\ArrayCollection;
use OswisOrg\OswisAddressBookBundle\Entity\AbstractClass\AbstractContact;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantCategory;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantFlag;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantNote;
use OswisOrg\OswisCalendarBundle\Repository\EventRepository;
use OswisOrg\OswisCalendarBundle\Repository\ParticipantRepository;
use OswisOrg\OswisCalendarBundle\Repository\RegRangeRepository;
use OswisOrg\OswisCalendarBundle\Service\EventService;
use OswisOrg\OswisCalendarBundle\Service\ParticipantCategoryService;
use OswisOrg\OswisCalendarBundle\Service\ParticipantService;
use OswisOrg\OswisCalendarBundle\Service\RegRangeService;
use OswisOrg\OswisCoreBundle\Exceptions\NotFoundException;
use OswisOrg\OswisCoreBundle\Exceptions\OswisException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;

class WebAdminParticipantsListController extends AbstractController
{
    public EventService $eventService;

    public ParticipantService $participantService;

    public ParticipantCategoryService $participantCategoryService;

    public RegRangeService $regRangeService;

    public function __construct(
        EventService $eventService,
        ParticipantService $participantService,
        ParticipantCategoryService $participantCategoryService,
        RegRangeService $regRangeService
    ) {
        $this->eventService = $eventService;
        $this->participantService = $participantService;
        $this->participantCategoryService = $participantCategoryService;
        $this->regRangeService = $regRangeService;
    }

    public function showParticipants(?string $eventSlug = null, ?string $participantCategorySlug = null): Response
    {
        $data['participantCategory'] = $this->participantCategoryService->getParticipantTypeBySlug($participantCategorySlug);
        $data['event'] = $this->eventService->getRepository()->getEvent([EventRepository::CRITERIA_SLUG => $eventSlug]);
        $data['participants'] = $this->participantService->getParticipants(
            [
                ParticipantRepository::CRITERIA_INCLUDE_DELETED       => true,
                ParticipantRepository::CRITERIA_EVENT                 => $data['event'],
                ParticipantRepository::CRITERIA_PARTICIPANT_CATEGORY  => $data['participantCategory'],
                ParticipantRepository::CRITERIA_EVENT_RECURSIVE_DEPTH => 2,
            ]
        );
        $data['title'] = "Přehled účastníků :: ADMIN";
        $participantsArray = $data['participants']->toArray();
        usort($participantsArray, fn(Participant $a, Participant $b) => strcoll($a->getSortableName(), $b->getSortableName()));
        $data['participants'] = new ArrayCollection($participantsArray);

        return $this->render("@OswisOrgOswisCalendar/other/participant-list/participant-list.html.twig", $data);
    }

    /**
     * @param string|null $eventSlug
     *
     * @return Response
     * @throws NotFoundException
     * @throws OswisException
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
        $participants = $this->participantService->getParticipants(
            [
                ParticipantRepository::CRITERIA_INCLUDE_DELETED       => false,
                ParticipantRepository::CRITERIA_EVENT                 => $event,
                ParticipantRepository::CRITERIA_PARTICIPANT_TYPE      => ParticipantCategory::TYPE_ATTENDEE,
                ParticipantRepository::CRITERIA_EVENT_RECURSIVE_DEPTH => 2,
            ]
        );
        $occupancy = [
            'event'     => $event,
            'occupancy' => $participants->count(),
            'subEvents' => [],
        ];
        foreach ($event->getSubEvents() as $subEvent) {
            $occupancy['subEvents'][] = [
                'event'     => $subEvent,
                'occupancy' => $this->participantService->getParticipants(
                    [
                        ParticipantRepository::CRITERIA_EVENT                 => $subEvent,
                        ParticipantRepository::CRITERIA_EVENT_RECURSIVE_DEPTH => 2,
                    ]
                )->count(),
            ];
            // Do some recursion??
        }
        $flagsUsageByRange = [];
        $flagsUsageByFlag = [];
        $otherAggregations = [];
        $paymentsAggregation['Celkem cena (s příznaky)'] = 0;
        $paymentsAggregation['Celkem základní cena (bez příznaků)'] = 0;
        $paymentsAggregation['Celkem záloha (s příznaky)'] = 0;
        $paymentsAggregation['Zaplacená cena'] = 0;
        $paymentsAggregation['Celkem cena za příznaky'] = 0;
        $paymentsAggregation['Celkem záloha za příznaky'] = 0;
        $paymentsAggregation['Zbývající záloha (s příznaky)'] = 0;
        $paymentsAggregation['Zbývající cena (s příznaky)'] = 0;
        foreach ($participants as $participant) {
            assert($participant instanceof Participant);
            foreach ($participant->getParticipantFlags(null, null, true) as $participantFlag) {
                assert($participantFlag instanceof ParticipantFlag);
                $flagRange = $participantFlag->getFlagRange();
                if (null === $flagRange) {
                    continue;
                }
                $participantFlagGroup = $participantFlag->getParticipantFlagGroup();
                $flagGroupRange = $participantFlagGroup ? $participantFlagGroup->getFlagGroupRange() : null;
                $flagGroupRangeId = $flagGroupRange ? $flagGroupRange->getId() : 0;
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
            if ($participant->hasActivatedContactUser()) {
                $otherAggregations['Přihláška']['Přihláška ověřena'] ??= 0;
                $otherAggregations['Přihláška']['Přihláška ověřena']++;
            }
            if ($participant->getNotes()->filter(fn(ParticipantNote $note) => !empty($note->getTextValue()))->count() > 0) {
                $otherAggregations['Poznámky']['S poznámkou'] ??= 0;
                $otherAggregations['Poznámky']['S poznámkou']++;
            }
            if ($participant->isFormal(false)) {
                $otherAggregations['Nastavení IS']['Formální oslovení (ručně u přihlášky)'] ??= 0;
                $otherAggregations['Nastavení IS']['Formální oslovení (ručně u přihlášky)']++;
            }
            switch ($participant->getContact() ? $participant->getContact()->getGender() : null) {
                case AbstractContact::GENDER_MALE:
                    $otherAggregations['Pohlaví (odhad)']['👨 Muž'] ??= 0;
                    $otherAggregations['Pohlaví (odhad)']['👨 Muž']++;
                    break;
                case AbstractContact::GENDER_FEMALE:
                    $otherAggregations['Pohlaví (odhad)']['👩 Žena'] ??= 0;
                    $otherAggregations['Pohlaví (odhad)']['👩 Žena']++;
                    break;
                default:
                    $otherAggregations['Pohlaví (odhad)']['👤 Neurčeno'] ??= 0;
                    $otherAggregations['Pohlaví (odhad)']['👤 Neurčeno']++;
                    break;
            }
            // Payments aggregation.
            $paymentsAggregation['Celkem cena (s příznaky)'] += $participant->getPrice();
            $paymentsAggregation['Celkem základní cena (bez příznaků)'] += $participant->getPrice() - $participant->getFlagsPrice();
            $paymentsAggregation['Celkem záloha (s příznaky)'] += $participant->getDepositValue();
            $paymentsAggregation['Zaplacená cena'] += $participant->getPaidPrice();
            $paymentsAggregation['Celkem cena za příznaky'] += $participant->getFlagsPrice();
            $paymentsAggregation['Celkem záloha za příznaky'] += $participant->getFlagsDepositValue();
            $paymentsAggregation['Zbývající záloha (s příznaky)'] += $participant->getRemainingDeposit();
            $paymentsAggregation['Zbývající cena (s příznaky)'] += $participant->getRemainingPrice();
        }
        $regRanges = $this->regRangeService->getRepository()->getRegistrationsRanges([RegRangeRepository::CRITERIA_EVENT => $event]);
        ksort($flagsUsageByRange);
        ksort($flagsUsageByFlag);

        return $this->render(
            '@OswisOrgOswisCalendar/web_admin/event.html.twig',
            [
                'title'               => "Přehled události :: ADMIN",
                'event'               => $event,
                'occupancy'           => $occupancy,
                'flagsUsageByRange'   => $flagsUsageByRange,
                'flagsUsageByFlag'    => $flagsUsageByFlag,
                'regRanges'           => $regRanges,
                'otherAggregations'   => $otherAggregations,
                'paymentsAggregation' => $paymentsAggregation,
                'isDefaultEvent'      => $isDefaultEvent,
            ]
        );
    }
}
