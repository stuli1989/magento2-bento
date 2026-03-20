<?php
/**
 * Cart Recovery Controller Unit Test
 */

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Test\Unit\Controller\Cart;

use ArtLounge\BentoEvents\Controller\Cart\Recover;
use ArtLounge\BentoEvents\Model\RecoveryToken;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Message\ManagerInterface as MessageManager;
use Magento\Framework\UrlInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\Data\CartInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class RecoverTest extends TestCase
{
    public function testExecuteRedirectsWhenMissingToken(): void
    {
        $mocks = $this->createRecoverMocks();
        $this->mockRequestParams($mocks['request'], []);
        $mocks['redirect']->expects($this->once())->method('setPath')->with('checkout/cart')->willReturn($mocks['redirect']);

        $controller = $this->createRecoverController($mocks);
        $controller->execute();
    }

    public function testExecuteRejectsInvalidToken(): void
    {
        $mocks = $this->createRecoverMocks();
        $this->mockRequestParams($mocks['request'], ['recover' => 'bad-token']);
        $mocks['recoveryToken']->method('parse')->with('bad-token')->willThrowException(new \InvalidArgumentException('Invalid token'));
        $mocks['messageManager']->expects($this->once())->method('addErrorMessage');

        $controller = $this->createRecoverController($mocks);
        $controller->execute();
    }

    public function testExecuteRestoresGuestCart(): void
    {
        $mocks = $this->createRecoverMocks();
        $this->mockRequestParams($mocks['request'], ['recover' => 'signed-token', 'autopay' => 1]);
        $mocks['recoveryToken']->method('parse')->with('signed-token')->willReturn([
            'quote_id' => 10,
            'email' => 'test@example.com',
            'store_id' => 1
        ]);

        $quote = $this->createMock(CartInterface::class);
        $quote->method('getCustomerEmail')->willReturn('test@example.com');
        $quote->method('getIsActive')->willReturn(true);
        $quote->method('getItemsCount')->willReturn(1);
        $quote->method('getCustomerId')->willReturn(null);
        $mocks['cartRepository']->method('get')->with(10)->willReturn($quote);

        $mocks['checkoutSession']->expects($this->once())->method('setQuoteId')->with(10);
        $mocks['messageManager']->expects($this->once())->method('addSuccessMessage');
        $mocks['redirect']->expects($this->once())
            ->method('setPath')
            ->with('checkout/cart', ['_query' => ['bento_recovered' => 1, 'autopay' => 1]])
            ->willReturn($mocks['redirect']);

        $controller = $this->createRecoverController($mocks);
        $controller->execute();
    }

    public function testExecuteRejectsEmailMismatch(): void
    {
        $mocks = $this->createRecoverMocks();
        $this->mockRequestParams($mocks['request'], ['recover' => 'signed-token']);
        $mocks['recoveryToken']->method('parse')->willReturn([
            'quote_id' => 10,
            'email' => 'test@example.com',
            'store_id' => 1
        ]);

        $quote = $this->createMock(CartInterface::class);
        $quote->method('getCustomerEmail')->willReturn('other@example.com');
        $mocks['cartRepository']->method('get')->with(10)->willReturn($quote);
        $mocks['messageManager']->expects($this->once())->method('addErrorMessage');

        $controller = $this->createRecoverController($mocks);
        $controller->execute();
    }

    public function testExecuteHandlesInactiveQuote(): void
    {
        $mocks = $this->createRecoverMocks();
        $this->mockRequestParams($mocks['request'], ['recover' => 'signed-token']);
        $mocks['recoveryToken']->method('parse')->willReturn([
            'quote_id' => 10,
            'email' => 'test@example.com',
            'store_id' => 1
        ]);

        $quote = $this->createMock(CartInterface::class);
        $quote->method('getCustomerEmail')->willReturn('test@example.com');
        $quote->method('getIsActive')->willReturn(false);
        $mocks['cartRepository']->method('get')->with(10)->willReturn($quote);
        $mocks['messageManager']->expects($this->once())->method('addNoticeMessage');

        $controller = $this->createRecoverController($mocks);
        $controller->execute();
    }

    public function testExecuteHandlesEmptyQuote(): void
    {
        $mocks = $this->createRecoverMocks();
        $this->mockRequestParams($mocks['request'], ['recover' => 'signed-token']);
        $mocks['recoveryToken']->method('parse')->willReturn([
            'quote_id' => 10,
            'email' => 'test@example.com',
            'store_id' => 1
        ]);

        $quote = $this->createMock(CartInterface::class);
        $quote->method('getCustomerEmail')->willReturn('test@example.com');
        $quote->method('getIsActive')->willReturn(true);
        $quote->method('getItemsCount')->willReturn(0);
        $mocks['cartRepository']->method('get')->with(10)->willReturn($quote);
        $mocks['messageManager']->expects($this->once())->method('addNoticeMessage');

        $controller = $this->createRecoverController($mocks);
        $controller->execute();
    }

    public function testExecuteRestoresCustomerCartWhenSameCustomerLoggedIn(): void
    {
        $mocks = $this->createRecoverMocks();
        $this->mockRequestParams($mocks['request'], ['recover' => 'signed-token']);
        $mocks['recoveryToken']->method('parse')->willReturn([
            'quote_id' => 10,
            'email' => 'test@example.com',
            'store_id' => 1
        ]);

        $quote = $this->createMock(CartInterface::class);
        $quote->method('getCustomerEmail')->willReturn('test@example.com');
        $quote->method('getIsActive')->willReturn(true);
        $quote->method('getItemsCount')->willReturn(2);
        $quote->method('getCustomerId')->willReturn(42);
        $mocks['cartRepository']->method('get')->with(10)->willReturn($quote);

        $mocks['customerSession']->method('isLoggedIn')->willReturn(true);
        $mocks['customerSession']->method('getCustomerId')->willReturn(42);
        $mocks['checkoutSession']->expects($this->once())->method('setQuoteId')->with(10);
        $mocks['messageManager']->expects($this->once())->method('addSuccessMessage');
        $mocks['redirect']->expects($this->once())
            ->method('setPath')
            ->with('checkout/cart', ['_query' => ['bento_recovered' => 1]])
            ->willReturn($mocks['redirect']);

        $controller = $this->createRecoverController($mocks);
        $controller->execute();
    }

    public function testExecuteRedirectsToLoginWhenDifferentCustomer(): void
    {
        $mocks = $this->createRecoverMocks();
        $this->mockRequestParams($mocks['request'], ['recover' => 'signed-token', 'autopay' => 1]);
        $mocks['recoveryToken']->method('parse')->willReturn([
            'quote_id' => 10,
            'email' => 'test@example.com',
            'store_id' => 1
        ]);

        $quote = $this->createMock(CartInterface::class);
        $quote->method('getCustomerEmail')->willReturn('test@example.com');
        $quote->method('getIsActive')->willReturn(true);
        $quote->method('getItemsCount')->willReturn(2);
        $quote->method('getCustomerId')->willReturn(42);
        $mocks['cartRepository']->method('get')->with(10)->willReturn($quote);

        $mocks['customerSession']->method('isLoggedIn')->willReturn(true);
        $mocks['customerSession']->method('getCustomerId')->willReturn(99);
        $mocks['urlBuilder']->expects($this->once())
            ->method('getUrl')
            ->with('bento/cart/recover', $this->arrayHasKey('recover'))
            ->willReturn('https://example.com/bento/cart/recover?recover=signed-token&autopay=1');
        $mocks['messageManager']->expects($this->once())->method('addNoticeMessage');
        $mocks['redirect']->expects($this->once())
            ->method('setPath')
            ->with('customer/account/login')
            ->willReturn($mocks['redirect']);

        $controller = $this->createRecoverController($mocks);
        $controller->execute();
    }

    public function testExecuteHandlesCartRepositoryException(): void
    {
        $mocks = $this->createRecoverMocks();
        $this->mockRequestParams($mocks['request'], ['recover' => 'signed-token']);
        $mocks['recoveryToken']->method('parse')->willReturn([
            'quote_id' => 10,
            'email' => 'test@example.com',
            'store_id' => 1
        ]);
        $mocks['cartRepository']->method('get')->willThrowException(new \Exception('Quote not found'));
        $mocks['messageManager']->expects($this->once())->method('addErrorMessage');
        $mocks['logger']->expects($this->once())->method('error');

        $controller = $this->createRecoverController($mocks);
        $controller->execute();
    }

    private function createRecoverMocks(): array
    {
        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturn($redirect);
        $redirectFactory = $this->createMock(RedirectFactory::class);
        $redirectFactory->method('create')->willReturn($redirect);
        $customerSession = $this->getMockBuilder(CustomerSession::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['isLoggedIn', 'getCustomerId'])
            ->addMethods(['setBeforeAuthUrl', 'setAfterAuthUrl'])
            ->getMock();

        return [
            'request' => $this->createMock(RequestInterface::class),
            'redirectFactory' => $redirectFactory,
            'redirect' => $redirect,
            'cartRepository' => $this->createMock(CartRepositoryInterface::class),
            'recoveryToken' => $this->createMock(RecoveryToken::class),
            'checkoutSession' => $this->createMock(CheckoutSession::class),
            'customerSession' => $customerSession,
            'urlBuilder' => $this->createMock(UrlInterface::class),
            'messageManager' => $this->createMock(MessageManager::class),
            'logger' => $this->createMock(LoggerInterface::class),
        ];
    }

    private function createRecoverController(array $mocks): Recover
    {
        return new Recover(
            $mocks['request'],
            $mocks['redirectFactory'],
            $mocks['cartRepository'],
            $mocks['recoveryToken'],
            $mocks['checkoutSession'],
            $mocks['customerSession'],
            $mocks['urlBuilder'],
            $mocks['messageManager'],
            $mocks['logger']
        );
    }

    private function mockRequestParams(MockObject $request, array $params): void
    {
        $request->method('getParam')->willReturnCallback(
            static function (string $key, $default = null) use ($params) {
                return $params[$key] ?? $default;
            }
        );
    }
}
