<?php
/**
 * Integration Test: Cart Recovery Flow
 *
 * Tests the full recovery lifecycle:
 *   1. Token generation (real RecoveryToken + real Json + controllable DateTime)
 *   2. Token parsing & validation (signature, expiry)
 *   3. Recover controller paths (guest restore, customer redirect, invalid token)
 */

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Test\Integration;

use ArtLounge\BentoCore\Api\ConfigInterface;
use ArtLounge\BentoEvents\Controller\Cart\Recover;
use ArtLounge\BentoEvents\Model\RecoveryToken;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Message\ManagerInterface as MessageManager;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Framework\UrlInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class RecoveryFlowTest extends TestCase
{
    private const SECRET_KEY = 'test-secret-key-32-bytes-long!!';
    private const STORE_ID = 1;
    private const QUOTE_ID = 42;
    private const EMAIL = 'Cart@Example.com';
    private const NOW = 1700000000; // fixed timestamp

    private RecoveryToken $token;
    private ConfigInterface $config;
    private StoreManagerInterface $storeManager;

    protected function setUp(): void
    {
        $this->config = $this->createMock(ConfigInterface::class);
        $this->config->method('getSecretKey')->willReturn(self::SECRET_KEY);

        // Real Json stub, controllable DateTime
        $json = new Json();
        $dateTime = $this->createConfiguredMock(DateTime::class, [
            'gmtTimestamp' => self::NOW
        ]);

        $this->token = new RecoveryToken($this->config, $json, $dateTime);

        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn(self::STORE_ID);
        $this->storeManager = $this->createMock(StoreManagerInterface::class);
        $this->storeManager->method('getStore')->willReturn($store);
    }

    // ══════════════════════════════════════════════
    // Part 1: Token generate → parse round-trip
    // ══════════════════════════════════════════════

    public function testGenerateAndParseRoundTrip(): void
    {
        $tokenStr = $this->token->generate(self::QUOTE_ID, self::EMAIL, self::STORE_ID);

        $this->assertNotNull($tokenStr);
        $this->assertStringContainsString('.', $tokenStr, 'Token must have payload.signature format');

        $parsed = $this->token->parse($tokenStr, self::STORE_ID);

        $this->assertSame(self::QUOTE_ID, $parsed['quote_id']);
        $this->assertSame('cart@example.com', $parsed['email']); // normalized
        $this->assertSame(self::STORE_ID, $parsed['store_id']);
    }

    public function testGenerateReturnsNullForInvalidInputs(): void
    {
        $this->assertNull($this->token->generate(0, self::EMAIL, self::STORE_ID));
        $this->assertNull($this->token->generate(self::QUOTE_ID, '', self::STORE_ID));
        $this->assertNull($this->token->generate(self::QUOTE_ID, '  ', self::STORE_ID));
    }

    public function testParseRejectsGarbageToken(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid token format');
        $this->token->parse('not-a-valid-token', self::STORE_ID);
    }

    public function testParseRejectsTamperedSignature(): void
    {
        $tokenStr = $this->token->generate(self::QUOTE_ID, self::EMAIL, self::STORE_ID);
        // Flip the last character of the signature
        $tampered = substr($tokenStr, 0, -1) . (substr($tokenStr, -1) === 'a' ? 'b' : 'a');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid token signature');
        $this->token->parse($tampered, self::STORE_ID);
    }

    public function testParseRejectsExpiredToken(): void
    {
        // Generate token with "now" = 1700000000
        $tokenStr = $this->token->generate(self::QUOTE_ID, self::EMAIL, self::STORE_ID);

        // Create a new RecoveryToken with time far in the future (past TTL)
        $futureDateTime = $this->createConfiguredMock(DateTime::class, [
            'gmtTimestamp' => self::NOW + 700000 // past the 7-day (604800s) TTL
        ]);

        $futureToken = new RecoveryToken($this->config, new Json(), $futureDateTime);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Token has expired');
        $futureToken->parse($tokenStr, self::STORE_ID);
    }

    // ══════════════════════════════════════════════
    // Part 2: Recover controller — guest cart restored
    // ══════════════════════════════════════════════

    public function testRecoverGuestCartSuccess(): void
    {
        $tokenStr = $this->token->generate(self::QUOTE_ID, self::EMAIL, self::STORE_ID);

        $quote = $this->buildQuote(
            quoteId: self::QUOTE_ID,
            email: self::EMAIL,
            active: true,
            itemsCount: 3,
            customerId: null
        );

        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')
            ->willReturnMap([
                ['recover', null, $tokenStr],
                ['autopay', 0, 0],
            ]);

        $redirect = new Redirect();
        $redirectFactory = $this->createMock(RedirectFactory::class);
        $redirectFactory->method('create')->willReturn($redirect);

        $cartRepo = $this->createMock(CartRepositoryInterface::class);
        $cartRepo->method('get')->with(self::QUOTE_ID)->willReturn($quote);

        $checkoutSession = new CheckoutSession();
        $customerSession = new CustomerSession();
        $messageManager = $this->createMock(MessageManager::class);
        $messageManager->expects($this->once())->method('addSuccessMessage');

        $controller = new Recover(
            $request,
            $redirectFactory,
            $cartRepo,
            $this->token,
            $checkoutSession,
            $customerSession,
            $this->createMock(UrlInterface::class),
            $messageManager,
            $this->createMock(LoggerInterface::class),
            $this->storeManager
        );

        $result = $controller->execute();

        $this->assertInstanceOf(Redirect::class, $result);
        $this->assertSame('checkout/cart', $redirect->getPath());
        $this->assertSame(self::QUOTE_ID, $checkoutSession->getQuoteId());
    }

    // ══════════════════════════════════════════════
    // Part 3: Recover controller — customer cart, different user → login redirect
    // ══════════════════════════════════════════════

    public function testRecoverCustomerCartRedirectsToLogin(): void
    {
        $tokenStr = $this->token->generate(self::QUOTE_ID, self::EMAIL, self::STORE_ID);

        $quote = $this->buildQuote(
            quoteId: self::QUOTE_ID,
            email: self::EMAIL,
            active: true,
            itemsCount: 2,
            customerId: 99
        );

        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')
            ->willReturnMap([
                ['recover', null, $tokenStr],
                ['autopay', 0, 0],
            ]);

        $redirect = new Redirect();
        $redirectFactory = $this->createMock(RedirectFactory::class);
        $redirectFactory->method('create')->willReturn($redirect);

        $cartRepo = $this->createMock(CartRepositoryInterface::class);
        $cartRepo->method('get')->willReturn($quote);

        $customerSession = new CustomerSession();
        // Not logged in → should redirect to login
        $messageManager = $this->createMock(MessageManager::class);
        $messageManager->expects($this->once())->method('addNoticeMessage');

        $urlBuilder = $this->createMock(UrlInterface::class);
        $urlBuilder->method('getUrl')->willReturn('https://example.com/bento/cart/recover?token=abc');

        $controller = new Recover(
            $request,
            $redirectFactory,
            $cartRepo,
            $this->token,
            new CheckoutSession(),
            $customerSession,
            $urlBuilder,
            $messageManager,
            $this->createMock(LoggerInterface::class),
            $this->storeManager
        );

        $result = $controller->execute();

        $this->assertSame('customer/account/login', $redirect->getPath());
        // Auth URL should be set on session
        $this->assertNotNull($customerSession->getData('before_auth_url'));
    }

    // ══════════════════════════════════════════════
    // Part 4: Recover controller — inactive cart → notice message
    // ══════════════════════════════════════════════

    public function testRecoverInactiveCartShowsNotice(): void
    {
        $tokenStr = $this->token->generate(self::QUOTE_ID, self::EMAIL, self::STORE_ID);

        $quote = $this->buildQuote(
            quoteId: self::QUOTE_ID,
            email: self::EMAIL,
            active: false,
            itemsCount: 1,
            customerId: null
        );

        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')
            ->willReturnMap([
                ['recover', null, $tokenStr],
                ['autopay', 0, 0],
            ]);

        $redirect = new Redirect();
        $redirectFactory = $this->createMock(RedirectFactory::class);
        $redirectFactory->method('create')->willReturn($redirect);

        $cartRepo = $this->createMock(CartRepositoryInterface::class);
        $cartRepo->method('get')->willReturn($quote);

        $messageManager = $this->createMock(MessageManager::class);
        $messageManager->expects($this->once())->method('addNoticeMessage');

        $controller = new Recover(
            $request,
            $redirectFactory,
            $cartRepo,
            $this->token,
            new CheckoutSession(),
            new CustomerSession(),
            $this->createMock(UrlInterface::class),
            $messageManager,
            $this->createMock(LoggerInterface::class),
            $this->storeManager
        );

        $result = $controller->execute();

        $this->assertSame('checkout/cart', $redirect->getPath());
    }

    // ══════════════════════════════════════════════
    // Part 5: Recover controller — invalid token → error message
    // ══════════════════════════════════════════════

    public function testRecoverInvalidTokenShowsError(): void
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')
            ->willReturnMap([
                ['recover', null, 'garbage.token'],
                ['autopay', 0, 0],
            ]);

        $redirect = new Redirect();
        $redirectFactory = $this->createMock(RedirectFactory::class);
        $redirectFactory->method('create')->willReturn($redirect);

        $messageManager = $this->createMock(MessageManager::class);
        $messageManager->expects($this->once())->method('addErrorMessage');

        $controller = new Recover(
            $request,
            $redirectFactory,
            $this->createMock(CartRepositoryInterface::class),
            $this->token,
            new CheckoutSession(),
            new CustomerSession(),
            $this->createMock(UrlInterface::class),
            $messageManager,
            $this->createMock(LoggerInterface::class),
            $this->storeManager
        );

        $result = $controller->execute();

        $this->assertSame('checkout/cart', $redirect->getPath());
    }

    // ══════════════════════════════════════════════
    // Part 6: Recover controller — no token → simple redirect
    // ══════════════════════════════════════════════

    public function testRecoverNoTokenRedirectsToCart(): void
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')
            ->willReturnMap([
                ['recover', null, null],
                ['autopay', 0, 0],
            ]);

        $redirect = new Redirect();
        $redirectFactory = $this->createMock(RedirectFactory::class);
        $redirectFactory->method('create')->willReturn($redirect);

        $controller = new Recover(
            $request,
            $redirectFactory,
            $this->createMock(CartRepositoryInterface::class),
            $this->token,
            new CheckoutSession(),
            new CustomerSession(),
            $this->createMock(UrlInterface::class),
            $this->createMock(MessageManager::class),
            $this->createMock(LoggerInterface::class),
            $this->storeManager
        );

        $result = $controller->execute();

        $this->assertSame('checkout/cart', $redirect->getPath());
    }

    // ──────────────────────────────────────────────
    // Helper: build a Quote mock with configurable properties
    // ──────────────────────────────────────────────
    private function buildQuote(
        int $quoteId,
        string $email,
        bool $active,
        int $itemsCount,
        ?int $customerId
    ): Quote {
        $quote = $this->createMock(Quote::class);
        $quote->method('getId')->willReturn($quoteId);
        $quote->method('getCustomerEmail')->willReturn($email);
        $quote->method('getIsActive')->willReturn($active);
        $quote->method('getItemsCount')->willReturn($itemsCount);
        $quote->method('getCustomerId')->willReturn($customerId);
        $quote->method('getStoreId')->willReturn(self::STORE_ID);
        return $quote;
    }
}
