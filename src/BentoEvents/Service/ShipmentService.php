<?php
/**
 * Shipment Service
 *
 * Provides shipment data for Bento events.
 */

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Service;

use ArtLounge\BentoCore\Api\ConfigInterface;
use Magento\Sales\Api\Data\ShipmentInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\ShipmentRepositoryInterface;
use Psr\Log\LoggerInterface;

class ShipmentService
{
    public function __construct(
        private readonly ShipmentRepositoryInterface $shipmentRepository,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly OrderService $orderService,
        private readonly ConfigInterface $config,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Get shipment data for async event
     *
     * @param int $id Shipment ID
     * @return array
     */
    public function getShipmentData(int $id): array
    {
        try {
            $shipment = $this->shipmentRepository->get($id);
            return $this->formatShipmentData($shipment);
        } catch (\Exception $e) {
            $this->logger->error('Failed to get shipment data for Bento', [
                'shipment_id' => $id,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Format shipment data for Bento
     *
     * @param ShipmentInterface $shipment
     * @return array
     */
    public function formatShipmentData(ShipmentInterface $shipment): array
    {
        $order = $this->orderRepository->get((int)$shipment->getOrderId());
        $orderData = $this->orderService->formatOrderData($order);

        // Get tracking information
        $tracks = [];
        foreach ($shipment->getTracks() as $track) {
            $tracks[] = [
                'carrier_code' => $track->getCarrierCode(),
                'title' => $track->getTitle(),
                'track_number' => $track->getTrackNumber()
            ];
        }

        // Get shipped items
        $shippedItems = [];
        foreach ($shipment->getItems() as $item) {
            $shippedItems[] = [
                'sku' => $item->getSku(),
                'name' => $item->getName(),
                'qty' => (int)$item->getQty()
            ];
        }

        return array_merge($orderData, [
            'event_type' => '$OrderShipped',

            'shipment' => [
                'shipment_id' => (int)$shipment->getEntityId(),
                'increment_id' => $shipment->getIncrementId(),
                'created_at' => $shipment->getCreatedAt(),
                'tracks' => $tracks,
                'shipped_items' => $shippedItems
            ]
        ]);
    }
}
