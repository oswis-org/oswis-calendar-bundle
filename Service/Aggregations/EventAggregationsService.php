<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Service\Aggregations;

use Doctrine\Common\Collections\Collection;
use OswisOrg\OswisCalendarBundle\Entity\Event\Event;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantCategory;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantNote;
use OswisOrg\OswisCalendarBundle\Repository\Participant\ParticipantRepository;
use OswisOrg\OswisCalendarBundle\Repository\Registration\RegistrationOfferRepository;
use OswisOrg\OswisCalendarBundle\Service\Participant\ParticipantService;
use OswisOrg\OswisCalendarBundle\Service\Registration\RegistrationOfferService;
use OswisOrg\OswisCoreBundle\Interfaces\AddressBook\ContactInterface;

/**
 * Builds the per-event admin dashboard data: occupancy, flag-usage breakdown,
 * payments roll-up, miscellaneous binary metrics (notes / formal addressing /
 * gender estimate / verified accounts). Pure read; idempotent.
 *
 * Output shape is dictated by the @OswisOrgOswisCalendar/web_admin/event.html.twig template.
 */
final readonly class EventAggregationsService
{
    private const string GENDER_APPROX_KEY = 'Pohlaví (odhad)';

    public function __construct(
        private ParticipantService        $participantService,
        private RegistrationOfferService  $registrationOfferService,
    ) {
    }

    /**
     * @return array{
     *     occupancy: array{event: Event, occupancy: int, subEvents: list<array{event: Event, occupancy: int}>},
     *     flagsUsageByRange: array<int, array<string, mixed>>,
     *     flagsUsageByFlag: array<int, array<string, mixed>>,
     *     otherAggregations: array<string, array<string, int>>,
     *     paymentsAggregation: array<string, int|float>,
     *     offers: Collection,
     * }
     */
    public function getEventAggregations(Event $event): array
    {
        $participants = $this->participantService->getParticipants([
            ParticipantRepository::CRITERIA_INCLUDE_DELETED       => false,
            ParticipantRepository::CRITERIA_EVENT                 => $event,
            ParticipantRepository::CRITERIA_PARTICIPANT_TYPE      => ParticipantCategory::TYPE_ATTENDEE,
            ParticipantRepository::CRITERIA_EVENT_RECURSIVE_DEPTH => 1,
        ]);

        [$flagsUsageByRange, $flagsUsageByFlag, $otherAggregations, $paymentsAggregation]
            = $this->aggregateParticipants($participants);

        ksort($flagsUsageByRange);
        ksort($flagsUsageByFlag);

        return [
            'occupancy'           => $this->buildOccupancy($event, $participants->count()),
            'flagsUsageByRange'   => $flagsUsageByRange,
            'flagsUsageByFlag'    => $flagsUsageByFlag,
            'otherAggregations'   => $otherAggregations,
            'paymentsAggregation' => $paymentsAggregation,
            'offers'              => $this->getRegistrationOffers($event),
        ];
    }

    /**
     * @return array{event: Event, occupancy: int, subEvents: list<array{event: Event, occupancy: int}>}
     */
    private function buildOccupancy(Event $event, int $occupancy): array
    {
        $subEventCounts = $this->participantService->getRepository()->countAttendeesGroupedBySubEvent($event);
        $subEvents = [];
        foreach ($event->getSubEvents() as $subEvent) {
            $subEvents[] = [
                'event'     => $subEvent,
                'occupancy' => $subEventCounts[(int) $subEvent->getId()] ?? 0,
            ];
        }

        return ['event' => $event, 'occupancy' => $occupancy, 'subEvents' => $subEvents];
    }

    /**
     * Walks the participants collection once, building the four parallel aggregations
     * the dashboard template consumes.
     *
     * @param Collection<int, Participant> $participants
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>, 2: array<string, array<string, int>>, 3: array<string, int|float>}
     */
    private function aggregateParticipants(Collection $participants): array
    {
        $flagsUsageByRange = [];
        $flagsUsageByFlag = [];
        $otherAggregations = [];
        $paymentsAggregation = [
            'Celkem cena (s příznaky)'              => 0,
            'Celkem základní cena (bez příznaků)'   => 0,
            'Celkem záloha (s příznaky)'            => 0,
            'Zaplacená cena'                        => 0,
            'Celkem cena za příznaky'               => 0,
            'Celkem záloha za příznaky'             => 0,
            'Zbývající záloha (s příznaky)'         => 0,
            'Zbývající cena (s příznaky)'           => 0,
        ];

        foreach ($participants as $participant) {
            $this->collectFlagUsage($participant, $flagsUsageByRange, $flagsUsageByFlag);
            $this->collectOtherAggregations($participant, $otherAggregations);
            $this->collectPayments($participant, $paymentsAggregation);
        }

        return [$flagsUsageByRange, $flagsUsageByFlag, $otherAggregations, $paymentsAggregation];
    }

    /**
     * @param array<int, array<string, mixed>> $flagsUsageByRange
     * @param array<int, array<string, mixed>> $flagsUsageByFlag
     */
    private function collectFlagUsage(Participant $participant, array &$flagsUsageByRange, array &$flagsUsageByFlag): void
    {
        foreach ($participant->getParticipantFlags(null, null, true) as $participantFlag) {
            $flagRange = $participantFlag->getFlagOffer();
            if (null === $flagRange) {
                continue;
            }
            $participantFlagGroup = $participantFlag->getParticipantFlagGroup();
            $flagGroupRange = $participantFlagGroup?->getFlagGroupOffer();
            $flagGroupRangeId = $flagGroupRange?->getId() ?? 0;
            $flag = $flagRange->getFlag();
            $flagId = $flag?->getId() ?? 0;
            $flagCategory = $flagRange->getCategory();
            $flagCategoryId = $flagCategory?->getId() ?? 0;
            $flagRangeId = $flagRange->getId() ?? 0;

            // Initialise the per-flagGroupRange entry with all required keys.
            $rangeEntry = is_array($flagsUsageByRange[$flagGroupRangeId] ?? null)
                ? $flagsUsageByRange[$flagGroupRangeId]
                : [];
            $rangeEntry['flagCategory']   = $flagCategory;
            $rangeEntry['flagGroupRange'] = $flagGroupRange;
            $rangeItems = is_array($rangeEntry['items'] ?? null) ? $rangeEntry['items'] : [];
            $rangeItem = is_array($rangeItems[$flagRangeId] ?? null) ? $rangeItems[$flagRangeId] : [];
            $rangeItem['entity'] = $flagRange;
            $rangeCurrent = $rangeItem['usage'] ?? 0;
            $rangeItem['usage'] = (is_int($rangeCurrent) ? $rangeCurrent : 0) + 1;
            $rangeItems[$flagRangeId] = $rangeItem;
            $rangeEntry['items'] = $rangeItems;
            $flagsUsageByRange[$flagGroupRangeId] = $rangeEntry;

            // Same pattern for byFlag (one level shallower).
            $flagEntry = is_array($flagsUsageByFlag[$flagCategoryId] ?? null)
                ? $flagsUsageByFlag[$flagCategoryId]
                : [];
            $flagEntry['flagCategory'] = $flagCategory;
            $flagItems = is_array($flagEntry['items'] ?? null) ? $flagEntry['items'] : [];
            $flagItem = is_array($flagItems[$flagId] ?? null) ? $flagItems[$flagId] : [];
            $flagItem['entity'] = $flag;
            $flagCurrent = $flagItem['usage'] ?? 0;
            $flagItem['usage'] = (is_int($flagCurrent) ? $flagCurrent : 0) + 1;
            $flagItems[$flagId] = $flagItem;
            $flagEntry['items'] = $flagItems;
            $flagsUsageByFlag[$flagCategoryId] = $flagEntry;
        }
    }

    /**
     * @param array<string, array<string, int>> $otherAggregations
     */
    private function collectOtherAggregations(Participant $participant, array &$otherAggregations): void
    {
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
        $genderLabel = match ($participant->getContact()?->getGender()) {
            ContactInterface::GENDER_MALE   => '👨 Muž',
            ContactInterface::GENDER_FEMALE => '👩 Žena',
            default                         => '👤 Neurčeno',
        };
        $otherAggregations[self::GENDER_APPROX_KEY][$genderLabel] ??= 0;
        $otherAggregations[self::GENDER_APPROX_KEY][$genderLabel]++;
    }

    /**
     * @param array<string, int|float> $paymentsAggregation
     */
    private function collectPayments(Participant $participant, array &$paymentsAggregation): void
    {
        $paymentsAggregation['Celkem cena (s příznaky)']            += $participant->getPrice();
        $paymentsAggregation['Celkem základní cena (bez příznaků)'] += $participant->getPrice() - $participant->getFlagsPrice();
        $paymentsAggregation['Celkem záloha (s příznaky)']          += $participant->getDepositValue();
        $paymentsAggregation['Zaplacená cena']                      += $participant->getPaidPrice();
        $paymentsAggregation['Celkem cena za příznaky']             += $participant->getFlagsPrice();
        $paymentsAggregation['Celkem záloha za příznaky']           += $participant->getFlagsDepositValue();
        $paymentsAggregation['Zbývající záloha (s příznaky)']       += $participant->getRemainingDeposit();
        $paymentsAggregation['Zbývající cena (s příznaky)']         += $participant->getRemainingPrice();
    }

    private function getRegistrationOffers(Event $event): Collection
    {
        $offerRepo = $this->registrationOfferService->getRepository();
        $offers = $offerRepo->getRegistrationsRanges(
            [RegistrationOfferRepository::CRITERIA_EVENT => $event]
        );

        // Pull in any offer that's marked as "requires" one of the currently-listed offers
        // (e.g. follow-up offers that only open once a prerequisite offer has been used).
        $required = [];
        foreach ($offers as $offer) {
            $required = [
                ...$required,
                ...$offerRepo->getRegistrationsRanges(
                    [RegistrationOfferRepository::CRITERIA_REQUIRED_REG_RANGE => $offer],
                ),
            ];
        }
        foreach ($required as $offer) {
            if (!$offers->contains($offer)) {
                $offers->add($offer);
            }
        }

        return $offers;
    }
}
