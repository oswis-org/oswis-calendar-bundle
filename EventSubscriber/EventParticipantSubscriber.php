<?php

namespace Zakjakub\OswisCalendarBundle\EventSubscriber;

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
use Twig\Environment;
use Zakjakub\OswisCalendarBundle\Entity\EventParticipant\EventParticipant;
use Zakjakub\OswisCalendarBundle\Entity\EventParticipant\EventParticipantPayment;
use Zakjakub\OswisCalendarBundle\Exceptions\OswisEventParticipantNotFoundException;
use Zakjakub\OswisCalendarBundle\Manager\EventParticipantManager;
use Zakjakub\OswisCoreBundle\Provider\OswisCoreSettingsProvider;
use function assert;
use function in_array;

/**
 * Class EventParticipantSubscriber
 * @package Zakjakub\OswisCalendarBundle\EventSubscriber
 */
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

    /**
     * @var OswisCoreSettingsProvider
     */
    private $oswisCoreSettings;

    /**
     * @var UserPasswordEncoderInterface
     */
    private $encoder;

    private $templating;

    /**
     * @param EntityManagerInterface       $em
     * @param MailerInterface              $mailer
     * @param LoggerInterface              $logger
     * @param OswisCoreSettingsProvider    $oswisCoreSettings
     * @param UserPasswordEncoderInterface $encoder
     * @param Environment                  $templating
     */
    public function __construct(
        EntityManagerInterface $em,
        MailerInterface $mailer,
        LoggerInterface $logger,
        OswisCoreSettingsProvider $oswisCoreSettings,
        UserPasswordEncoderInterface $encoder,
        Environment $templating
    ) {
        $this->em = $em;
        $this->mailer = $mailer;
        $this->logger = $logger;
        $this->oswisCoreSettings = $oswisCoreSettings;
        $this->encoder = $encoder;
        $this->templating = $templating;
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

    /** @noinspection PhpUnused */
    /**
     * @param ViewEvent $event
     *
     * @throws Exception
     */
    public function postWrite(ViewEvent $event): void
    {
        $eventParticipant = $event->getControllerResult();
        $method = $event->getRequest()->getMethod();

        if (!$eventParticipant instanceof EventParticipantPayment || !in_array($method, [Request::METHOD_POST, Request::METHOD_PUT], true)) {
            return;
        }

        $eventParticipantRepository = $this->em->getRepository(EventParticipant::class);
        $eventParticipant = $eventParticipantRepository->findOneBy(['id' => $eventParticipant->getId()]);
        assert($eventParticipant instanceof EventParticipant);

        if ($eventParticipant) {
            $eventParticipantManager = new EventParticipantManager(
                $this->em,
                $this->mailer,
                $this->oswisCoreSettings,
                $this->logger,
                $this->templating
            );
            $eventParticipantManager->sendMail($eventParticipant, $this->encoder, Request::METHOD_POST === $method);
        } else {
            throw new OswisEventParticipantNotFoundException();
        }
    }

    /** @noinspection PhpUnused */
    /**
     * @param ViewEvent $event
     *
     * @throws Exception
     */
    public function postValidate(ViewEvent $event): void
    {
        $newEventParticipant = $event->getControllerResult();
        $method = $event->getRequest()->getMethod();

        if (!$newEventParticipant instanceof EventParticipantPayment || $method !== Request::METHOD_PUT) {
            return;
        }

        $eventParticipant = $this->getExistingEventParticipant($newEventParticipant);

        if ($eventParticipant) {
            $newEventParticipant->setEMailConfirmationDateTime(null);
            $eventParticipant->setEMailConfirmationDateTime(null);
        } else {
            throw new OswisEventParticipantNotFoundException();
        }
    }

    private function getExistingEventParticipant(EventParticipant $newEventParticipant): ?EventParticipant
    {
        $eventParticipantRepository = $this->em->getRepository(EventParticipant::class);
        $eventParticipant = $eventParticipantRepository->findOneBy(['id' => $newEventParticipant->getId()]);
        assert($eventParticipant instanceof EventParticipant);

        return $eventParticipant;
    }
}
