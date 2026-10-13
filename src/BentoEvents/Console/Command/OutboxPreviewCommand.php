<?php

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Console\Command;

use ArtLounge\BentoEvents\Model\Outbox\OutboxStoreInterface;
use ArtLounge\BentoEvents\Model\Payload\BuilderPool;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Prints the exact JSON the sender would POST for a row — without sending or changing it.
 */
class OutboxPreviewCommand extends Command
{
    public function __construct(
        private readonly OutboxStoreInterface $store,
        private readonly BuilderPool $builders
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('bento:outbox:preview')
            ->setDescription('Print the payload a given outbox row would send (no send)')
            ->addOption('id', null, InputOption::VALUE_REQUIRED, 'Row id');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $row = $this->store->getById((int)$input->getOption('id'));
        if ($row === null) {
            $output->writeln('<error>No such row</error>');
            return Command::FAILURE;
        }
        $builder = $this->builders->get($row->eventType);
        if ($builder === null) {
            $output->writeln('<error>No builder for ' . $row->eventType . '</error>');
            return Command::FAILURE;
        }
        $built = $builder->build($row);
        $output->writeln(json_encode(
            $built->isSkip()
                ? ['skip' => $built->skipReason]
                : ['subscriber' => $built->subscriber, 'event' => $built->event],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ));

        return Command::SUCCESS;
    }
}
