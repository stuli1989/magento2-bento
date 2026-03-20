<?php
declare(strict_types=1);

namespace ArtLounge\BentoEvents\Console\Command;

use ArtLounge\BentoCore\Api\BentoClientInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class TestConnectionCommand extends Command
{
    public function __construct(
        private readonly BentoClientInterface $bentoClient
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('bento:test')
            ->setDescription('Test the Bento API connection')
            ->addOption('store', 's', InputOption::VALUE_OPTIONAL, 'Store ID', null);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $storeId = $input->getOption('store');
        $storeId = $storeId !== null ? (int)$storeId : null;

        $output->writeln('<info>Testing Bento API connection...</info>');

        $result = $this->bentoClient->testConnection($storeId);

        if ($result['success'] ?? false) {
            $output->writeln('<info>Connection successful!</info>');
            if (isset($result['status_code'])) {
                $output->writeln(sprintf('  Status code: %d', $result['status_code']));
            }
            if (isset($result['response_time'])) {
                $output->writeln(sprintf('  Response time: %dms', $result['response_time']));
            }
            return Command::SUCCESS;
        }

        $output->writeln('<error>Connection failed: ' . ($result['message'] ?? 'Unknown error') . '</error>');
        return Command::FAILURE;
    }
}
