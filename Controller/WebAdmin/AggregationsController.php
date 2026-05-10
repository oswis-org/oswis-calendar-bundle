<?php

namespace OswisOrg\OswisCalendarBundle\Controller\WebAdmin;

use DateTimeImmutable;
use Doctrine\DBAL\Exception;
use OswisOrg\OswisCalendarBundle\Service\Aggregations\AggregationsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;

class AggregationsController extends AbstractController
{
    public function __construct(
        readonly private AggregationsService $aggregationsService,
    ) {
    }

    /**
     * @throws Exception
     */
    final public function showAggregations(): Response
    {
        $data['title'] = "Agregace :: ADMIN";
        $data['aggregations'] = $this->aggregationsService->getAggregations();

        foreach ($data['aggregations'] as &$year) {
            $createdSum = 0;
            $deletedSum = 0;
            $notDeletedSum = 0;
            $activeSum = 0;

            $year['created_attendees_chart'] = '[';
            $year['created_attendees_sum_chart'] = '[';
            $year['deleted_attendees_chart'] = '[';
            $year['deleted_attendees_sum_chart'] = '[';
            $year['not_deleted_attendees_chart'] = '[';
            $year['not_deleted_attendees_sum_chart'] = '[';
            $year['active_attendees_chart'] = '[';
            $year['active_attendees_sum_chart'] = '[';
            foreach ($year['days'] as &$day) {
                $createdSum += $day['created_attendees'] ?? 0;
                $deletedSum += $day['deleted_attendees'] ?? 0;
                $notDeletedSum += $day['not_deleted_attendees'] ?? 0;
                $active = (($day['created_attendees'] ?? 0) - ($day['deleted_attendees'] ?? 0));
                $activeSum += $active;

                $year['created_attendees_sum'] = $day['created_attendees_sum'] = $createdSum;
                $year['deleted_attendees_sum'] = $day['deleted_attendees_sum'] = $deletedSum;
                $year['not_deleted_attendees_sum'] = $day['not_deleted_attendees_sum'] = $notDeletedSum;
                $year['active_attendees_sum'] = $day['active_attendees_sum'] = $activeSum;

                $year['created_attendees_chart'] .= '[\'' . (new DateTimeImmutable($day['fake_date']))->format('Y-m-d\T00:00:00.0') . '\', ' . $day['created_attendees'] . '],';
                $year['created_attendees_sum_chart'] .= '[\'' . (new DateTimeImmutable($day['fake_date']))->format('Y-m-d\T00:00:00.0') . '\', ' . $createdSum . '],';
                $year['deleted_attendees_chart'] .= '[\'' . (new DateTimeImmutable($day['fake_date']))->format('Y-m-d\T00:00:00.0') . '\', ' . $day['deleted_attendees'] . '],';
                $year['deleted_attendees_sum_chart'] .= '[\'' . (new DateTimeImmutable($day['fake_date']))->format('Y-m-d\T00:00:00.0') . '\', ' . $deletedSum . '],';
                $year['not_deleted_attendees_chart'] .= '[\'' . (new DateTimeImmutable($day['fake_date']))->format('Y-m-d\T00:00:00.0') . '\', ' . $day['not_deleted_attendees'] . '],';
                $year['not_deleted_attendees_sum_chart'] .= '[\'' . (new DateTimeImmutable($day['fake_date']))->format('Y-m-d\T00:00:00.0') . '\', ' . $notDeletedSum . '],';
                $year['active_attendees_chart'] .= '[\'' . (new DateTimeImmutable($day['fake_date']))->format('Y-m-d\T00:00:00.0') . '\', ' . $active . '],';
                $year['active_attendees_sum_chart'] .= '[\'' . (new DateTimeImmutable($day['fake_date']))->format('Y-m-d\T00:00:00.0') . '\', ' . $activeSum . '],';
            }
            unset($day);
            $year['created_attendees_chart'] .= ']';
            $year['created_attendees_sum_chart'] .= ']';
            $year['deleted_attendees_chart'] .= ']';
            $year['deleted_attendees_sum_chart'] .= ']';
            $year['not_deleted_attendees_chart'] .= ']';
            $year['not_deleted_attendees_sum_chart'] .= ']';
            $year['active_attendees_chart'] .= ']';
            $year['active_attendees_sum_chart'] .= ']';

        }
        unset($year);

        return $this->render('@OswisOrgOswisCalendar/web_admin/aggregations.html.twig', $data);
    }
}
