<?php

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Model\Payload;

use ArtLounge\BentoEvents\Model\RecoveryToken;
use Magento\Framework\UrlInterface;
use Psr\Log\LoggerInterface;

class RecoveryUrlBuilder
{
    public function __construct(
        private readonly RecoveryToken $recoveryToken,
        private readonly UrlInterface $urlBuilder,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Signed, expiring link to bento/cart/recover. A coupon (when given) is a path param and is
     * auto-applied by the Recover controller.
     */
    public function build(int $quoteId, string $email, int $storeId, ?string $couponCode = null): ?string
    {
        try {
            $token = $this->recoveryToken->generate($quoteId, $email, $storeId);
            if ($token === null) {
                return null;
            }
            $params = ['_scope' => $storeId, 'recover' => $token, 'autopay' => 1, '_nosid' => true];
            if ($couponCode !== null && $couponCode !== '') {
                $params['coupon'] = $couponCode;
            }

            return $this->urlBuilder->getUrl('bento/cart/recover', $params);
        } catch (\Throwable $e) {
            $this->logger->warning('Bento recovery URL failed', ['quote_id' => $quoteId, 'error' => $e->getMessage()]);
            return null;
        }
    }
}
