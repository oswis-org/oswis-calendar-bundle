<?php
/**
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use OswisOrg\OswisAddressBookBundle\Entity\AbstractClass\AbstractContact;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantToken;
use OswisOrg\OswisCalendarBundle\Entity\ParticipantMail\ParticipantMail;
use OswisOrg\OswisCalendarBundle\Entity\ParticipantMail\ParticipantMailCategory;
use OswisOrg\OswisCalendarBundle\Entity\ParticipantMail\ParticipantMailGroup;
use OswisOrg\OswisCalendarBundle\Repository\ParticipantMailCategoryRepository;
use OswisOrg\OswisCalendarBundle\Repository\ParticipantMailGroupRepository;
use OswisOrg\OswisCalendarBundle\Repository\ParticipantMailRepository;
use OswisOrg\OswisCoreBundle\Entity\AppUser\AppUser;
use OswisOrg\OswisCoreBundle\Exceptions\InvalidTypeException;
use OswisOrg\OswisCoreBundle\Exceptions\NotFoundException;
use OswisOrg\OswisCoreBundle\Exceptions\NotImplementedException;
use OswisOrg\OswisCoreBundle\Exceptions\OswisException;
use OswisOrg\OswisCoreBundle\Interfaces\Mail\MailCategoryInterface;
use OswisOrg\OswisCoreBundle\Service\AbstractMailService;
use OswisOrg\OswisCoreBundle\Service\MailService;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;

class ParticipantMailService
{
    protected EntityManagerInterface $em;

    protected MailService $mailService;

    protected ParticipantMailRepository $participantMailRepository;

    protected ParticipantMailGroupRepository $groupRepository;

    protected ParticipantMailCategoryRepository $categoryRepository;

    public function __construct(
        EntityManagerInterface $em,
        MailService $mailService,
        ParticipantMailGroupRepository $groupRepository,
        ParticipantMailCategoryRepository $categoryRepository,
        ParticipantMailRepository $participantMailRepository
    ) {
        $this->em = $em;
        $this->mailService = $mailService;
        $this->groupRepository = $groupRepository;
        $this->categoryRepository = $categoryRepository;
        $this->participantMailRepository = $participantMailRepository;
    }

    /**
     * @param Participant $participant
     *
     * @throws OswisException
     */
    public function sendActivated(Participant $participant): void
    {
        $sent = 0;
        foreach ($participant->getContactPersons(true) as $contactPerson) {
            if (!($contactPerson instanceof AbstractContact)) {
                continue;
            }
            try {
                $this->sendUserMail($participant, $contactPerson->getAppUser(), ParticipantMail::TYPE_ACTIVATION);
            } catch (OswisException|NotFoundException|NotImplementedException|InvalidTypeException $e) {
            }
            $sent++;
        }
        if (1 > $sent) {
            throw new OswisException("Nepodařilo se odeslat potvrzovací e-mail.");
        }
    }

    /**
     * @param Participant           $participant
     * @param AppUser               $appUser
     * @param string                $type
     * @param ParticipantToken|null $participantToken
     *
     * @throws NotFoundException
     * @throws NotImplementedException
     * @throws OswisException
     * @throws InvalidTypeException
     */
    public function sendUserMail(Participant $participant, AppUser $appUser, string $type, ?ParticipantToken $participantToken = null): void
    {
        $isIS = false;
        if (null !== $participantToken && (!$participantToken->isParticipant($participant) || !$participantToken->isAppUser($appUser))) {
            throw new OswisException('Token není kompatibilní s přihláškou.');
        }
        if (null === ($mailCategory = $this->getMailCategoryByType($type))) {
            throw new NotImplementedException($type, 'u e-mailů k přihláškám');
        }
        if (null === ($group = $this->getMailGroup($participant, $mailCategory)) || null === ($twigTemplate = $group->getTwigTemplate())) {
            throw new NotFoundException('Skupina nebo šablona e-mailů nebyla nalezena.');
        }
        $appUser = ($participantToken ? $participantToken->getAppUser() : null) ?? $participant->getAppUser();
        if (null === $appUser) {
            throw new NotFoundException('Uživatel nebyl nalezen.');
        }
        $title = $twigTemplate->getName() ?? 'Přihláška na akci';
        $participantMail = new ParticipantMail($participant, $appUser, $title, $type, $participantToken);
        $participantMail->setPastMails($this->participantMailRepository->findByAppUser($appUser));
        $data = [
            'participant'      => $participant,
            'category'         => $mailCategory,
            'type'             => $type,
            'participantToken' => $participantToken,
            'isIS'             => $isIS,
        ];
        $templatedEmail = $participantMail->getTemplatedEmail();
        if (ParticipantMail::TYPE_ACTIVATION === $type) {
            $data = $this->embedQrPayments($templatedEmail, $participant, $data);
        }
        $this->em->persist($participantMail);
        $templateName = $twigTemplate->getTemplateName() ?? '@OswisOrgOswisCalendar/e-mail/pages/participant-universal.html.twig';
        $this->mailService->sendEMail($participantMail, $templateName, $data);
        $this->em->flush();
    }

    public function getMailCategoryByType(?string $type): ?ParticipantMailCategory
    {
        return $this->categoryRepository->findByType($type);
    }

    public function getMailGroup(Participant $participant, MailCategoryInterface $category): ?ParticipantMailGroup
    {
        return $this->groupRepository->findByUser($participant, $category);
    }

    public function embedQrPayments(TemplatedEmail $templatedEmail, Participant $participant, array $mailData): array
    {
        $participantId = $participant->getId();
        $participantContactSlug = $participant->getContact() ? $participant->getContact()->getSlug() : null;
        $eventId = $participant->getEvent() ? $participant->getEvent()->getId() : null;
        $qrComment = "$participantContactSlug, ID $participantId, akce $eventId";
        foreach (['depositQr' => ['deposit' => true, 'rest' => false], 'restQr' => ['deposit' => false, 'rest' => true]] as $key => $opts) {
            if ($qrPng = $participant->getQrPng($opts['deposit'], $opts['rest'], $qrComment)) {
                $templatedEmail->embed($qrPng, $key, 'image/png');
                $mailData[$key] = "cid:$key";
            }
        }

        return $mailData;
    }
}
