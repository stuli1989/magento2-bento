<?php
declare(strict_types=1);

namespace ArtLounge\BentoEvents\Console\Command;

use ArtLounge\BentoEvents\Model\DeadLetter\Monitor;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ReplayDeadLetterCommand extends Command
{
    public function __construct(
        private readonly Monitor $monitor
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('bento:deadletter:replay')
            ->setDescription('Replay dead-lettered events back to the retry queue')
            ->addOption('limit', 'l', InputOption::VALUE_OPTIONAL, 'Max messages to replay', '50');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = (int)$input->getOption('limit');

        // Show current queue depth first
        $depth = $this->monitor->checkQueueDepth();
        $output->writeln(sprintf('<info>Dead-letter queue depth: %d</info>', $depth));

        if ($depth === 0) {
            $output->writeln('No dead-lettered events to replay.');
            return Command::SUCCESS;
        }

        $output->writeln(sprintf('Replaying up to %d messages...', min($limit, $depth)));
        $output->writeln('');

        $result = $this->monitor->replay($limit);

        $output->writeln(sprintf(
            '<info>Done. Replayed: %d, Failed: %d</info>',
            $result['replayed'],
            $result['failed']
        ));

        if ($result['replayed'] > 0) {
            $output->writeln('');
            $output->writeln('<comment>Start the retry consumer to process replayed events:</comment>');
            $output->writeln('  php bin/magento queue:consumers:start event.retry.consumer --max-messages=50');
        }

        return Command::SUCCESS;
    }
}
