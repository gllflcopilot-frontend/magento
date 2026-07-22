<?php

declare(strict_types=1);

namespace OmnisSolutio\PaymentGateway\Gateway\Response;

use Magento\Payment\Gateway\Helper\SubjectReader;
use Magento\Payment\Gateway\Response\HandlerInterface;
use Magento\Sales\Model\Order;

/**
 * Handles the create-order API response.
 *
 * The gateway returns a session token under various field names
 * (token / orderToken / sessionToken, optionally nested under 'data').
 * This mirrors WP's omnis_extract_session_token() logic.
 *
 * The token is stored as 'omnissolutio_gateway_order_id' — it acts as the
 * gateway's order reference and is forwarded to the checkout iframe as the
 * ?token= URL parameter.
 *
 * Also sets the stateObject so Magento lands the order in pending_payment
 * instead of the default new/processing state.
 */
class InitializeHandler implements HandlerInterface
{
    public function handle(array $handlingSubject, array $response): void
    {
        $paymentDO = SubjectReader::readPayment($handlingSubject);
        $payment   = $paymentDO->getPayment();

        $token = $this->extractToken($response);

        if ($token !== '') {
            $payment->setAdditionalInformation('omnissolutio_gateway_order_id', $token);
        }

        // Set the Magento order state to pending_payment via the stateObject
        // so the order-first flow works correctly.
        $stateObject = $handlingSubject['stateObject'] ?? null;
        if ($stateObject instanceof \Magento\Framework\DataObject) {
            $stateObject->setState(Order::STATE_PENDING_PAYMENT);
            $stateObject->setStatus('pending_payment');
            $stateObject->setIsNotified(false);
        }
    }

    /**
     * Mirrors WP's omnis_extract_session_token() — checks these paths in order:
     *   token, orderToken, sessionToken,
     *   data.token, data.orderToken, data.sessionToken
     */
    private function extractToken(array $response): string
    {
        $paths = [
            ['token'],
            ['orderToken'],
            ['sessionToken'],
            ['data', 'token'],
            ['data', 'orderToken'],
            ['data', 'sessionToken'],
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
}
