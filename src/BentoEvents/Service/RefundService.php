<?php
/**
 * Refund Service
 *
 * Provides refund/creditmemo data for Bento events.
 */

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Service;

use ArtLounge\BentoCore\Api\ConfigInterface;
use Magento\Sales\Api\CreditmemoRepositoryInterface;
use Magento\Sales\Api\Data\CreditmemoInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Psr\Log\LoggerInterface;

class RefundService
{
    public function __construct(
        private readonly CreditmemoRepositoryInterface $creditmemoRepository,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly OrderService $orderService,
        private readonly ConfigInterface $config,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Get refund data for async event
     *
     * @param int $id Creditmemo ID
     * @return array
     */
    public function getRefundData(int $id): array
    {
        try {
            $creditmemo = $this->creditmemoRepository->get($id);
            return $this->formatRefundData($creditmemo);
        } catch (\Exception $e) {
            $this->logger->error('Failed to get refund data for Bento', [
                'creditmemo_id' => $id,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Format refund data for Bento
     *
     * @param CreditmemoInterface $creditmemo
     * @return array
     */
    public function formatRefundData(CreditmemoInterface $creditmemo): array
    {
        $order = $this->orderRepository->get((int)$creditmemo->getOrderId());
        $orderData = $this->orderService->formatOrderData($order);
        $storeId = (int)$order->getStoreId();
        $multiplier = $this->config->getCurrencyMultiplier($storeId);

        // Get refunded items
        $refundedItems = [];
        foreach ($creditmemo->getItems() as $item) {
            if ($item->getQty() > 0) {
                $refundedItems[] = [
                    'sku' => $item->getSku(),
                    'name' => $item->getName(),
                    'qty' => (int)$item->getQty(),
                    'row_total' => (int)round((float)$item->getRowTotal() * $multiplier)
                ];
            }
        }

        return array_merge($orderData, [
            'event_type' => '$OrderRefunded',

            'refund' => [
                'creditmemo_id' => (int)$creditmemo->getEntityId(),
                'increment_id' => $creditmemo->getIncrementId(),
                'created_at' => $creditmemo->getCreatedAt(),
                'refund_amount' => (int)round((float)$creditmemo->getGrandTotal() * $multiplier),
                'adjustment_positive' => (int)round((float)$creditmemo->getAdjustmentPositive() * $multiplier),
                'adjustment_negative' => (int)round((float)$creditmemo->getAdjustmentNegative() * $multiplier),
                'refunded_items' => $refundedItems
            ]
        ]);
    }
}
