<?php
declare(strict_types=1);

namespace ArtLounge\BentoEvents\Console\Command;

use ArtLounge\BentoEvents\Model\AbandonedCart\Scheduler;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class CleanupAbandonedCartsCommand extends Command
{
    public function __construct(
        private readonly Scheduler $scheduler
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('bento:abandoned-cart:cleanup')
            ->setDescription('Remove old abandoned cart schedule entries')
            ->addOption('days', 'd', InputOption::VALUE_OPTIONAL, 'Delete entries older than N days', '7');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $days = (int)$input->getOption('days');

        if ($days < 1) {
            $output->writeln('<error>Days must be at least 1</error>');
            return Command::FAILURE;
        }

        $output->writeln(sprintf('<info>Cleaning up abandoned cart entries older than %d days...</info>', $days));

        $deleted = $this->scheduler->cleanup($days);

        $output->writeln(sprintf('<info>Done. Deleted %d entries.</info>', $deleted));

        return Command::SUCCESS;
    }
}
