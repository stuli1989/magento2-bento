<?php
/**
 * POST /bento/checkout/contact (AJAX, JSON). See ContactCaptureHandler for the rules.
 * PII travels only in the POST body — never in the URL.
 */

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Controller\Checkout;

use ArtLounge\BentoEvents\Model\Checkout\ContactCaptureHandler;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Data\Form\FormKey\Validator as FormKeyValidator;

class Contact implements HttpPostActionInterface, CsrfAwareActionInterface
{
    private const RATE_SESSION_KEY = 'bento_contact_capture_rate';

    public function __construct(
        private readonly RequestInterface $request,
        private readonly JsonFactory $jsonFactory,
        private readonly FormKeyValidator $formKeyValidator,
        private readonly CheckoutSession $checkoutSession,
        private readonly ContactCaptureHandler $handler
    ) {
    }

    public function execute()
    {
        $rate = (array)($this->checkoutSession->getData(self::RATE_SESSION_KEY) ?: []);
        [$code, $body] = $this->handler->handle(
            [
                'email' => $this->request->getParam('email'),
                'phone' => $this->request->getParam('phone'),
                'rzp_order_id' => $this->request->getParam('rzp_order_id'),
            ],
            $this->formKeyValidator->validate($this->request),
            $rate
        );
        $this->checkoutSession->setData(self::RATE_SESSION_KEY, $rate);

        return $this->jsonFactory->create()->setHttpResponseCode($code)->setData($body);
    }

    /** The form key is validated by the handler so failures return JSON, not a redirect. */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}
