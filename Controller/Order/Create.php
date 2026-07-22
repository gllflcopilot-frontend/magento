<?php

declare(strict_types=1);

namespace OmnisSolutio\PaymentGateway\Controller\Order;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Framework\UrlInterface;
use Magento\Sales\Api\Data\OrderInterface;
use OmnisSolutio\PaymentGateway\Logger\Logger;
use OmnisSolutio\PaymentGateway\Model\Api\Client;
use OmnisSolutio\PaymentGateway\Model\PaymentMethod;

/**
 * Phase D, step 3 — "create gateway order for the current session order".
 *
 * Called by the Knockout renderer AFTER Magento placeOrder succeeds (order lands
 * in pending_payment via the initialize gateway command). Returns the gateway
 * session token + public config so the renderer can open the checkout iframe.
 *
 * If the initialize command already stored the token (can_initialize=1), we
 * return it without a second API call. Otherwise we create the order here and
 * persist it — the endpoint is idempotent in both cases.
 *
 * Payload structure mirrors WP's omnis_ajax_create_session() exactly, including
 * amount in MAJOR units (AED, INR, etc. — NOT minor units / paise / fils).
 */
class Create implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly CheckoutSession  $checkoutSession,
        private readonly JsonFactory      $resultJsonFactory,
        private readonly Client           $client,
        private readonly RemoteAddress    $remoteAddress,
        private readonly UrlInterface     $url,
        private readonly Logger           $logger
    ) {}

    public function execute(): Json
    {
        $result = $this->resultJsonFactory->create();
        $order  = $this->checkoutSession->getLastRealOrder();

        if (!$order->getId()
            || $order->getPayment() === null
            || $order->getPayment()->getMethod() !== PaymentMethod::CODE
        ) {
            return $result->setHttpResponseCode(400)->setData([
                'success' => false,
                'message' => (string) __('No Omnis Solutio order found for this session.'),
            ]);
        }

        try {
            $token = $this->resolveGatewayToken($order);
        } catch (\Throwable $e) {
            $this->logger->error('OmnisSolutio Create controller failed', [
                'order_increment_id' => $order->getIncrementId(),
                'error'              => $e->getMessage(),
            ]);
            return $result->setHttpResponseCode(502)->setData([
                'success' => false,
                'message' => (string) __('Could not start the payment session. Please try again.'),
            ]);
        }

        return $result->setData([
            'success'  => true,
            'token'    => $token,               // → ?token= param in iframe URL
            'amount'   => round((float) $order->getGrandTotal(), 2), // MAJOR units
            'currency' => strtoupper((string) $order->getOrderCurrencyCode()),
        ]);
    }

    private function resolveGatewayToken(OrderInterface $order): string
    {
        $payment  = $order->getPayment();
        $existing = (string) $payment->getAdditionalInformation('omnissolutio_gateway_order_id');

        if ($existing !== '') {
            return $existing;
        }

        $response = $this->client->createOrder(
            $this->buildPayload($order),
            (string) $order->getIncrementId(),
            (int) $order->getStoreId()
        );

        $token = $this->extractToken($response);
        if ($token === '') {
            throw new \RuntimeException('Gateway create-order response did not include a session token.');
        }

        $payment->setAdditionalInformation('omnissolutio_gateway_order_id', $token);
        $payment->save();

        return $token;
    }

    /**
     * Matches WP's omnis_ajax_create_session() payload shape exactly.
     * Amount in MAJOR units (e.g. 100.00 for $100 AED — NOT 10000).
     */
    private function buildPayload(OrderInterface $order): array
    {
        $billing     = $order->getBillingAddress();
        $customerId  = $order->getCustomerId();
        $userAgent   = (string) ($this->request->getHeader('User-Agent') ?? 'Magento');

        return [
            'orderRef'      => 'ORD-' . $order->getIncrementId(),
            'receipt'       => $order->getIncrementId(),
            'amount'        => round((float) $order->getGrandTotal(), 2),
            'currency'      => strtoupper((string) $order->getOrderCurrencyCode()),
            'orderInfo'     => 'Magento order ' . $order->getIncrementId(),
            'maxAttempts'   => 3,
            'expiryMinutes' => 30,
            'customerRef'   => $customerId
                ? 'CUST-' . $customerId
                : 'CUST-GUEST-' . $order->getIncrementId(),
            'firstName'     => $billing ? (string) $billing->getFirstname() : '',
            'lastName'      => $billing ? (string) $billing->getLastname()  : '',
            'email'         => $billing ? (string) $billing->getEmail()      : (string) $order->getCustomerEmail(),
            'phone'         => $billing ? (string) $billing->getTelephone()  : '',
            'addressLine1'  => $billing ? (string) $billing->getStreetLine(1) : '',
            'addressLine2'  => $billing ? (string) $billing->getStreetLine(2) : '',
            'city'          => $billing ? (string) $billing->getCity()       : '',
            'state'         => $billing ? (string) $billing->getRegion()     : '',
            'postalCode'    => $billing ? (string) $billing->getPostcode()   : '',
            'country'       => $billing ? (string) $billing->getCountryId()  : '',
            'ipAddress'     => (string) $this->remoteAddress->getRemoteAddress(),
            'userAgent'     => $userAgent,
            'notes'         => new \stdClass(),
            'callbackUrl'   => $this->url->getUrl('omnissolutio/webhook'),
            'returnUrl'     => $this->url->getUrl('checkout/onepage/success'),
            'cancelUrl'     => $this->url->getUrl('checkout/cart'),
        ];
    }

    /**
     * Mirrors WP's omnis_extract_session_token() — checks token / orderToken /
     * sessionToken at both top-level and under 'data'.
     */
    private function extractToken(array $response): string
    {
        $paths = [
            ['token'], ['orderToken'], ['sessionToken'],
            ['data', 'token'], ['data', 'orderToken'], ['data', 'sessionToken'],
        ];

        foreach ($paths as $path) {
            $value = $response;
            foreach ($path as $key) {
                if (!is_array($value) || !array_key_exists($key, $value)) {
                    $value = null;
                    break;
                }
                $value = $value[$key];
            }
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return '';
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    /**
     * Same-origin, session-scoped — only ever touches the current customer's
     * own just-placed order. Safe to exempt from form-key CSRF.
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}
