<?php

namespace Zakjakub\OswisAccommodationBundle\EventSubscriber;

use ApiPlatform\Core\EventListener\EventPriorities;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ViewEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Security\Core\Encoder\UserPasswordEncoderInterface;
use Zakjakub\OswisCalendarBundle\Entity\EventParticipant\EventParticipantPayment;
use Zakjakub\OswisCalendarBundle\Manager\EventParticipantManager;
use Zakjakub\OswisCoreBundle\Provider\OswisCoreSettingsProvider;
use function in_array;

final class EventParticipantSubscriber implements EventSubscriberInterface
{

    /**
     * @var EntityManagerInterface
     */
    private $em;

    /**
     * @var MailerInterface
     */
    private $mailer;

    /**
     * @var LoggerInterface
     */
    private $logger;

    private $oswisCoreSettings;

    /**
     * @var UserPasswordEncoderInterface
     */
    private $encoder;

    /**
     * @param EntityManagerInterface       $em
     * @param MailerInterface              $mailer
     * @param LoggerInterface              $logger
     * @param OswisCoreSettingsProvider    $oswisCoreSettings
     * @param UserPasswordEncoderInterface $encoder
     */
    public function __construct(
        EntityManagerInterface $em,
        MailerInterface $mailer,
        LoggerInterface $logger,
        OswisCoreSettingsProvider $oswisCoreSettings,
        UserPasswordEncoderInterface $encoder
    ) {
        $this->em = $em;
        $this->mailer = $mailer;
        $this->logger = $logger;
        $this->oswisCoreSettings = $oswisCoreSettings;
        $this->encoder = $encoder;
    }

    /**
     * @return array
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::VIEW => [
                ['sendEmail', EventPriorities::POST_WRITE],
            ],
        ];
    }

    /**
     * @param ViewEvent $event
     *
     * @throws Exception
     */
    public function sendEmail(ViewEvent $event): void
    {
        $eventParticipant = $event->getControllerResult();
        $method = $event->getRequest()->getMethod();

        if (!$eventParticipant instanceof EventParticipantPayment
            || !in_array($method, [Request::METHOD_POST, Request::METHOD_PUT], true)) {
            return;
        }

        $eventParticipantManager = new EventParticipantManager($this->em, $this->mailer, $this->oswisCoreSettings, $this->logger);
        $eventParticipantManager->sendMail($eventParticipant, $this->encoder, Request::METHOD_POST === $method);
    }

}
