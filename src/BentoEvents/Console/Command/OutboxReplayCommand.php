<?php

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Console\Command;

use ArtLounge\BentoEvents\Model\Outbox\OutboxStoreInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class OutboxReplayCommand extends Command
{
    public function __construct(private readonly OutboxStoreInterface $store)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('bento:outbox:replay')
            ->setDescription('Re-queue parked/skipped outbox rows (sent on the next cron run)')
            ->addOption('id', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Row id(s)')
            ->addOption('parked', null, InputOption::VALUE_NONE, 'All parked rows');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $ids = array_map('intval', (array)$input->getOption('id'));
        if (!$ids && !$input->getOption('parked')) {
            $output->writeln('<error>Pass --id=N (repeatable) or --parked</error>');
            return Command::INVALID;
        }
        $n = $this->store->requeue($ids ?: null);
        $output->writeln(sprintf('<info>Re-queued %d row(s).</info>', $n));

        return Command::SUCCESS;
    }
}
