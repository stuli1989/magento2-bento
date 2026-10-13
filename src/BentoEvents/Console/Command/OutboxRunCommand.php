<?php

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Console\Command;

use ArtLounge\BentoEvents\Model\Outbox\OutboxStoreInterface;
use ArtLounge\BentoEvents\Model\Outbox\Sender;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class OutboxRunCommand extends Command
{
    public function __construct(
        private readonly Sender $sender,
        private readonly OutboxStoreInterface $store
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('bento:outbox:run')
            ->setDescription('Run one outbox send pass now (same as the per-minute cron)')
            ->addOption('max', null, InputOption::VALUE_REQUIRED, 'Max rows', '100');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $stats = $this->sender->run(60, max(1, (int)$input->getOption('max')));
        $output->writeln(json_encode($stats));

        return Command::SUCCESS;
    }
}
