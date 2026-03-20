<?php
declare(strict_types=1);

namespace ArtLounge\BentoEvents\Console\Command;

use ArtLounge\BentoCore\Api\ConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class StatusCommand extends Command
{
    private const TABLE = 'artlounge_bento_abandoned_cart_schedule';

    public function __construct(
        private readonly ConfigInterface $config,
        private readonly ResourceConnection $resourceConnection
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('bento:status')
            ->setDescription('Show Bento integration status and abandoned cart schedule summary');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<info>Bento Integration Status</info>');
        $output->writeln(str_repeat('-', 40));

        // Config summary
        $output->writeln(sprintf('  Enabled:           %s', $this->config->isEnabled() ? 'Yes' : 'No'));
        $output->writeln(sprintf('  Debug:             %s', $this->config->isDebugEnabled() ? 'Yes' : 'No'));
        $output->writeln(sprintf('  Abandoned Cart:    %s', $this->config->isAbandonedCartEnabled() ? 'Yes' : 'No'));
        $output->writeln(sprintf('  Tracking:          %s', $this->config->isTrackingEnabled() ? 'Yes' : 'No'));

        // Schedule stats
        $connection = $this->resourceConnection->getConnection();
        $tableName = $this->resourceConnection->getTableName(self::TABLE);

        $statuses = ['pending', 'processing', 'sent', 'failed', 'converted', 'expired'];
        $output->writeln('');
        $output->writeln('<info>Abandoned Cart Schedule</info>');
        $output->writeln(str_repeat('-', 40));

        foreach ($statuses as $status) {
            $select = $connection->select()
                ->from($tableName, ['cnt' => new \Magento\Framework\DB\Sql\Expression('COUNT(*)')])
                ->where('status = ?', $status);
            $count = (int)$connection->fetchOne($select);
            $output->writeln(sprintf('  %-16s %d', ucfirst($status) . ':', $count));
        }

        $select = $connection->select()
            ->from($tableName, ['cnt' => new \Magento\Framework\DB\Sql\Expression('COUNT(*)')]);
        $total = (int)$connection->fetchOne($select);
        $output->writeln(sprintf('  %-16s %d', 'Total:', $total));

        return Command::SUCCESS;
    }
}
