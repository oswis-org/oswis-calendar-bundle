<?php

declare(strict_types=1);

namespace OswisOrg\OswisCalendarBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use OswisOrg\OswisCalendarBundle\Entity\ParticipantMail\ParticipantMail;
use OswisOrg\OswisCalendarBundle\Repository\Participant\ParticipantMailRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Backfill thread_key on all existing ParticipantMail rows.
 *
 * Idempotent: skips rows that already have thread_key set.
 * Use --dry-run to count without writing.
 *
 * Spec: docs/superpowers/specs/2026-05-24-communication-history-design.md §5 F.
 */
#[AsCommand(
    name: 'oswis:mail:backfill-threading',
    description: 'Compute thread_key for existing ParticipantMail rows that don\'t have one yet.',
)]
final class BackfillThreadingCommand extends Command
{
    private const int BATCH_SIZE = 500;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ParticipantMailRepository $mailRepo,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Count affected rows without persisting.')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Process at most N rows (0 = all).', '0');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $limitOpt = $input->getOption('limit');
        $limit = is_numeric($limitOpt) ? (int) $limitOpt : 0;

        $qb = $this->mailRepo->createQueryBuilder('mail')
            ->where('mail.threadKey IS NULL')
            ->orderBy('mail.id', 'ASC');
        if ($limit > 0) {
            $qb->setMaxResults($limit);
        }

        /** @var iterable<ParticipantMail> $iterator */
        $iterator = $qb->getQuery()->toIterable();

        $io->title($dryRun ? 'DRY RUN — backfilling thread_key' : 'Backfilling thread_key');

        $processed = 0;
        $updated = 0;
        $skipped = 0;
        foreach ($iterator as $mail) {
            if (!$mail instanceof ParticipantMail) {
                continue;
            }
            $mail->ensureThreadKey();
            if (null === $mail->getThreadKey()) {
                $skipped++;
            } else {
                $updated++;
            }
            $processed++;
            if (!$dryRun && 0 === $processed % self::BATCH_SIZE) {
                $this->em->flush();
                $this->em->clear();
                $io->writeln(sprintf('  ... processed %d rows', $processed));
            }
        }

        if (!$dryRun) {
            $this->em->flush();
            $this->em->clear();
        }

        $io->success(sprintf(
            '%s: %d processed, %d would update, %d skipped (no email).',
            $dryRun ? 'Dry run complete' : 'Backfill complete',
            $processed,
            $updated,
            $skipped,
        ));

        return Command::SUCCESS;
    }
}
