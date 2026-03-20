<?php
declare(strict_types=1);

namespace ArtLounge\BentoEvents\Console\Command;

use ArtLounge\BentoEvents\Model\Outbox\Processor;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class OutboxStatusCommand extends Command
{
    public function __construct(
        private readonly Processor $processor
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('bento:outbox:status')
            ->setDescription('Show outbox queue status counts');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $counts = $this->processor->getStatusCounts();
        $oldest = $this->processor->getOldestPendingAge();

        $output->writeln('<info>Bento Event Outbox Status</info>');
        $output->writeln('');
        $output->writeln(sprintf('  Pending:    %d', $counts['pending']));
        $output->writeln(sprintf('  Processing: %d', $counts['processing']));
        $output->writeln(sprintf('  Completed:  %d', $counts['completed']));
        $output->writeln(sprintf('  Failed:     %d', $counts['failed']));
        $output->writeln('');

        if ($oldest) {
            $output->writeln(sprintf('  Oldest pending: %s', $oldest));
        } else {
            $output->writeln('  No pending entries.');
        }

        if ($counts['failed'] > 0) {
            $output->writeln('');
            $output->writeln(sprintf(
                '<error>WARNING: %d failed entries (exceeded max attempts). Review var/log/bento.log.</error>',
                $counts['failed']
            ));
        }

        return Command::SUCCESS;
    }
}
