<?php

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Console\Command;

use ArtLounge\BentoEvents\Model\Profile\Backfill;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class BackfillCommand extends Command
{
    public function __construct(
        private readonly Backfill $backfill,
        private readonly State $state
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('bento:backfill')
            ->setDescription('Push customer profile fields (order_count, lifetime_spend, primary_medium, …) for every buyer to Bento')
            ->addOption('email-pattern', null, InputOption::VALUE_REQUIRED, 'Only emails matching this glob, e.g. "*+bentotest*@*"')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Count and print a sample; send nothing')
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Emails per /batch/subscribers call', '1000')
            ->addOption('resume', null, InputOption::VALUE_NONE, 'Continue after the last successful batch');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->state->setAreaCode(Area::AREA_CRONTAB);
        } catch (\Throwable) {
            // already set
        }
        $dry = (bool)$input->getOption('dry-run');
        $report = $this->backfill->run(
            $input->getOption('email-pattern'),
            $dry,
            max(1, min(1000, (int)$input->getOption('batch-size'))),
            (bool)$input->getOption('resume'),
            static fn (string $m) => $output->writeln($m)
        );

        $output->writeln(sprintf(
            '<info>%s</info> eligible=%d filtered_out=%d sent=%d batches=%d',
            $dry ? 'DRY RUN' : 'DONE',
            $report['eligible'],
            $report['filtered_out'],
            $report['sent'],
            $report['batches']
        ));
        if ($report['sample']) {
            $output->writeln('Sample:');
            $output->writeln(json_encode($report['sample'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        if ($report['error'] !== null) {
            $output->writeln('<error>' . $report['error'] . '</error>');
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
