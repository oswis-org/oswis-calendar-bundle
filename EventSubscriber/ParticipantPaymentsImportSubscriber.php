<?php
/**
 * @noinspection RedundantDocCommentTagInspection
 */

namespace OswisOrg\OswisCalendarBundle\EventSubscriber;

use ApiPlatform\Core\EventListener\EventPriorities;
use Exception;
use OswisOrg\OswisCalendarBundle\Entity\NonPersistent\CsvPaymentImportSettings;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantPaymentsImport;
use OswisOrg\OswisCalendarBundle\Service\Participant\ParticipantPaymentsImportService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ViewEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class ParticipantPaymentsImportSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly ParticipantPaymentsImportService $paymentsImportService,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::VIEW => [
                ['postWrite', EventPriorities::POST_WRITE],
            ],
        ];
    }

    /**
     * @param  ViewEvent  $event
     *
     * @throws Exception
     */
    public function postWrite(ViewEvent $event): void
    {
        $paymentsImport = $event->getControllerResult();
        $method = $event->getRequest()->getMethod();
        if (!$paymentsImport instanceof ParticipantPaymentsImport || Request::METHOD_POST !== $method) {
            return;
        }
        // TODO: Make settings abstract and changeable!
        $this->paymentsImportService->processImport($paymentsImport, new CsvPaymentImportSettings());
    }
}
