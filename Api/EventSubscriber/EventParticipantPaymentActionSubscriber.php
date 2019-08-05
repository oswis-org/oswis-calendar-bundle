<?php

namespace Zakjakub\OswisCalendarBundle\Api\EventSubscriber;

use ApiPlatform\Core\EventListener\EventPriorities;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ViewEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Mailer\MailerInterface;
use Zakjakub\OswisCalendarBundle\Entity\EventParticipant\EventParticipantPayment;
use Zakjakub\OswisCalendarBundle\Manager\EventParticipantPaymentManager;
use Zakjakub\OswisCoreBundle\Provider\OswisCoreSettingsProvider;
use function assert;
use function count;
use function in_array;

final class EventParticipantPaymentActionSubscriber implements EventSubscriberInterface
{

    public const ALLOWED_ACTION_TYPES = ['send-confirmation', 'csv'];

    /**
     * @var EntityManagerInterface
     */
    private $em;

    /**
     * @var EventParticipantPaymentManager
     */
    private $eventParticipantPaymentManager;

    public function __construct(
        EntityManagerInterface $em,
        MailerInterface $mailer,
        LoggerInterface $logger,
        OswisCoreSettingsProvider $oswisCoreSettings
    ) {
        $this->em = $em;
        $this->eventParticipantPaymentManager = new EventParticipantPaymentManager($em, $mailer, $logger, $oswisCoreSettings);
    }

    /**
     * @return array
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::VIEW => ['reservationPaymentAction', EventPriorities::POST_VALIDATE],
        ];
    }

    /** @noinspection PhpUnused */
    /**
     * @param ViewEvent $event
     *
     * @throws Exception
     */
    public function reservationPaymentAction(ViewEvent $event): void
    {

        $request = $event->getRequest();

        if ('api_event_participant_payment_action_requests_post_collection' !== $request->attributes->get('_route')) {
            return;
        }

        $output = null;
        $reservationPaymentActionRequest = $event->getControllerResult();

        $identifiers = $reservationPaymentActionRequest->identifiers;
        $type = $reservationPaymentActionRequest->type;

        if (!in_array($type, self::ALLOWED_ACTION_TYPES, true)) {
            $event->setResponse(new JsonResponse(null, Response::HTTP_NOT_IMPLEMENTED));

            return;
        }

        if ('csv' === $type) {
            $this->paymentCsvAction($reservationPaymentActionRequest);

            return;
        }
        if ($identifiers && count($identifiers) > 0) {
            $eventParticipantPaymentRepository = $this->em->getRepository(EventParticipantPayment::class);

            $processedActionsCount = 0;
            $reservations = new ArrayCollection();
            foreach ($identifiers as $id) {
                $payment = $eventParticipantPaymentRepository->findOneBy(['id' => $id]);
                if (!$payment) {
                    continue;
                }
                assert($payment instanceof ReservationPayment);
                $reservations->add($payment);
                switch ($type) {
                    case 'get-receipt-pdf':
                        // $output = $this->reservationPaymentManager->createReceiptPdfString($payment);
                        $processedActionsCount++;
                        break;
                    case 'send-receipt-pdf-customer':
                        // $this->reservationPaymentManager->sendReceiptPdf($payment);
                        $processedActionsCount++;
                        break;
                    default:
                        $event->setResponse(new JsonResponse(null, Response::HTTP_NOT_IMPLEMENTED));

                        return;
                        break;
                }
            }

            if ($processedActionsCount === 0) {
                $event->setResponse(new JsonResponse(null, Response::HTTP_NOT_FOUND));

                return;
            }

            if ($output) {
                $data = ['data' => chunk_split(base64_encode($output))];
                $event->setResponse(new JsonResponse($data, Response::HTTP_CREATED));

                return;
            }

            $event->setResponse(new JsonResponse(null, Response::HTTP_NO_CONTENT));
        }

        $event->setResponse(new JsonResponse(null, Response::HTTP_NOT_IMPLEMENTED));
    }

    public function paymentCsvAction(mixed $reservationPaymentActionRequest): Response
    {
        $event = $reservationPaymentActionRequest->event ?? null;
        $csvContent = $reservationPaymentActionRequest->csvContent ?? null;
        $csvDelimiter = $reservationPaymentActionRequest->csvDelimiter ?? null;
        $csvEnclosure = $reservationPaymentActionRequest->csvEnclosure ?? null;
        $csvEscape = $reservationPaymentActionRequest->csvEscape ?? null;
        $csvVariableSymbolColumnName = $reservationPaymentActionRequest->csvVariableSymbolColumnName ?? null;
        $csvDateColumnName = $reservationPaymentActionRequest->csvDateColumnName ?? null;
        $csvValueColumnName = $reservationPaymentActionRequest->csvValueColumnName ?? null;
        $csvCurrencyColumnName = $reservationPaymentActionRequest->csvCurrencyColumnName ?? null;
        $csvCurrencyAllowed = $reservationPaymentActionRequest->csvCurrencyAllowed ?? null;
        $csvEventParticipantType = $reservationPaymentActionRequest->csvEventParticipantType ?? null;
        $successPaymentsCount = $this->eventParticipantPaymentManager->createFromCsv(
            $event,
            $csvContent,
            $csvEventParticipantType,
            $csvDelimiter,
            $csvEnclosure,
            $csvEscape,
            $csvVariableSymbolColumnName,
            $csvDateColumnName,
            $csvValueColumnName,
            $csvCurrencyColumnName,
            $csvCurrencyAllowed
        );

        return new JsonResponse(['data' => chunk_split(base64_encode("Vytvořeno $successPaymentsCount plateb z CSV."))], Response::HTTP_CREATED);
    }


}
