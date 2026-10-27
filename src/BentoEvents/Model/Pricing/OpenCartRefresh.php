<?php
/**
 * Nightly: re-push the cart snapshot fields of carts abandoned in the last WINDOW_DAYS, so a ladder change,
 * a sale starting or a coupon being held back reaches the cart emails still to come (CA-03/03C go out ~72 h
 * after the abandon). Rebuilds each email's newest sent $cart_abandoned / $checkout_abandoned row with its own
 * builder and sends only the fields (/batch/subscribers), never a new event. Carts since converted or emptied
 * are skipped by the builders. Obeys the test-mode allow-list.
 * ponytail: the discount is the quote's stored total (as of the customer's last cart visit); opening the cart recollects it.
 */

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Model\Pricing;

use ArtLounge\BentoCore\Api\BentoClientInterface;
use ArtLounge\BentoCore\Api\ConfigInterface;
use ArtLounge\BentoEvents\Model\Outbox\OutboxRow;
use ArtLounge\BentoEvents\Model\Payload\BuilderPool;
use Magento\Framework\App\ResourceConnection;

class OpenCartRefresh
{
    public const WINDOW_DAYS = 4;
    private const EVENTS = ['cart_abandoned', 'checkout_abandoned'];

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly BuilderPool $builders,
        private readonly BentoClientInterface $client,
        private readonly ConfigInterface $config
    ) {
    }

    /** @return array{carts:int, sent:int, skipped:int, error:?string} */
    public function run(): array
    {
        $conn = $this->resource->getConnection();
        $rows = $conn->fetchAll(
            $conn->select()->from($this->resource->getTableName('artlounge_bento_outbox'))
                ->where('event_type IN (?)', self::EVENTS)
                ->where('status = ?', 'sent')
                ->where('created_at >= ?', gmdate('Y-m-d H:i:s', time() - self::WINDOW_DAYS * 86400))
                ->order('id DESC')
        );
        $testMode = $this->config->getTestMode();
        $records = [];
        $skipped = 0;
        foreach ($rows as $r) {
            $row = OutboxRow::fromArray($r);
            if (isset($records[$row->email]) || !$testMode->allows($row->email)) {
                continue; // newest cart per email only
            }
            $result = $this->builders->get($row->eventType)?->build($row);
            if ($result === null || $result->isSkip() || empty($result->event['fields'])) {
                $skipped++;
                continue;
            }
            $records[$row->email] = ['email' => $row->email] + $result->event['fields'];
        }
        $report = ['carts' => count($records), 'sent' => 0, 'skipped' => $skipped, 'error' => null];
        foreach (array_chunk(array_values($records), 1000) as $chunk) {
            $sent = $this->client->importSubscribers($chunk);
            if ($sent->isSuccess()) {
                $report['sent'] += count($chunk);
            } else {
                $report['error'] = $sent->describe();
            }
        }

        return $report;
    }
}
