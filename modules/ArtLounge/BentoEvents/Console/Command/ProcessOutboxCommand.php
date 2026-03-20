<?php
declare(strict_types=1);

namespace ArtLounge\BentoEvents\Console\Command;

use ArtLounge\BentoEvents\Model\Outbox\Processor;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ProcessOutboxCommand extends Command
{
    public function __construct(
        private readonly Processor $processor
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('bento:outbox:process')
            ->setDescription('Replay pending outbox events to the AMQP queue')
            ->addOption('limit', 'l', InputOption::VALUE_OPTIONAL, 'Max entries to process', '50');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = (int)$input->getOption('limit');
        $output->writeln(sprintf('<info>Processing up to %d pending outbox entries...</info>', $limit));

        $result = $this->processor->processQueue($limit);

        if ($result['processed'] === 0 && $result['failed'] === 0 && $result['skipped'] === 0) {
            $output->writeln('No pending entries to process.');
            return Command::SUCCESS;
        }

        $output->writeln('');
        $output->writeln(sprintf(
            '<info>Done. Replayed: %d, Failed: %d, Skipped: %d</info>',
            $result['processed'],
            $result['failed'],
            $result['skipped']
        ));

        return Command::SUCCESS;
    }
}
