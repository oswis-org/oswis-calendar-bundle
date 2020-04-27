<?php
/**
 * @noinspection RedundantDocCommentTagInspection
 * @noinspection PhpUnused
 */

namespace OswisOrg\OswisCalendarBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;

class EventLeafletController extends AbstractController
{
    public function __construct()
    {
    }

    /**
     * @param string|null $slug
     *
     * @return Response
     */
    final public function eventLeafletPdf(?string $slug = null): Response
    {
        return $this->render('@OswisOrgOswisCalendar/other/leaflet/leaflet.html.twig', ['slug' => $slug]);
    }
}
