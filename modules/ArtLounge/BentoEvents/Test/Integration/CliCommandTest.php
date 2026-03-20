<?php
/**
 * Integration Test: CLI Commands
 *
 * Tests all 4 CLI commands using Symfony CommandTester with mock dependencies.
 * Verifies output messages, return codes, and argument handling.
 */

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Test\Integration;

use ArtLounge\BentoCore\Api\BentoClientInterface;
use ArtLounge\BentoCore\Api\ConfigInterface;
use ArtLounge\BentoEvents\Console\Command\CleanupAbandonedCartsCommand;
use ArtLounge\BentoEvents\Console\Command\ProcessAbandonedCartsCommand;
use ArtLounge\BentoEvents\Console\Command\StatusCommand;
use ArtLounge\BentoEvents\Console\Command\TestConnectionCommand;
use ArtLounge\BentoEvents\Model\AbandonedCart\Checker;
use ArtLounge\BentoEvents\Model\AbandonedCart\Scheduler;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class CliCommandTest extends TestCase
{
    // ──────────────────────────────────────────────
    // bento:test — successful connection
    // ──────────────────────────────────────────────
    public function testTestConnectionSuccess(): void
    {
        $client = $this->createMock(BentoClientInterface::class);
        $client->method('testConnection')
            ->with(null)
            ->willReturn([
                'success' => true,
                'status_code' => 200,
                'response_time' => 42
            ]);

        $command = new TestConnectionCommand($client);
        $tester = new CommandTester($command);
        $tester->execute([]);

        $output = $tester->getDisplay();
        $this->assertStringContainsString('Connection successful', $output);
        $this->assertStringContainsString('200', $output);
        $this->assertStringContainsString('42ms', $output);
        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    // ──────────────────────────────────────────────
    // bento:test — failed connection
    // ──────────────────────────────────────────────
    public function testTestConnectionFailure(): void
    {
        $client = $this->createMock(BentoClientInterface::class);
        $client->method('testConnection')
            ->willReturn([
                'success' => false,
                'message' => 'Unauthorized – invalid key'
            ]);

        $command = new TestConnectionCommand($client);
        $tester = new CommandTester($command);
        $tester->execute([]);

        $output = $tester->getDisplay();
        $this->assertStringContainsString('Connection failed', $output);
        $this->assertStringContainsString('Unauthorized', $output);
        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
    }

    // ──────────────────────────────────────────────
    // bento:test — with --store option
    // ──────────────────────────────────────────────
    public function testTestConnectionWithStoreOption(): void
    {
        $client = $this->createMock(BentoClientInterface::class);
        $client->expects($this->once())
            ->method('testConnection')
            ->with(3)
            ->willReturn(['success' => true]);

        $command = new TestConnectionCommand($client);
        $tester = new CommandTester($command);
        $tester->execute(['--store' => '3']);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    // ──────────────────────────────────────────────
    // bento:status — displays config flags and schedule counts
    // ──────────────────────────────────────────────
    public function testStatusCommand(): void
    {
        $config = $this->createMock(ConfigInterface::class);
        $config->method('isEnabled')->willReturn(true);
        $config->method('isDebugEnabled')->willReturn(false);
        $config->method('isAbandonedCartEnabled')->willReturn(true);
        $config->method('isTrackingEnabled')->willReturn(true);

        // DB adapter that returns counts per status
        $statusCounts = [
            'pending' => 5,
            'processing' => 1,
            'sent' => 12,
            'failed' => 0,
            'converted' => 3,
            'expired' => 0,
        ];
        $totalCount = array_sum($statusCounts);

        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();

        $adapter = $this->createMock(AdapterInterface::class);
        $adapter->method('select')->willReturn($select);

        // fetchOne returns the count for each successive call (6 statuses + 1 total)
        $fetchSequence = array_merge(array_values($statusCounts), [$totalCount]);
        $adapter->method('fetchOne')
            ->willReturnOnConsecutiveCalls(...$fetchSequence);

        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($adapter);
        $resource->method('getTableName')->willReturnArgument(0);

        $command = new StatusCommand($config, $resource);
        $tester = new CommandTester($command);
        $tester->execute([]);

        $output = $tester->getDisplay();

        // Config flags
        $this->assertStringContainsString('Yes', $output); // Enabled
        $this->assertStringContainsString('No', $output);  // Debug

        // Schedule counts
        $this->assertStringContainsString('5', $output);   // Pending
        $this->assertStringContainsString('12', $output);  // Sent
        $this->assertStringContainsString((string)$totalCount, $output);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    // ──────────────────────────────────────────────
    // bento:abandoned-cart:process — processes pending carts
    // ──────────────────────────────────────────────
    public function testProcessAbandonedCartsCommand(): void
    {
        $pendingCarts = [
            ['quote_id' => 101, 'customer_email' => 'a@b.com', 'quote_updated_at' => '2025-01-01 00:00:00'],
            ['quote_id' => 102, 'customer_email' => 'c@d.com', 'quote_updated_at' => '2025-01-01 01:00:00'],
        ];

        $scheduler = $this->createMock(Scheduler::class);
        $scheduler->method('getPendingCarts')
            ->with(100)
            ->willReturn($pendingCarts);

        $checker = $this->createMock(Checker::class);
        // First cart triggers, second is skipped
        $checker->method('checkAndTrigger')
            ->willReturnOnConsecutiveCalls(true, false);

        $command = new ProcessAbandonedCartsCommand($scheduler, $checker);
        $tester = new CommandTester($command);
        $tester->execute([]);

        $output = $tester->getDisplay();
        $this->assertStringContainsString('[SENT] Quote #101', $output);
        $this->assertStringContainsString('[SKIP] Quote #102', $output);
        $this->assertStringContainsString('Processed: 2', $output);
        $this->assertStringContainsString('Triggered: 1', $output);
        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    // ──────────────────────────────────────────────
    // bento:abandoned-cart:process — no pending carts
    // ──────────────────────────────────────────────
    public function testProcessAbandonedCartsNoPending(): void
    {
        $scheduler = $this->createMock(Scheduler::class);
        $scheduler->method('getPendingCarts')->willReturn([]);

        $checker = $this->createMock(Checker::class);

        $command = new ProcessAbandonedCartsCommand($scheduler, $checker);
        $tester = new CommandTester($command);
        $tester->execute([]);

        $output = $tester->getDisplay();
        $this->assertStringContainsString('No pending carts', $output);
        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    // ──────────────────────────────────────────────
    // bento:abandoned-cart:process — custom --limit
    // ──────────────────────────────────────────────
    public function testProcessAbandonedCartsCustomLimit(): void
    {
        $scheduler = $this->createMock(Scheduler::class);
        $scheduler->expects($this->once())
            ->method('getPendingCarts')
            ->with(25)
            ->willReturn([]);

        $checker = $this->createMock(Checker::class);

        $command = new ProcessAbandonedCartsCommand($scheduler, $checker);
        $tester = new CommandTester($command);
        $tester->execute(['--limit' => '25']);

        $this->assertStringContainsString('25', $tester->getDisplay());
    }

    // ──────────────────────────────────────────────
    // bento:abandoned-cart:cleanup — successful cleanup
    // ──────────────────────────────────────────────
    public function testCleanupCommand(): void
    {
        $scheduler = $this->createMock(Scheduler::class);
        $scheduler->expects($this->once())
            ->method('cleanup')
            ->with(7)
            ->willReturn(42);

        $command = new CleanupAbandonedCartsCommand($scheduler);
        $tester = new CommandTester($command);
        $tester->execute([]);

        $output = $tester->getDisplay();
        $this->assertStringContainsString('Deleted 42 entries', $output);
        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    // ──────────────────────────────────────────────
    // bento:abandoned-cart:cleanup — custom --days
    // ──────────────────────────────────────────────
    public function testCleanupCommandCustomDays(): void
    {
        $scheduler = $this->createMock(Scheduler::class);
        $scheduler->expects($this->once())
            ->method('cleanup')
            ->with(30)
            ->willReturn(0);

        $command = new CleanupAbandonedCartsCommand($scheduler);
        $tester = new CommandTester($command);
        $tester->execute(['--days' => '30']);

        $this->assertStringContainsString('30 days', $tester->getDisplay());
        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    // ──────────────────────────────────────────────
    // bento:abandoned-cart:cleanup — invalid days → FAILURE
    // ──────────────────────────────────────────────
    public function testCleanupCommandInvalidDays(): void
    {
        $scheduler = $this->createMock(Scheduler::class);
        $scheduler->expects($this->never())->method('cleanup');

        $command = new CleanupAbandonedCartsCommand($scheduler);
        $tester = new CommandTester($command);
        $tester->execute(['--days' => '0']);

        $this->assertStringContainsString('Days must be at least 1', $tester->getDisplay());
        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
    }
}
