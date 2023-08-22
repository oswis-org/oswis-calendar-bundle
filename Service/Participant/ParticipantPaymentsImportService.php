<?php
/**
 * @noinspection MethodShouldBeFinalInspection
 */

namespace OswisOrg\OswisCalendarBundle\Service\Participant;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use OswisOrg\OswisCalendarBundle\Entity\NonPersistent\CsvPaymentImportSettings;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantPayment;
use OswisOrg\OswisCalendarBundle\Entity\Participant\ParticipantPaymentsImport;
use OswisOrg\OswisCalendarBundle\Repository\Participant\ParticipantRepository;
use OswisOrg\OswisCoreBundle\Exceptions\OswisException;
use Psr\Log\LoggerInterface;

class ParticipantPaymentsImportService
{
    public function __construct(
        protected EntityManagerInterface $em,
        protected LoggerInterface        $logger,
        protected ParticipantService     $participantService,
        protected ParticipantPaymentService $paymentService,
    )
    {
    }

    public function processImport(
        ParticipantPaymentsImport $paymentsImport,
        ?CsvPaymentImportSettings $importSettings = null
    ): void
    {
        $importedPayments = new ArrayCollection();
        $this->em->persist($paymentsImport);
        $this->em->flush();
        $payments = $paymentsImport->extractPayments($importSettings ?? new CsvPaymentImportSettings());
        foreach ($payments as $payment) {
            if (!($payment instanceof ParticipantPayment)) {
                continue;
            }
            if (null === $payment->getImport()) {
                $payment->setImport($paymentsImport);
            }
            $participant = $this->getParticipantByPayment($payment);
            $importedPayments->add($this->paymentService->create($payment, true, $participant));
        }
        try {
            $this->em->flush();
            $this->paymentService->sendPaymentsReport($importedPayments);
            $this->logger->info("OK: Payments report sent! ");
        } catch (OswisException $e) {
            $this->logger->error("ERROR: Payments report not sent! " . $e->getMessage());
            $this->logger->error("ERROR: Payments report not sent! " . $e->getTraceAsString());
        }
    }

    public function getParticipantByPayment(ParticipantPayment $payment, bool $isSecondTry = false): ?Participant
    {
        $secondTryString = $isSecondTry ? " (second try, included deleted participants)" : '';
        $value = $payment->getNumericValue();
        if (empty($vs = $payment->getVariableSymbol())) {
            $this->logger->warning("Participant NOT found for payment without VS and with value '$value'.");

            return null;
        }
        $participants = $this->participantService->getParticipants([
            ParticipantRepository::CRITERIA_VARIABLE_SYMBOL => $payment->getVariableSymbol(),
            ParticipantRepository::CRITERIA_INCLUDE_DELETED => $isSecondTry,
        ]);
        $participantsCount = $participants->count();
        $this->logger->info(
            "Found $participantsCount participants for payment with VS '$vs' and value '$value'$secondTryString."
        );
        $participantsArray = $participants->toArray();
        usort(
            $participantsArray,
            static fn(mixed $p1, mixed $p2) => self::compareParticipantsByPayment($value ?? 0, $p1, $p2),
        );
        $participants = new ArrayCollection($participantsArray);
        $participant = $participants->first() instanceof Participant ? $participants->first() : null;
        if (null === $participant && !$isSecondTry) {
            $participant = $this->getParticipantByPayment($payment, true);
        }
        if (null === $participant) {
            $this->logger->warning(
                "Participant NOT found for payment with VS '$vs' and value '$value'$secondTryString."
            );

            return null;
        }
        $participantString = $participant->getId() . ' ' . $participant->getName();
        $this->logger->info("Found participant '$participantString' for payment with VS '$vs' and value '$value'.");

        return $participant;
    }

    public static function compareParticipantsByPayment(int $value, mixed $participant1, mixed $participant2): int
    {
        /** @var Participant $participant1 */
        /** @var Participant $participant2 */
        return $participant1->differenceFromPayment($value) <=> $participant2->differenceFromPayment($value);
    }
}
