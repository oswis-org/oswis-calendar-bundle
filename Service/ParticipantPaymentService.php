<?php
/**
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Service;

use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantPayment;
use OswisOrg\OswisCoreBundle\Exceptions\OswisException;
use OswisOrg\OswisCoreBundle\Provider\OswisCoreSettingsProvider;
use OswisOrg\OswisCoreBundle\Utils\EmailUtils;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;

class ParticipantPaymentService
{
    protected EntityManagerInterface $em;

    protected MailerInterface $mailer;

    protected LoggerInterface $logger;

    protected OswisCoreSettingsProvider $coreSettings;

    protected ParticipantMailService $participantMailService;

    public function __construct(
        EntityManagerInterface $em,
        MailerInterface $mailer,
        LoggerInterface $logger,
        OswisCoreSettingsProvider $oswisCoreSettings,
        ParticipantMailService $participantMailService
    ) {
        $this->em = $em;
        $this->mailer = $mailer;
        $this->logger = $logger;
        $this->coreSettings = $oswisCoreSettings;
        $this->participantMailService = $participantMailService;
    }

    public function create(ParticipantPayment $payment, bool $sendConfirmation = true): ?ParticipantPayment
    {
        $paymentsRepository = $this->em->getRepository(ParticipantPayment::class);
        try {
            if (!empty($externalId = $payment->getExternalId()) && !empty(
                $existing = $paymentsRepository->findBy(
                    ['externalId' => $externalId]
                )
                ) && $existing[0] instanceof ParticipantPayment) {
                $payment = $existing[0];
                $paymentId = $payment->getId();
                $this->logger->info("Found duplicity (id '$paymentId') of payment with external id '$externalId'.");
            }
            $this->em->persist($payment);
            $this->em->flush();
            $id = $payment->getId();
            $vs = $payment->getVariableSymbol();
            $value = $payment->getNumericValue();
            $participant = $payment->getParticipant();
            $this->logger->info("CREATE: Created (or updated) participant payment (by service): ID $id, VS $vs, value $value,- Kč.");
            if ($sendConfirmation && null !== $participant && !$payment->isConfirmedByMail()) {
                $this->participantMailService->sendPaymentConfirmation($payment);
                $this->logger->info("CREATE: Sent confirmation for participant payment (by service): ID $id, VS $vs, value $value,- Kč.");
            }

            return $payment;
        } catch (Exception $e) {
            $this->logger->notice('ERROR: Participant payment not created (by service): '.$e->getMessage());

            return null;
        }
    }

    /**
     * @param Collection $payments
     *
     * @return bool
     * @throws OswisException
     */
    final public function sendPaymentsReport(Collection $payments): bool
    {
        try {
            $email = new TemplatedEmail();
            $email->to($this->coreSettings->getArchiveMailerAddress())->subject(EmailUtils::mimeEnc('Report nových plateb'));
            $email->htmlTemplate('@OswisOrgOswisCalendar/e-mail/pages/participant-payments-report.html.twig');
            $email->context(['payments' => $payments]);
            $this->mailer->send($email);

            return true;
        } catch (TransportExceptionInterface $e) {
            throw new OswisException('Problém s odesláním reportu o CSV platbách. '.$e->getMessage());
        } catch (Exception $e) {
            throw new OswisException('Problém s vytvářením reportu o CSV platbách. '.$e->getMessage());
        }
    }
}
