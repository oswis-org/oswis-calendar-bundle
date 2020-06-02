<?php

namespace OswisOrg\OswisCalendarBundle\Api\EventSubscriber;

use ApiPlatform\Core\EventListener\EventPriorities;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use OswisOrg\OswisCalendarBundle\Api\Dto\ParticipantPaymentActionRequest;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantPayment;
use OswisOrg\OswisCalendarBundle\Service\ParticipantPaymentService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ViewEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use function assert;
use function count;
use function in_array;

final class ParticipantPaymentActionSubscriber implements EventSubscriberInterface
{
    public const ALLOWED_ACTION_TYPES = ['send-confirmation', 'csv'];

    private EntityManagerInterface $em;

    private ParticipantPaymentService $participantPaymentService;

    public function __construct(EntityManagerInterface $em, ParticipantPaymentService $participantPaymentService)
    {
        $this->em = $em;
        $this->participantPaymentService = $participantPaymentService;
    }

    /**
     * @return array<string, array<int|string>>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::VIEW => ['reservationPaymentAction', EventPriorities::POST_VALIDATE],
        ];
    }

    /**
     * @param ViewEvent $event
     *
     * @throws Exception
     * @noinspection PhpUnused
     */
    public function reservationPaymentAction(ViewEvent $event): void
    {
        $request = $event->getRequest();
        if ('api_participant_payment_action_requests_post_collection' !== $request->attributes->get('_route')) {
            return;
        }
        $output = null;
        $paymentActionRequest = $event->getControllerResult();
        $identifiers = $paymentActionRequest->identifiers;
        $type = $paymentActionRequest->type;
        if (!in_array($type, self::ALLOWED_ACTION_TYPES, true)) {
            $event->setResponse(new JsonResponse(null, Response::HTTP_NOT_IMPLEMENTED));

            return;
        }
        if ('csv' === $type) {
            $event->setResponse($this->paymentCsvAction($paymentActionRequest));

            return;
        }
        if ($identifiers && count($identifiers) > 0) {
            $participantPaymentRepository = $this->em->getRepository(ParticipantPayment::class);
            $processedActionsCount = 0;
            $reservations = new ArrayCollection();
            foreach ($identifiers as $id) {
                $payment = $participantPaymentRepository->findOneBy(['id' => $id]);
                if (!$payment) {
                    continue;
                }
                assert($payment instanceof ParticipantPayment);
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
                }
            }
            if ($processedActionsCount === 0) {
                $event->setResponse(new JsonResponse(null, Response::HTTP_NOT_FOUND));

                return;
            }
//            if ($output) {
//                $data = ['data' => chunk_split(base64_encode($output))];
//                $event->setResponse(new JsonResponse($data, Response::HTTP_CREATED));
//
//                return;
//            }
            $event->setResponse(new JsonResponse(null, Response::HTTP_NO_CONTENT));
        }
        $event->setResponse(new JsonResponse(null, Response::HTTP_NOT_IMPLEMENTED));
    }

    public function paymentCsvAction(ParticipantPaymentActionRequest $reservationPaymentActionRequest): Response
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
        if (!$event) {
            return new JsonResponse('Vytvořeno 0 plateb z CSV. Událost nenalezena.', Response::HTTP_NOT_FOUND);
        }
        $successPaymentsCount = $this->participantPaymentService->createFromCsv(
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

        return new JsonResponse("Vytvořeno $successPaymentsCount plateb z CSV.", Response::HTTP_CREATED);
    }
}