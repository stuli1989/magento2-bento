<?php

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Model\Purchase;

interface PurchaseMarkerStoreInterface
{
    /** INSERT IGNORE. @return bool true if this call created the marker (= first time paid) */
    public function claim(int $orderId, string $incrementId, string $email, string $purchasedAtUtc): bool;

    public function exists(int $orderId): bool;
}
