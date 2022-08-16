<?php
/**
 * @noinspection PropertyCanBePrivateInspection
 * @noinspection RedundantDocCommentTagInspection
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Service\Event;

use DateTime;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use OswisOrg\OswisCalendarBundle\Controller\Event\EventController;
use OswisOrg\OswisCalendarBundle\Entity\Event\Event;
use OswisOrg\OswisCalendarBundle\Provider\OswisCalendarSettingsProvider;
use OswisOrg\OswisCalendarBundle\Repository\Event\EventRepository;
use OswisOrg\OswisCoreBundle\Utils\DateTimeUtils;
use Psr\Log\LoggerInterface;

class EventService
{
    protected ?Event $defaultEvent = null;

    public function __construct(
        protected EntityManagerInterface $em,
        protected LoggerInterface $logger,
        protected OswisCalendarSettingsProvider $calendarSettings,
        protected EventRepository $eventRepository
    ) {
        $this->setDefaultEvent();
    }

    public function getCalendarSettings(): OswisCalendarSettingsProvider
    {
        return $this->calendarSettings;
    }

    public function create(Event $event): Event
    {
        $this->em->persist($event);
        $this->em->flush();
        $this->logger->info('CREATE: Created event (by service): '.$event->getId().' '.$event->getName().'.');

        return $event;
    }

    public function getDefaultEvent(): ?Event
    {
        return $this->defaultEvent;
    }

    public function setDefaultEvent(): ?Event
    {
        $opts = [
            EventRepository::CRITERIA_SLUG => $this->calendarSettings->getDefaultEvent(),
            EventRepository::CRITERIA_ONLY_PUBLIC_ON_WEB => true,
            EventRepository::CRITERIA_INCLUDE_DELETED => false,
        ];
        $event = $this->getRepository()->getEvent($opts);
        foreach ($this->calendarSettings->getDefaultEventFallbacks() as $fallback) {
            if (null === $event && !empty($fallback)) {
                $opts[EventRepository::CRITERIA_SLUG] = $fallback;
                $event = $this->getRepository()->getEvent($opts);
            }
        }

        return $this->defaultEvent = $event;
    }

    /**
     * @param string|null   $range
     * @param DateTime|null $start
     * @param DateTime|null $end
     * @param int|null      $limit
     * @param int|null      $offset
     * @param string|null   $eventSlug
     * @param bool|null     $onlyRoot
     *
     * @return Collection
     * @throws Exception
     */
    public function getEvents(
        ?string $range = null,
        ?DateTime $start = null,
        ?DateTime $end = null,
        ?int $limit = null,
        ?int $offset = null,
        ?string $eventSlug = null,
        ?bool $onlyRoot = true
    ): Collection {
        $range ??= EventController::RANGE_ALL;
        $limit = $limit < 1 ? null : $limit;
        $offset = $offset < 1 ? null : $offset;
        $start = DateTimeUtils::getDateTimeByRange($start, $range, false);
        $end = DateTimeUtils::getDateTimeByRange($end, $range, true);
        $opts = [
            EventRepository::CRITERIA_START => $start,
            EventRepository::CRITERIA_END => $end,
            EventRepository::CRITERIA_INCLUDE_DELETED => false,
            EventRepository::CRITERIA_ONLY_PUBLIC_ON_WEB => true,
            EventRepository::CRITERIA_ONLY_ROOT => $onlyRoot,
            EventRepository::CRITERIA_SLUG => $eventSlug,
        ];

        return $this->getRepository()->getEvents($opts, $limit, $offset);
    }

    public function getRepository(): EventRepository
    {
        return $this->eventRepository;
    }
}