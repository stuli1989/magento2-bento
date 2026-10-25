<?php

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Console\Command;

use ArtLounge\BentoEvents\Model\Picks\NextPicksProvider;
use ArtLounge\BentoEvents\Model\Picks\Refresh;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class NextPicksCommand extends Command
{
    public function __construct(
        private readonly Refresh $refresh,
        private readonly NextPicksProvider $picks,
        private readonly State $state
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('bento:next-picks')
            ->setDescription('Recompute next_pick_* / new_in_* / usual_* (with the other profile fields) and push them to Bento')
            ->addOption('email', null, InputOption::VALUE_REQUIRED, 'Only this customer')
            ->addOption('recent', null, InputOption::VALUE_NONE, 'Buyers with an order in the last --days days')
            ->addOption('vips', null, InputOption::VALUE_NONE, 'All VIPs (admin spend / order-count thresholds). Neither flag = both, like the cron')
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'Window for --recent (default: admin Refresh Window, 130)')
            ->addOption('medium', null, InputOption::VALUE_REQUIRED, 'Print the lists for a customer of this medium with no purchases; sends nothing')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Count and print a sample; send nothing')
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Emails per /batch/subscribers call', (string)Refresh::BATCH_SIZE);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->state->setAreaCode(Area::AREA_CRONTAB);
        } catch (\Throwable) {
            // already set
        }
        $json = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        $medium = $input->getOption('medium');
        if ($medium !== null) {
            $output->writeln(json_encode($this->picks->fieldsFor(['(no purchases)' => strtolower($medium)]), $json));
            return Command::SUCCESS;
        }

        $dry = (bool)$input->getOption('dry-run');
        $recent = (bool)$input->getOption('recent');
        $vips = (bool)$input->getOption('vips');
        $report = $this->refresh->run(
            $input->getOption('email'),
            $recent || !$vips,
            $vips || !$recent,
            $input->getOption('days') !== null ? (int)$input->getOption('days') : null,
            $dry,
            (int)$input->getOption('batch-size'),
            static fn (string $m) => $output->writeln($m)
        );
        $output->writeln(sprintf(
            '<info>%s</info> recent=%d vips=%d audience=%d eligible=%d filtered_out=%d sent=%d batches=%d failed_batches=%d',
            $dry ? 'DRY RUN' : 'DONE',
            $report['recent'],
            $report['vips'],
            $report['audience'],
            $report['eligible'],
            $report['filtered_out'],
            $report['sent'],
            $report['batches'],
            $report['failed_batches']
        ));
        if ($report['sample']) {
            $output->writeln('Sample:');
            $output->writeln(json_encode($report['sample'], $json));
        }
        if ($report['error'] !== null) {
            $output->writeln('<error>' . $report['error'] . '</error>');
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
