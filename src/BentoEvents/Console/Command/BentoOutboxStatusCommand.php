<?php

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Console\Command;

use ArtLounge\BentoCore\Api\ConfigInterface;
use ArtLounge\BentoEvents\Model\Checkout\ContactStore;
use ArtLounge\BentoEvents\Model\Outbox\OutboxStoreInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class BentoOutboxStatusCommand extends Command
{
    public function __construct(
        private readonly OutboxStoreInterface $store,
        private readonly ContactStore $contacts,
        private readonly ConfigInterface $config
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('bento:outbox:status')
            ->setDescription('Bento outbox counts, oldest pending age, parked rows, contact-capture health');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<info>Bento outbox</info>');
        $output->writeln(sprintf(
            '  configured: %s   test mode: %s',
            $this->config->isConfigured() ? 'yes' : '<comment>NO (rows wait as pending)</comment>',
            $this->config->getTestMode()->isActive()
                ? implode(', ', $this->config->getTestModeEmailPatterns())
                : 'off'
        ));

        $counts = $this->store->countByStatus();
        foreach (['pending', 'sending', 'sent', 'parked', 'skipped'] as $status) {
            $output->writeln(sprintf('  %-8s %d', $status, $counts[$status] ?? 0));
        }

        $oldest = $this->store->oldestPendingCreatedAt();
        if ($oldest !== null) {
            $age = time() - strtotime($oldest . ' UTC');
            $output->writeln(sprintf(
                '  oldest pending: %s (%d min ago)%s',
                $oldest,
                intdiv($age, 60),
                $age > 900 ? '  <error>cron stopped or Bento unreachable?</error>' : ''
            ));
        }

        $parked = $this->store->listByStatus(OutboxStoreInterface::STATUS_PARKED, 20);
        if ($parked) {
            $output->writeln('<comment>Parked (newest 20) — fix then `bento:outbox:replay --id=N` or `--parked`:</comment>');
            foreach ($parked as $row) {
                $output->writeln(sprintf(
                    '  #%d %s %s %s — %s',
                    $row->id,
                    $row->eventType,
                    $row->dedupKey,
                    $row->email,
                    mb_substr((string)$row->lastError, 0, 160)
                ));
            }
        }

        $captures = $this->contacts->countSince(gmdate('Y-m-d H:i:s', time() - 7 * 86400));
        $output->writeln(sprintf('  contact captures (7 days): %d%s', $captures, $captures === 0
            ? '  <comment>zero — if checkouts happened, Razorpay mx-analytics names may have changed</comment>'
            : ''));

        return Command::SUCCESS;
    }
}
