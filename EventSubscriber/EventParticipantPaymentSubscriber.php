<?php

namespace Zakjakub\OswisCalendarBundle\EventSubscriber;

use ApiPlatform\Core\EventListener\EventPriorities;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ViewEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Mailer\MailerInterface;
use Zakjakub\OswisCalendarBundle\Entity\EventParticipant\EventParticipantPayment;
use Zakjakub\OswisCalendarBundle\Manager\EventParticipantPaymentManager;
use Zakjakub\OswisCoreBundle\Exceptions\OswisException;
use Zakjakub\OswisCoreBundle\Provider\OswisCoreSettingsProvider;

/**
 * Class EventParticipantPaymentSubscriber
 * @package Zakjakub\OswisAccommodationBundle\EventSubscriber
 */
final class EventParticipantPaymentSubscriber implements EventSubscriberInterface
{

    /**
     * @var EntityManagerInterface
     */
    private EntityManagerInterface $em;

    /**
     * @var MailerInterface
     */
    private MailerInterface $mailer;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @var OswisCoreSettingsProvider
     */
    private OswisCoreSettingsProvider $oswisCoreSettings;

    /**
     * @param EntityManagerInterface    $em
     * @param MailerInterface           $mailer
     * @param LoggerInterface           $logger
     * @param OswisCoreSettingsProvider $oswisCoreSettings
     */
    public function __construct(
        EntityManagerInterface $em,
        MailerInterface $mailer,
        LoggerInterface $logger,
        OswisCoreSettingsProvider $oswisCoreSettings
    ) {
        $this->em = $em;
        $this->mailer = $mailer;
        $this->logger = $logger;
        $this->oswisCoreSettings = $oswisCoreSettings;
    }

    /**
     * @return array
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::VIEW => [
                ['postWrite', EventPriorities::POST_WRITE],
                ['postValidate', EventPriorities::POST_VALIDATE],
            ],
        ];
    }

    /**
     * @param ViewEvent $event
     *
     * @throws Exception
     * @noinspection PhpUnused
     */
    public function postWrite(ViewEvent $event): void
    {
        $eventParticipantPayment = $event->getControllerResult();
        $method = $event->getRequest()->getMethod();
        if (!$eventParticipantPayment instanceof EventParticipantPayment || Request::METHOD_POST !== $method) {
            return;
        }
        $eventParticipantPaymentManager = new EventParticipantPaymentManager($this->em, $this->mailer, $this->logger, $this->oswisCoreSettings);
        $eventParticipantPaymentManager->sendConfirmation($eventParticipantPayment);
    }

    /**
     * @param ViewEvent $event
     *
     * @throws OswisException
     * @throws SuspiciousOperationException
     * @noinspection PhpUnused
     */
    public function postValidate(ViewEvent $event): void
    {
        $eventParticipantPayment = $event->getControllerResult();
        $method = $event->getRequest()->getMethod();
        if (!$eventParticipantPayment instanceof EventParticipantPayment) {
            return;
        }
        if (Request::METHOD_PUT === $method) {
            throw new OswisException('Změna platby není povolena.');
        }
    }
}
