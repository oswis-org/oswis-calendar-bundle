<?php
/**
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use OswisOrg\OswisAddressBookBundle\Entity\AbstractClass\AbstractContact;
use OswisOrg\OswisAddressBookBundle\Entity\Person;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantPayment;
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
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;

class ParticipantMailService
{
    protected EntityManagerInterface $em;

    protected MailService $mailService;

    protected ParticipantMailRepository $participantMailRepository;

    protected ParticipantMailGroupRepository $groupRepository;

    protected ParticipantMailCategoryRepository $categoryRepository;

    protected LoggerInterface $logger;

    public function __construct(
        EntityManagerInterface $em,
        MailService $mailService,
        ParticipantMailGroupRepository $groupRepository,
        ParticipantMailCategoryRepository $categoryRepository,
        ParticipantMailRepository $participantMailRepository,
        LoggerInterface $logger
    ) {
        $this->em = $em;
        $this->mailService = $mailService;
        $this->groupRepository = $groupRepository;
        $this->categoryRepository = $categoryRepository;
        $this->participantMailRepository = $participantMailRepository;
        $this->logger = $logger;
    }

    /**
     * @param Participant $participant
     *
     * @throws OswisException
     */
    public function sendSummary(Participant $participant): void
    {
        $sent = 0;
        foreach ($participant->getContactPersons(true) as $contactPerson) {
            if (!($contactPerson instanceof AbstractContact)) {
                continue;
            }
            try {
                $this->sendSummaryToUser($participant, $contactPerson->getAppUser(), ParticipantMail::TYPE_SUMMARY);
                $sent++;
            } catch (OswisException|NotFoundException|NotImplementedException|InvalidTypeException $exception) {
                $participantId = $participant->getId();
                $userId = $contactPerson->getAppUser() ? $contactPerson->getAppUser()->getId() : null;
                $message = $exception->getMessage();
                $this->logger->error("ERROR: Not sent summary for participant '$participantId' to user '$userId' ($message).");
            }
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
    public function sendSummaryToUser(Participant $participant, AppUser $appUser, string $type, ?ParticipantToken $participantToken = null): void
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
        if ($participant->isDeleted()) {
            $title = "Shrnutí smazané přihlášky";
        }
        $participantMail = new ParticipantMail($participant, $appUser, $title, $type, $participantToken);
        $participantMail->setPastMails($this->participantMailRepository->findByAppUser($appUser));
        $contact = $participant->getContact();
        $data = [
            'participant'      => $participant,
            'appUser'          => $appUser,
            'contact'          => $contact,
            'salutationName'   => $contact instanceof Person ? $contact->getSalutationName() : $contact->getName(),
            'category'         => $mailCategory,
            'type'             => $type,
            'participantToken' => $participantToken,
            'isIS'             => $isIS,
        ];
        $templatedEmail = $participantMail->getTemplatedEmail();
        if (ParticipantMail::TYPE_SUMMARY === $type) {
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

    /**
     * @param ParticipantPayment $payment
     *
     * @throws OswisException
     */
    public function sendPaymentConfirmation(ParticipantPayment $payment): void
    {
        $sent = 0;
        $paymentId = $payment->getId();
        if (null === ($participant = $payment->getParticipant())) {
            $this->logger->warning("Not sending payment '$paymentId' confirmation because participant is not set.");

            return;
        }
        foreach ($participant->getContactPersons(true) as $contactPerson) {
            if (!($contactPerson instanceof AbstractContact)) {
                continue;
            }
            try {
                $this->sendPaymentConfirmationToUser($payment, $contactPerson->getAppUser());
                $sent++;
            } catch (NotFoundException|NotImplementedException|InvalidTypeException $exception) {
                $userId = $contactPerson->getAppUser() ? $contactPerson->getAppUser()->getId() : null;
                $message = $exception->getMessage();
                $this->logger->error("ERROR: Not sent confirmation of payment '$paymentId' to user '$userId' ($message).");
            }
        }
        if (1 > $sent) {
            throw new OswisException("Nepodařilo se odeslat potvrzovací e-mail o platbě účastníkovi.");
        }
    }

    /**
     * @param ParticipantPayment $payment
     * @param AppUser            $appUser
     *
     * @throws InvalidTypeException
     * @throws NotFoundException
     * @throws NotImplementedException
     */
    public function sendPaymentConfirmationToUser(ParticipantPayment $payment, AppUser $appUser): void
    {
        $participant = $payment->getParticipant();
        if (null === $participant) {
            return;
        }
        if (null === ($mailCategory = $this->getMailCategoryByType(ParticipantMail::TYPE_PAYMENT))) {
            throw new NotImplementedException(ParticipantMail::TYPE_PAYMENT, 'u e-mailů k přihláškám');
        }
        if (null === ($group = $this->getMailGroup($participant, $mailCategory)) || null === ($twigTemplate = $group->getTwigTemplate())) {
            $groupName = $group ? $group->getName() : null;
            $templateName = isset($twigTemplate) ? $twigTemplate->getName() : null;
            throw new NotFoundException("Skupina '$groupName' nebo šablona '$templateName' e-mailů nebyla nalezena.");
        }
        $title = $payment->getNumericValue() < 0 ? 'Vrácení/oprava platby' : 'Přijetí platby';
        $participantMail = new ParticipantMail($participant, $appUser, $title, ParticipantMail::TYPE_PAYMENT);
        $participantMail->setPastMails($this->participantMailRepository->findByAppUser($appUser));
        $contact = $participant->getContact();
        $data = [
            'payment'        => $payment,
            'participant'    => $participant,
            'appUser'        => $appUser,
            'contact'        => $contact,
            'salutationName' => $contact instanceof Person ? $contact->getSalutationName() : $contact->getName(),
            'category'       => $mailCategory,
            'type'           => ParticipantMail::TYPE_PAYMENT,
            'isIS'           => false,
        ];
        $this->em->persist($participantMail);
        $this->em->persist($payment);
        $templateName = $twigTemplate->getTemplateName() ?? '@OswisOrgOswisCalendar/e-mail/pages/participant-payment.html.twig';
        $this->mailService->sendEMail($participantMail, $templateName, $data);
        if ($participantMail->getSent() && !$payment->isConfirmedByMail()) {
            $payment->setConfirmedByMailAt($participantMail->getSent());
        }
        $this->em->flush();
    }
}
