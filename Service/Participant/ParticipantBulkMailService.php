<?php

namespace OswisOrg\OswisCalendarBundle\Service\Participant;

use Doctrine\ORM\EntityManagerInterface;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Entity\ParticipantMail\ParticipantMailBulk;
use OswisOrg\OswisCoreBundle\Exceptions\OswisException;
use OswisOrg\OswisCoreBundle\Mail\Validation\MailProblem;
use OswisOrg\OswisCoreBundle\Mail\Validation\MailValidationResult;
use Psr\Log\LoggerInterface;

/**
 * Queue + drain of the ad-hoc bulk e-mail outbox ({@see ParticipantMailBulk}). Sending is synchronous
 * blocking SMTP, so a bulk is drained in capped batches (cron command / JS auto-drain), never in one
 * request. Kontrola, vykreslení a odeslání jednomu příjemci jdou stejnou cestou jako „Nová zpráva"
 * ({@see ParticipantManualMailer}); tahle služba přidává frontu, kurzor a ochranu proti duplicitě.
 */
class ParticipantBulkMailService
{
    public function __construct(
        protected EntityManagerInterface $em,
        protected ParticipantManualMailer $mailer,
        protected LoggerInterface $logger,
    ) {
    }

    /**
     * Kontrola hromadné zprávy proti VŠEM příjemcům (spec 2026-09-13 §3.5).
     *
     * @param array<int> $participantIds
     */
    public function validate(ParticipantManualMail $mail, array $participantIds): MailValidationResult
    {
        return $this->mailer->validate($mail, $this->participants($participantIds));
    }

    /**
     * Create a queued bulk (snapshot of recipient IDs). Sends nothing. Kontrolu dělá volající
     * ({@see validate()}) a výsledek předá sem — 285 příjemců se tak nekontroluje dvakrát; zprávu
     * s chybou zařadit nejde.
     *
     * @param array<int> $participantIds
     *
     * @throws OswisException když kontrola našla chyby
     */
    public function queue(ParticipantManualMail $mail, array $participantIds, MailValidationResult $validation): ParticipantMailBulk
    {
        if ($validation->hasErrors()) {
            throw new OswisException(implode(' | ', array_map(static fn (MailProblem $p): string => $p->message, $validation->errors())));
        }
        $bulk = new ParticipantMailBulk($mail->subject, $mail->body, $participantIds, $mail->adminName, $mail->templateSlug);
        $this->em->persist($bulk);
        $this->em->flush();

        return $bulk;
    }

    /**
     * @param array<int> $participantIds
     *
     * @return list<Participant>
     */
    private function participants(array $participantIds): array
    {
        $participants = [];
        foreach ($participantIds as $participantId) {
            $participant = $this->em->find(Participant::class, (int) $participantId);
            if ($participant instanceof Participant) {
                $participants[] = $participant;
            }
        }

        return $participants;
    }

