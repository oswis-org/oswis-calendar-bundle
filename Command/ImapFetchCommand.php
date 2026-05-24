<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Command;

use OswisOrg\OswisCalendarBundle\Service\Imap\ImapFetchService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'oswis:imap:fetch',
    description: 'Read-only IMAP fetch of new mails into ParticipantIncomingMail / ParticipantUnmatchedMail.',
)]
final class ImapFetchCommand extends Command
{
    public function __construct(
        private readonly ImapFetchService $imapFetchService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'cap',
            null,
            InputOption::VALUE_REQUIRED,
            'Max messages per folder per run',
            '100',
        );
        $this->addOption(
            'init-from-now',
            null,
            InputOption::VALUE_NONE,
            'First-time init: set lastSeenUid to current MAX UID per folder without fetching bodies. Use once before enabling cron on a folder with years of historical mail.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $cap = max(1, (int) $input->getOption('cap'));
        $initFromNow = (bool) $input->getOption('init-from-now');

        $io->section('IMAP fetch (READ-ONLY)'.($initFromNow ? ' — INIT-FROM-NOW' : ''));
        $report = $this->imapFetchService->fetchAll($cap, $initFromNow);

        if (!$report['enabled']) {
            $io->warning('IMAP fetch disabled (OSWIS_IMAP_ENABLED=0). Set =1 in .env.local and retry.');

            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($report['folders'] as $folder => $stats) {
            $rows[] = [
                $folder,
                $stats['fetched'] ?? 0,
                $stats['matched'] ?? 0,
                $stats['unmatched'] ?? 0,
                $stats['lastUid'] ?? 0,
                $stats['error'] ?? '',
            ];
        }
        $io->table(['Folder', 'Fetched', 'Matched', 'Unmatched', 'LastUid', 'Error'], $rows);
        $io->success('IMAP fetch finished.');

        return Command::SUCCESS;
    }
}
