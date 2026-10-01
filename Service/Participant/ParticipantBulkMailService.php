<?php

namespace OswisOrg\OswisCalendarBundle\Service\Participant;

use Doctrine\ORM\EntityManagerInterface;
use OswisOrg\OswisCalendarBundle\Entity\Participant\Participant;
use OswisOrg\OswisCalendarBundle\Entity\ParticipantMail\ParticipantMailBulk;
use OswisOrg\OswisCoreBundle\Exceptions\OswisException;
use OswisOrg\OswisCoreBundle\Mail\Validation\MailProblem;
use OswisOrg\OswisCoreBundle\Mail\Validation\MailValidationResult;
use OswisOrg\OswisCoreBundle\Mail\Quota\MailDailyQuota;
use Psr\Clock\ClockInterface;
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
        /** Denní limit hromadných (dávka 3.1); bez něj bez omezení. */
        protected ?MailDailyQuota $quota = null,
        /** Hodiny pro „odeslat po" (dávka 3.2); bez nich systémový čas. */
        protected ?ClockInterface $clock = null,
    ) {
    }

    private function now(): \DateTimeImmutable
    {
        return $this->clock?->now() ?? new \DateTimeImmutable();
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
    public function queue(ParticipantManualMail $mail, array $participantIds, MailValidationResult $validation, ?\DateTimeImmutable $sendAt = null): ParticipantMailBulk
    {
        if ($validation->hasErrors()) {
            throw new OswisException(implode(' | ', array_map(static fn (MailProblem $p): string => $p->message, $validation->errors())));
        }
        $bulk = new ParticipantMailBulk($mail->subject, $mail->body, $participantIds, $mail->adminName, $mail->templateSlug);
        // Nejdřív za 30 s (čas na zrušení), nebo ve zvolený čas (naplánované odeslání) — dávka 3.2.
        $nejdriv = $this->now()->modify(sprintf('+%d seconds', ParticipantMailBulk::ODKLAD_SEKUND));
        $bulk->setSendAfter(null !== $sendAt && $sendAt > $nejdriv ? $sendAt : $nejdriv);
        $this->em->persist($bulk);
        $this->em->flush();

        return $bulk;
    }

    /**
     * Kontrola před odesláním (spec §5.2, dávka 3.3): kolik zpráv odejde a kdo nedostane nic a proč — stejným
     * výběrem adres jako odeslání ({@see ParticipantMailContextFactory::recipientUsers()}), takže ukazuje skutečnost.
     *
     * @param array<int> $participantIds
     *
     * @return array{zprav: int, prihlasek: int, vypadnou: list<array{id: int, popis: string, duvod: string}>}
     */
    public function prehledPrijemcu(array $participantIds): array
    {
        $zprav = 0;
        $prihlasek = 0;
        $vypadnou = [];
        foreach ($participantIds as $participantId) {
            $participant = $this->em->find(Participant::class, (int) $participantId);
            if (!$participant instanceof Participant) {
                $vypadnou[] = ['id' => (int) $participantId, 'popis' => '#'.(int) $participantId, 'duvod' => 'přihláška neexistuje'];
                continue;
            }
            $popis = trim('#'.(int) $participant->getId().' '.($participant->getContact()?->getName() ?? ''));
            if (null !== $participant->getDeletedAt()) {
                $vypadnou[] = ['id' => (int) $participant->getId(), 'popis' => $popis, 'duvod' => 'přihláška je zrušená'];
                continue;
            }
            $adres = count(ParticipantMailContextFactory::recipientUsers($participant));
            if (0 === $adres) {
                $vypadnou[] = ['id' => (int) $participant->getId(), 'popis' => $popis, 'duvod' => 'nemá aktivovaný účet — není komu psát'];
                continue;
            }
            $zprav += $adres;
            ++$prihlasek;
        }

        return ['zprav' => $zprav, 'prihlasek' => $prihlasek, 'vypadnou' => $vypadnou];
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
     * Denní limit hromadných ({@see MailDailyQuota}): když je vyčerpaný, dávka skončí PŘED dalším příjemcem
     * (`limit: true`), kurzor zůstane a další den se pokračuje tam, kde se skončilo.
     *
     * Čas „odeslat po" (dávka 3.2): dokud neuplynul, nic se neodešle (`waiting: true`). Zrušení se kontroluje
     * před KAŽDÝM příjemcem přímo v databázi — „Zastavit" z administrace tak platí i uprostřed dávky.
     *
     * @return array{sent: int, failed: int, processed: int, total: int, done: bool, busy: bool, limit: bool, waiting: bool, cancelled: bool, sendAfter: ?string}
     */
    public function drainBatch(ParticipantMailBulk $bulk, int $batchSize = 15): array
    {
        if ($bulk->isFinished()) {
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
            if ($bulk->isFinished()) {
                return $this->progress($bulk, 0, 0);
            }
            if (!$bulk->isDue($this->now())) {
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
                // Zrušeno / zastaveno z administrace (i během téhle dávky) — dál nic.
                if (ParticipantMailBulk::STATUS_CANCELLED === $connection->fetchOne('SELECT status FROM calendar_participant_mail_bulk WHERE id = ?', [$bulk->getId()])) {
                    $this->em->refresh($bulk);
                    $this->logger->info(sprintf('Bulk #%d: zrušeno z administrace — zastaveno na pozici %d.', $bulk->getId() ?? 0, $bulk->getProcessedCount()));

                    return $this->progress($bulk, $sent, $failed);
                }
                if (null !== $this->quota && !$this->quota->bulkAllowed()) {
                    $this->logger->info(sprintf('Bulk #%d: denní limit hromadných (%d) vyčerpán — pokračuje zítra od pozice %d.', $bulk->getId() ?? 0, $this->quota->bulkLimit(), $bulk->getProcessedCount()));

                    return $this->progress($bulk, $sent, $failed, limit: true);
                }
                $participant = $this->em->find(Participant::class, $participantId);
                // Přihláška zrušená mezi zařazením a odesláním se přeskočí (spec §5.3 h) — není chyba, jen poznámka.
                if ($participant instanceof Participant && null !== $participant->getDeletedAt()) {
                    $bulk->recordSkipped(sprintf('#%d: přihláška mezitím zrušena — přeskočeno', (int) $participantId));
                    $bulk->setProcessedCount($start + (int) $position + 1);
                    $this->em->flush();
                    continue;
                }
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

            if ($bulk->getProcessedCount() >= $bulk->getTotalCount() && !$bulk->isCancelled()) {
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
     * @return array{sent: int, failed: int, processed: int, total: int, done: bool, busy: bool, limit: bool, waiting: bool, cancelled: bool, sendAfter: ?string}
     */
    private function progress(ParticipantMailBulk $bulk, int $sent, int $failed, bool $busy = false, bool $limit = false): array
    {
        $sendAfter = $bulk->getSendAfter();
        return [
            'sent'      => $sent,
            'failed'    => $failed,
            'processed' => $bulk->getProcessedCount(),
            'total'     => $bulk->getTotalCount(),
            'done'      => $bulk->isDone(),
            'busy'      => $busy,
            'limit'     => $limit,
            'waiting'   => $bulk->isWaiting($this->now()),
            'cancelled' => $bulk->isCancelled(),
            'sendAfter' => $sendAfter?->format(\DATE_ATOM),
        ];
    }
}
