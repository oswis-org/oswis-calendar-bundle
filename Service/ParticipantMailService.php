<?php
/**
 * @noinspection PhpUnused
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantToken;
use OswisOrg\OswisCalendarBundle\Entity\ParticipantEMail\ParticipantEMail;
use OswisOrg\OswisCalendarBundle\Entity\ParticipantEMail\ParticipantEMailCategory;
use OswisOrg\OswisCalendarBundle\Entity\ParticipantEMail\ParticipantEMailGroup;
use OswisOrg\OswisCalendarBundle\Repository\ParticipantEMailCategoryRepository;
use OswisOrg\OswisCalendarBundle\Repository\ParticipantEMailGroupRepository;
use OswisOrg\OswisCoreBundle\Entity\NonPersistent\Nameable;
use OswisOrg\OswisCoreBundle\Exceptions\InvalidTypeException;
use OswisOrg\OswisCoreBundle\Exceptions\NotFoundException;
use OswisOrg\OswisCoreBundle\Exceptions\NotImplementedException;
use OswisOrg\OswisCoreBundle\Exceptions\OswisException;
use OswisOrg\OswisCoreBundle\Interfaces\EMail\EMailCategoryInterface;
use OswisOrg\OswisCoreBundle\Service\AbstractMailService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Exception\LogicException as MimeLogicException;
use Twig\Environment;

class ParticipantMailService extends AbstractMailService
{
    public const TYPE_CONFIRMATION = AppUserService::PASSWORD_CHANGE;
    public const TYPE_DELETE_REQUEST = AppUserService::PASSWORD_CHANGE_REQUEST;
    public const TYPE_DELETE = AppUserService::ACTIVATION;

    public const ALLOWED_TYPES = [
        self::TYPE_CONFIRMATION,
        self::TYPE_DELETE_REQUEST,
        self::TYPE_DELETE,
    ];

    protected ParticipantEMailGroupRepository $groupRepository;

    protected ParticipantEMailCategoryRepository $categoryRepository;

    protected Environment $twig;

    public function __construct(
        EntityManagerInterface $em,
        LoggerInterface $logger,
        MailerInterface $mailer,
        ParticipantEMailGroupRepository $groupRepository,
        ParticipantEMailCategoryRepository $categoryRepository,
        Environment $twig
    ) {
        parent::__construct($em, $logger, $mailer);
        $this->groupRepository = $groupRepository;
        $this->categoryRepository = $categoryRepository;
        $this->twig = $twig;
    }

    /**
     * @param Participant           $participant
     * @param string                $type
     * @param ParticipantToken|null $participantToken
     *
     * @throws InvalidTypeException
     * @throws MimeLogicException
     * @throws NotFoundException
     * @throws NotImplementedException
     * @throws OswisException
     * @throws TransportExceptionInterface
     */
    public function sendAppUserEMail(Participant $participant, string $type, ?ParticipantToken $participantToken = null): void
    {
        $isIS = false;
        if (null !== $participantToken && !$participantToken->isParticipant($participant)) {
            throw new OswisException('Token není kompatibilní s účastníkem.');
        }
        if (null === ($category = $this->getCategoryByType($type))) {
            throw new NotImplementedException($type, 'u uživatelských účtů');
        }
        if (null === ($group = $this->getGroup($participant, $category)) || null === ($twigTemplate = $group->getTwigTemplate())) {
            throw new NotFoundException('Šablona e-mailu nebyla nalezena.');
        }
        $title = $twigTemplate->getName() ?? 'Změna u uživatelského účtu';
        $appUserEMail = new ParticipantEMail($participant, new Nameable($title), $participant->getEmail(), $type, $participantToken);
        $data = [
            'appUser'      => $participant,
            'category'     => $category,
            'type'         => $type,
            'appUserToken' => $participantToken,
            'isIS'         => $isIS,
        ];
        $this->em->persist($appUserEMail);
        try {
            $this->sendEMail(
                $appUserEMail,
                $twigTemplate->getTemplateName() ?? '@OswisOrgOswisCore/e-mail/pages/app-user.html.twig',
                $data,
                ''.$participant->getName()
            );
        } catch (TransportExceptionInterface|MimeLogicException $exception) {
            $this->logger->error('App user e-mail exception: '.$exception->getMessage());
            $appUserEMail->setInternalNote($exception->getMessage());
            $this->em->flush();
            throw $exception;
        }
        $this->em->flush();
    }

    public function getCategoryByType(?string $type): ?ParticipantEMailCategory
    {
        return $this->categoryRepository->findByType($type);
    }

    public function getGroup(Participant $participant, EMailCategoryInterface $category): ?ParticipantEMailGroup
    {
        return $this->groupRepository->findByUser($participant, $category);
    }

}
