<?php
/**
 * POST /bento/checkout/contact — {email, phone, rzp_order_id} from the Magic Checkout popup.
 *
 * Trust boundary: form key, 10 requests/min/session, strict input formats, and the Razorpay
 * order must have been created in THIS session (checkout-session key written by PlaceOrder).
 * Stores the contact and arms a delayed check (contact_capture_delay); the abandonment cron
 * emits $checkout_abandoned only if no paid order / dismissal arrived first.
 */

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Model\Checkout;

use ArtLounge\BentoCore\Api\ConfigInterface;
use ArtLounge\BentoEvents\Model\Payload\Phone;
use ArtLounge\BentoEvents\Model\Razorpay\CheckoutContextInterface;
use Psr\Log\LoggerInterface;

class ContactCaptureHandler
{
    private const RATE_LIMIT = 10;
    private const RATE_WINDOW_SECONDS = 60;

    public function __construct(
        private readonly CheckoutContextInterface $context,
        private readonly ContactStore $store,
        private readonly ConfigInterface $config,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param array $rateState per-session {start:int, count:int}, persisted by the caller
     * @return array{0:int, 1:array} [http code, json body]
     */
    public function handle(array $params, bool $formKeyValid, array &$rateState): array
    {
        if (!$formKeyValid) {
            return [403, ['ok' => false]];
        }
        $now = time();
        if (empty($rateState['start']) || $now - (int)$rateState['start'] >= self::RATE_WINDOW_SECONDS) {
            $rateState = ['start' => $now, 'count' => 0];
        }
        if ((int)$rateState['count'] >= self::RATE_LIMIT) {
            return [429, ['ok' => false]];
        }
        $rateState['count'] = (int)$rateState['count'] + 1;

        $storeId = $this->context->storeId();
        if (!$this->config->isContactCaptureEnabled($storeId)) {
            return [404, ['ok' => false]];
        }

        $email = strtolower(trim((string)($params['email'] ?? '')));
        $rzpOrderId = trim((string)($params['rzp_order_id'] ?? ''));
        if (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return [400, ['ok' => false]];
        }
        if (preg_match('/^order_[A-Za-z0-9]{6,40}$/', $rzpOrderId) !== 1) {
            return [400, ['ok' => false]];
        }
        $quoteId = $this->context->sessionQuoteIdForRzpOrder($rzpOrderId);
        if ($quoteId === null) {
            return [404, ['ok' => false]];
        }

        // Same rule as a dismissed popup: a logged-in customer is reached at the account email.
        $email = $this->context->loggedInEmail() ?: $email;

        try {
            $phone = Phone::normalize(mb_substr((string)($params['phone'] ?? ''), 0, 32));
            $due = gmdate('Y-m-d H:i:s', $now + $this->config->getContactCaptureDelayMinutes($storeId) * 60);
            $this->store->upsert($quoteId, $rzpOrderId, $email, $phone, $storeId, $due);
        } catch (\Throwable $e) {
            $this->logger->error('Bento contact capture store failed', ['error' => $e->getMessage()]);
            return [500, ['ok' => false]];
        }

        return [200, ['ok' => true]];
    }
}
