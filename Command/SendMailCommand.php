<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Command;

use OswisOrg\OswisCalendarBundle\Repository\Participant\ParticipantMailBulkRepository;
use OswisOrg\OswisCalendarBundle\Service\Participant\ParticipantBulkMailService;
use OswisOrg\OswisCalendarBundle\Service\Participant\ParticipantService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Drains the e-mail outbox — intended to be run periodically by cron (ISPConfig). Sends real mail,
 * so it is SAFE BY DEFAULT:
 *   - drains only the explicit ad-hoc bulk outbox ({@see ParticipantMailBulk}), which an admin
 *     deliberately composed + queued;
 *   - the auto-mail engine (infomail / feedback groups) runs ONLY with --automails, AND only for
 *     groups whose automaticMailing flag + date window are set — a deliberate double gate so old
 *     campaigns can't blast (see the 2026-06-08 disable of 19 stale groups);
 *   - --dry-run reports what WOULD be sent without sending anything.
 *
 * Single-instance via a native flock (no symfony/lock dependency); overlapping cron ticks no-op.
 */
#[AsCommand(
    name: 'oswis:mail:send',
    description: 'Drain the bulk e-mail outbox (cron). Safe-by-default: bulks only; --automails to also run auto-mail groups.',
)]
final class SendMailCommand extends Command
{
    private const string LOCK_FILE = 'oswis-mail-send.lock';

    public function __construct(
        private readonly ParticipantBulkMailService $bulkMailService,
        private readonly ParticipantMailBulkRepository $bulkRepository,
        private readonly ParticipantService $participantService,
    ) {
        parent::__construct();
    }

    private function intOption(InputInterface $input, string $name, int $default): int
    {
        $value = $input->getOption($name);

        return is_numeric($value) ? (int) $value : $default;
    }

    protected function configure(): void
    {
        $this
            ->addOption('batch', null, InputOption::VALUE_REQUIRED, 'Recipients per drain batch.', '15')
            ->addOption('max-recipients', null, InputOption::VALUE_REQUIRED, 'Cap total bulk recipients processed this run (0 = drain all pending).', '0')
            ->addOption('automails', null, InputOption::VALUE_NONE, 'ALSO run the auto-mail engine (off by default — sends real automatic mails).')
            ->addOption('automail-limit', null, InputOption::VALUE_REQUIRED, 'Max participants per auto-mail group when --automails.', '100')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would be sent without sending.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $batch = max(1, $this->intOption($input, 'batch', 15));
        $maxRecipients = max(0, $this->intOption($input, 'max-recipients', 0));
        $withAutomails = (bool) $input->getOption('automails');
        $automailLimit = max(1, $this->intOption($input, 'automail-limit', 100));
        $dryRun = (bool) $input->getOption('dry-run');

        // Single-instance guard (native flock, no daemon/dependency).
        $lockPath = sys_get_temp_dir().'/'.self::LOCK_FILE;
        $lockHandle = fopen($lockPath, 'c');
        if (false === $lockHandle || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
            $io->note('Another oswis:mail:send run holds the lock — skipping this tick.');
            if (false !== $lockHandle) {
                fclose($lockHandle);
            }

            return Command::SUCCESS;
        }

        try {
            $pending = $this->bulkRepository->findPending(100);

            if ($dryRun) {
                $io->section('DRY-RUN (nic se neodešle)');
                $totalRemaining = 0;
                foreach ($pending as $bulk) {
                    $remaining = $bulk->getRemainingCount();
                    $totalRemaining += $remaining;
                    $io->writeln(sprintf(
                        '  bulk #%d "%s" — %d/%d zpracováno, zbývá %d, status %s',
                        $bulk->getId() ?? 0,
                        $bulk->getSubject(),
                        $bulk->getProcessedCount(),
                        $bulk->getTotalCount(),
                        $remaining,
                        $bulk->getStatus(),
                    ));
                }
                $io->writeln(sprintf('Pending bulků: %d, příjemců zbývá celkem: %d', count($pending), $totalRemaining));
                $io->writeln($withAutomails ? 'Automaily: ZAPNUTO (běh by zpracoval aktivní automail skupiny).' : 'Automaily: vypnuto (--automails pro zapnutí).');

                return Command::SUCCESS;
            }

            $bulksTouched = 0;
            $totalSent = 0;
            $totalFailed = 0;
            $processedThisRun = 0;

            foreach ($pending as $bulk) {
                if (0 !== $maxRecipients && $processedThisRun >= $maxRecipients) {
                    break;
                }
                $bulksTouched++;
                while (!$bulk->isDone()) {
                    if (0 !== $maxRecipients && $processedThisRun >= $maxRecipients) {
                        break;
                    }
                    $progress = $this->bulkMailService->drainBatch($bulk, $batch);
                    $totalSent += $progress['sent'];
                    $totalFailed += $progress['failed'];
                    $processedThisRun += $progress['sent'] + $progress['failed'];
                    if (0 === $progress['sent'] + $progress['failed']) {
                        break; // nothing advanced (empty slice) — avoid spin
                    }
                }
            }

            if ($bulksTouched > 0) {
                $io->success(sprintf('Bulk outbox: %d dávek, odesláno %d, selhalo %d.', $bulksTouched, $totalSent, $totalFailed));
            } else {
                $io->writeln('Bulk outbox: nic ke zpracování.');
            }

            if ($withAutomails) {
                $summary = $this->participantService->sendAutoMails(null, null, $automailLimit);
                $io->success(sprintf(
                    'Automaily: odesláno %d, selhalo %d.%s',
                    $summary['sent'],
                    $summary['failed'],
                    [] === $summary['errors'] ? '' : ' Chyby: '.implode(' | ', array_slice($summary['errors'], 0, 10)),
                ));
            }

            return Command::SUCCESS;
        } finally {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
    }
}
