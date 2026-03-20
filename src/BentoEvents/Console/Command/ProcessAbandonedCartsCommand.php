<?php
declare(strict_types=1);

namespace ArtLounge\BentoEvents\Console\Command;

use ArtLounge\BentoEvents\Model\AbandonedCart\Checker;
use ArtLounge\BentoEvents\Model\AbandonedCart\Scheduler;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ProcessAbandonedCartsCommand extends Command
{
    public function __construct(
        private readonly Scheduler $scheduler,
        private readonly Checker $checker
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('bento:abandoned-cart:process')
            ->setDescription('Manually process pending abandoned cart schedules')
            ->addOption('limit', 'l', InputOption::VALUE_OPTIONAL, 'Max carts to process', '100');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = (int)$input->getOption('limit');
        $output->writeln(sprintf('<info>Processing up to %d pending abandoned carts...</info>', $limit));

        $pendingCarts = $this->scheduler->getPendingCarts($limit);

        if (empty($pendingCarts)) {
            $output->writeln('No pending carts to process.');
            return Command::SUCCESS;
        }

        $processed = 0;
        $triggered = 0;

        foreach ($pendingCarts as $cart) {
            $result = $this->checker->checkAndTrigger(
                (int)$cart['quote_id'],
                $cart['quote_updated_at']
            );

            $processed++;
            if ($result) {
                $triggered++;
                $output->writeln(sprintf(
                    '  [SENT] Quote #%d (%s)',
                    $cart['quote_id'],
                    $cart['customer_email'] ?? 'no email'
                ));
            } else {
                $output->writeln(sprintf(
                    '  [SKIP] Quote #%d',
                    $cart['quote_id']
                ));
            }
        }

        $output->writeln('');
        $output->writeln(sprintf('<info>Done. Processed: %d, Triggered: %d</info>', $processed, $triggered));

        return Command::SUCCESS;
    }
}
