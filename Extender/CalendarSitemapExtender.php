<?php
/**
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Extender;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use OswisOrg\OswisCalendarBundle\Repository\Event\EventRepository;
use OswisOrg\OswisCalendarBundle\Service\Event\EventService;
use OswisOrg\OswisCoreBundle\Entity\NonPersistent\SiteMapItem;
use OswisOrg\OswisCoreBundle\Interfaces\Web\SiteMapExtenderInterface;
use Symfony\Component\Routing\Exception\InvalidParameterException;
use Symfony\Component\Routing\Exception\MissingMandatoryParametersException;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class CalendarSitemapExtender implements SiteMapExtenderInterface
{
    public function __construct(
        protected UrlGeneratorInterface $urlGenerator,
        protected EventService $eventService,
    )
    {
    }

    public function getItems(): Collection
    {
        $items = new ArrayCollection();
        foreach (
            $this->eventService->getRepository()->getEvents([EventRepository::CRITERIA_ONLY_PUBLIC_ON_WEB => true]
            ) as $event
        ) {
            try {
                $items->add(
                    new SiteMapItem(
                        $this->urlGenerator->generate(
                            'oswis_org_oswis_calendar_web_event',
                            ['eventSlug' => $event->getSlug()]
                        ), null, $event->getUpdatedAt()
                    )
                );
                $items->add(
                    new SiteMapItem(
                        $this->urlGenerator->generate(
                            'oswis_org_oswis_calendar_web_event_leaflet',
                            ['eventSlug' => $event->getSlug()]
                        ), null, $event->getUpdatedAt()
                    )
                );
            } catch (InvalidParameterException|RouteNotFoundException|MissingMandatoryParametersException $e) {
            }
        }
        $items->add(new SiteMapItem($this->urlGenerator->generate('oswis_org_oswis_calendar_web_events')));
        $items->add(new SiteMapItem($this->urlGenerator->generate('oswis_org_oswis_calendar_web_events_future')));
        $items->add(new SiteMapItem($this->urlGenerator->generate('oswis_org_oswis_calendar_web_events_past')));
        $items->add(new SiteMapItem($this->urlGenerator->generate('oswis_org_oswis_calendar_web_events_calendar')));
        $items->add(new SiteMapItem($this->urlGenerator->generate('oswis_org_oswis_calendar_web_events_kalendar')));

        return $items;
    }
}
