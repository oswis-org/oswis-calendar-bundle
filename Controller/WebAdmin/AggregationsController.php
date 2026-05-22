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
        $aggregations = $this->aggregationsService->getAggregations();

        foreach ($aggregations as &$year) {
            $createdSum = 0;
            $deletedSum = 0;
            $activeSum = 0;

            $year['created_attendees_chart'] = '[';
            $year['created_attendees_sum_chart'] = '[';
            $year['deleted_attendees_chart'] = '[';
            $year['deleted_attendees_sum_chart'] = '[';
            $year['active_attendees_chart'] = '[';
            $year['active_attendees_sum_chart'] = '[';
            foreach ($year['days'] as &$day) {
                $createdSum += (int) ($day['created_attendees'] ?? 0);
                $deletedSum += (int) ($day['deleted_attendees'] ?? 0);
                $active = (int) ($day['created_attendees'] ?? 0) - (int) ($day['deleted_attendees'] ?? 0);
                $activeSum += $active;

                $day['created_attendees_sum'] = $year['created_attendees_sum'] = $createdSum;
                $day['deleted_attendees_sum'] = $year['deleted_attendees_sum'] = $deletedSum;
                $day['active_attendees']      = $active;
                $day['active_attendees_sum']  = $year['active_attendees_sum'] = $activeSum;

                $ts = (new DateTimeImmutable((string) ($day['fake_date'] ?? '')))->format('Y-m-d\T00:00:00.0');
                $year['created_attendees_chart']     .= "['$ts', " . ($day['created_attendees'] ?? 0) . '],';
                $year['created_attendees_sum_chart'] .= "['$ts', $createdSum],";
                $year['deleted_attendees_chart']     .= "['$ts', " . ($day['deleted_attendees'] ?? 0) . '],';
                $year['deleted_attendees_sum_chart'] .= "['$ts', $deletedSum],";
                $year['active_attendees_chart']      .= "['$ts', $active],";
                $year['active_attendees_sum_chart']  .= "['$ts', $activeSum],";
            }
            unset($day);
            $year['created_attendees_chart']     .= ']';
            $year['created_attendees_sum_chart'] .= ']';
            $year['deleted_attendees_chart']     .= ']';
            $year['deleted_attendees_sum_chart'] .= ']';
            $year['active_attendees_chart']      .= ']';
            $year['active_attendees_sum_chart']  .= ']';
        }
        unset($year);

        return $this->render('@OswisOrgOswisCalendar/web_admin/aggregations.html.twig', [
            'title' => 'Agregace :: ADMIN',
            'aggregations' => $aggregations,
        ]);
    }
}
