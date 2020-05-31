<?php
/**
 * @noinspection RedundantDocCommentTagInspection
 * @noinspection MethodShouldBeFinalInspection
 * @noinspection PhpUnused
 */

namespace OswisOrg\OswisCalendarBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use OswisOrg\OswisCalendarBundle\Entity\Event\Event;
use OswisOrg\OswisCalendarBundle\Provider\OswisCalendarSettingsProvider;
use OswisOrg\OswisCalendarBundle\Repository\EventRepository;
use Psr\Log\LoggerInterface;

class EventService
{
    protected EntityManagerInterface $em;

    protected LoggerInterface $logger;

    protected OswisCalendarSettingsProvider $calendarSettings;

    protected ?Event $defaultEvent = null;

    public function __construct(EntityManagerInterface $em, LoggerInterface $logger, OswisCalendarSettingsProvider $calendarSettings)
    {
        $this->em = $em;
        $this->logger = $logger;
        $this->calendarSettings = $calendarSettings;
        $this->setDefaultEvent();
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
            EventRepository::CRITERIA_SLUG               => $this->calendarSettings->getDefaultEvent(),
            EventRepository::CRITERIA_ONLY_PUBLIC_ON_WEB => true,
            EventRepository::CRITERIA_INCLUDE_DELETED    => false,
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

    public function getRepository(): EventRepository
    {
        $repository = $this->em->getRepository(Event::class);
        assert($repository instanceof EventRepository);

        return $repository;
    }
}