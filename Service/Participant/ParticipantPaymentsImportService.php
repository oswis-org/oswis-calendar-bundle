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
use OswisOrg\OswisCoreBundle\Exceptions\OswisException;
use Psr\Log\LoggerInterface;

class ParticipantPaymentsImportService
{
    public function __construct(
        protected EntityManagerInterface $em,
        protected LoggerInterface $logger,
        protected ParticipantService $participantService,
        protected ParticipantPaymentService $paymentService,
        protected PaymentMatchingService $matchingService,
    ) {
    }

    public function processImport(
        ParticipantPaymentsImport $paymentsImport,
        ?CsvPaymentImportSettings $importSettings = null
    ): void
    {
        $importedPayments = new ArrayCollection();
        $this->em->persist($paymentsImport);
        $this->em->flush();

        // ⚠️ IDEMPOTENCE: tentýž import se nesmí zpracovat dvakrát.
        //
        // 16. 8. 2026 se to stalo a vzniklo 102 fiktivních plateb za 349 735 Kč u 93 lidí
        // (docs/OSWIS_1_INCIDENT_PAYMENT_DUPLICATES_2026-08-16.md). Dvojí průchod je doložený:
        // jeden `import_id`, dva souvislé bloky `id` vzdálené o velikost dávky.
        //
        // Spustit jeden import dvakrát umí hned tři cesty a žádná to nehlídala:
        //   1. `processImport()` má DVA volající — web-admin kontroler i API subscriber,
        //   2. importní formulář nemá pojistku proti dvojímu odeslání (iOS Safari posílá 3–5×),
        //   3. cokoliv, co request zopakuje (retry proxy, F5 na POST).
        // Proto se zámek dává SEM, na jediné místo, kterým všechny cesty procházejí.
        //
        // Dřív to maskoval 504: první průchod posílal potvrzení synchronně, trval minuty
        // a request umřel dřív, než druhý začal. Po zrychlení (potvrzení jdou cronem) doběhnou oba.
        if (null !== $paymentsImport->getProcessedAt()) {
            $this->logger->warning(sprintf(
                'CSV import #%s už byl zpracován (%s) — druhé volání ignorováno.',
                (string) $paymentsImport->getId(),
                $paymentsImport->getProcessedAt()->format('Y-m-d H:i:s'),
            ));

            return;
        }
        $paymentsImport->setProcessedAt(new \DateTime());
        $this->em->flush();

        $payments = $paymentsImport->extractPayments($importSettings ?? new CsvPaymentImportSettings());
        foreach ($payments as $payment) {
            if (!($payment instanceof ParticipantPayment)) {
                continue;
            }
            if (null === $payment->getImport()) {
                $payment->setImport($paymentsImport);
            }
            // errorMessage is set by makePaymentFromCsv (unknown currency, unreadable date) but was
            // never read anywhere — the row imported and the problem stayed invisible. Surface it.
            $paymentError = $payment->getErrorMessage();
            if (null !== $paymentError && '' !== $paymentError) {
                $this->logger->warning(sprintf(
                    'CSV import: vadný řádek platby (VS %s, částka %s): %s',
                    (string) $payment->getVariableSymbol(),
                    (string) $payment->getNumericValue(),
                    $paymentError,
                ));
            }
            $participant = $this->getParticipantByPayment($payment);
            // `false` = NEPOSÍLAT potvrzení tady. Dřív se posílalo synchronně platbu po platbě
            // uvnitř HTTP requestu — u reálného importu (až 173 plateb, ~99 % s účastníkem) to
            // znamenalo ~170 sekvenčních SMTP odeslání a request padal na 504. Potvrzení teď
            // rozešle cron ({@see ParticipantPaymentService::sendPendingConfirmations()}),
            // který běží à 5 minut; zámek zůstává týž (`confirmedByMailAt`), takže opakovaný
            // import je pořád bezpečný a nic se neodešle dvakrát.
            $created = $this->paymentService->create($payment, false, $participant);
            // create() returns null only when it caught an error (e.g. the confirmation mail threw after
            // the payment was already flushed). Never add null to the report collection — a null row would
            // break the report Twig and silently drop the entry; log the discrepancy instead.
            // (audit 2026-06-25, A2-Bug3)
            if ($created instanceof ParticipantPayment) {
                $importedPayments->add($created);
            } else {
                $this->logger->error(sprintf(
                    'CSV import: payment create() returned null — excluded from report (VS %s, value %s).',
                    (string) $payment->getVariableSymbol(),
                    (string) $payment->getNumericValue(),
                ));
            }
        }
        try {
            $this->em->flush();
            $this->paymentService->sendPaymentsReport($importedPayments);
            $this->logger->info("OK: Payments report sent! ");
        } catch (OswisException $e) {
            $this->logger->error("ERROR: Payments report not sent! ".$e->getMessage());
            $this->logger->error("ERROR: Payments report not sent! ".$e->getTraceAsString());
        }
    }

    /**
     * Look up the best participant for a payment via PaymentMatchingService.
     * Only the unambiguous top candidate is auto-applied; ambiguous payments
     * are left orphaned (participant=null) for manual matching in the admin UI.
     */
    public function getParticipantByPayment(ParticipantPayment $payment): ?Participant
    {
        $vs = (string) $payment->getVariableSymbol();
        $value = $payment->getNumericValue();
        $note = (string) $payment->getNote();
        $internalNote = (string) $payment->getInternalNote();
        if ('' === trim($vs) && '' === trim($note) && '' === trim($internalNote)) {
            $this->logger->warning("Payment has no VS / note / internalNote — cannot auto-match (value '$value').");

            return null;
        }

        $candidate = $this->matchingService->pickUnambiguous($payment);
        if (null === $candidate) {
            $this->logger->info(
                "Payment VS '$vs' value '$value': no unambiguous candidate; left for manual matching."
            );

            return null;
        }

        $participant = $candidate->participant;
        $this->logger->info(sprintf(
            "Auto-matched payment VS '%s' value '%s' -> participant #%s '%s' (score=%d, reasons=[%s]).",
            $vs,
            (string) $value,
            (string) $participant->getId(),
            (string) $participant->getName(),
            $candidate->score,
            implode(', ', $candidate->reasons),
        ));

        return $participant;
    }
}