    /**
     * Drain up to $batchSize recipients of $bulk, starting from its cursor. Idempotent + resumable
     * (cursor advanced per recipient → a crash re-sends at most one). Marks the bulk done when the
     * cursor reaches the end.
     *
     * Concurrency: the status-page JS auto-drain and the cron command (and two admin tabs) can call
     * this for the SAME bulk at the same time — two drains reading the same cursor would send the
     * same slice twice (duplicate mail to real recipients). A per-bulk MariaDB advisory lock
     * (GET_LOCK, non-blocking, connection-scoped, auto-released on disconnect) serializes drains
     * without holding a DB transaction across SMTP sends. A caller that does not get the lock
     * returns immediately with busy=true and NO progress (the JS backs off and re-polls; the cron
     * breaks on zero progress and resumes next tick). The entity is refresh()ed AFTER acquiring the
     * lock — the caller may hold a cursor that another drain has meanwhile advanced.
     *
     * @return array{sent: int, failed: int, processed: int, total: int, done: bool, busy: bool}
     */
    public function drainBatch(ParticipantMailBulk $bulk, int $batchSize = 15): array
    {
        if ($bulk->isDone()) {
            return $this->progress($bulk, 0, 0);
        }
        $lockName = sprintf('oswis_bulk_drain_%d', $bulk->getId() ?? 0);
        $connection = $this->em->getConnection();
        $acquired = $connection->fetchOne('SELECT GET_LOCK(?, 0)', [$lockName]);
        if (!is_numeric($acquired) || 1 !== (int) $acquired) {
            $this->logger->info(sprintf('Bulk #%d: drain already running elsewhere, skipping.', $bulk->getId() ?? 0));

            return $this->progress($bulk, 0, 0, busy: true);
        }
        try {
            // Fresh cursor: our entity may predate a drain that just finished on another connection.
            $this->em->refresh($bulk);
            if ($bulk->isDone()) {
                return $this->progress($bulk, 0, 0);
            }
            if (ParticipantMailBulk::STATUS_SENDING !== $bulk->getStatus()) {
                $bulk->setStatus(ParticipantMailBulk::STATUS_SENDING);
                $this->em->flush();
            }
            $ids = $bulk->getParticipantIds();
            $start = $bulk->getProcessedCount();
            $slice = array_slice($ids, $start, max(1, $batchSize));
            $sent = 0;
            $failed = 0;

            $mail = ParticipantManualMail::fromBulk($bulk);
            foreach ($slice as $position => $participantId) {
                $participant = $this->em->find(Participant::class, $participantId);
                $delivery = $participant instanceof Participant
                    ? $this->sendToParticipant($bulk, $mail, $participant)
                    : ['sent' => 0, 'errors' => ['přihláška neexistuje']];
                if ($delivery['sent'] > 0) {
                    $bulk->recordSent();
                    ++$sent;
                } else {
                    $bulk->recordFailed(sprintf('#%d: %s', (int) $participantId, $delivery['errors'][0] ?? 'nedoručeno'));
                    ++$failed;
                }
                $bulk->setProcessedCount($start + (int) $position + 1);
                try {
                    $this->em->flush();
                } catch (\Throwable $cursorError) {
                    // Kurzor se nezapsal — typicky proto, že se EntityManager zavřel dřívější
                    // chybou. Dávku ukončíme hned: bez tohohle by výjimka probublala ven,
                    // drain by skončil neúspěchem a další tick by začal na STARÉM kurzoru.
                    // Duplicitní odeslání hlídá test výš, tady jde o to skončit čistě
                    // a nechat problém vidět v logu.
                    $this->logger->critical(sprintf(
                        'Bulk #%d: zápis kurzoru na pozici %d selhal (%s) — dávka ukončena, '
                        .'zbytek se doručí až po nápravě.',
                        $bulk->getId() ?? 0,
                        $start + (int) $position + 1,
                        $cursorError->getMessage(),
                    ));

                    break;
                }
            }

            if ($bulk->getProcessedCount() >= $bulk->getTotalCount()) {
                $bulk->setStatus(ParticipantMailBulk::STATUS_DONE);
                $this->em->flush();
            }

            return $this->progress($bulk, $sent, $failed);
        } finally {
            $connection->executeQuery('SELECT RELEASE_LOCK(?)', [$lockName]);
        }
    }

    /**
     * Zpráva jedné přihlášce (všem jejím adresám) přes {@see ParticipantManualMailer::send()}.
     * Chyba vykreslení u jednoho příjemce = ten nedostane nic (zapíše se do selhání), zbytek běží dál.
     *
     * @return array{sent: int, errors: list<string>}
     */
    private function sendToParticipant(ParticipantMailBulk $bulk, ParticipantManualMail $mail, Participant $participant): array
    {
        $type = sprintf('ad-hoc-bulk-%d', $bulk->getId() ?? 0);

        // Pojistka „nikdy dvakrát". Kurzor `processedCount` se zapisuje AŽ PO odeslání, takže
        // kdyby ten zápis selhal (zavřený EntityManager), příští drain by týž slice poslal
        // znovu. Přesně tak 21. 8. 2026 dostalo 17 lidí dvakrát potvrzení platby
        // ({@see ParticipantPaymentService::sendPendingConfirmations()}). Tenhle test se ptá
        // DAT, ne proměnné v paměti: existuje-li už odeslaný mail tohoto bulku, druhý nepošleme.
        // Počítá se jako doručené, aby kurzor postoupil a dávka se nezasekla.
        // I nedokončený pokus (stav „odesílá se") se počítá: nevíme, jestli zpráva odešla, a druhý
        // pokus by ji mohl doručit dvakrát. Kurzor dávky se stejně posouvá, takže se o nic nepřijde.
        if ($participant->hasEMailOfType($type, onlyDelivered: false)) {
            $this->logger->info(sprintf(
                'Bulk #%d → participant #%d: e-mail už odeslán dřív, přeskočeno (ochrana proti duplicitě).',
                $bulk->getId() ?? 0,
                $participant->getId() ?? 0,
            ));

            return ['sent' => 1, 'errors' => []];
        }
        $delivery = $this->mailer->send($mail, $participant, $type, $bulk);
        foreach ($delivery['errors'] as $error) {
            $this->logger->error(sprintf('Bulk #%d → participant #%d: %s', $bulk->getId() ?? 0, $participant->getId() ?? 0, $error));
        }

        return $delivery;
    }

    /**
     * @return array{sent: int, failed: int, processed: int, total: int, done: bool, busy: bool}
     */
    private function progress(ParticipantMailBulk $bulk, int $sent, int $failed, bool $busy = false): array
    {
        return [
            'sent'      => $sent,
            'failed'    => $failed,
            'processed' => $bulk->getProcessedCount(),
            'total'     => $bulk->getTotalCount(),
            'done'      => $bulk->isDone(),
            'busy'      => $busy,
        ];
    }
}
