<?php

declare(strict_types=1);

namespace OmnisSolutio\PaymentGateway\Gateway\Request;

use Magento\Framework\Exception\LocalizedException;
use Magento\Payment\Gateway\Helper\SubjectReader;
use Magento\Payment\Gateway\Request\BuilderInterface;

/**
 * Cancels an unpaid gateway order (Phase G / modal dismiss).
 * Cancel is a POST with no body — ApiClient sends only X-TenantID header.
 */
class VoidRequestBuilder implements BuilderInterface
{
    public function build(array $buildSubject): array
    {
        $paymentDO      = SubjectReader::readPayment($buildSubject);
        $payment        = $paymentDO->getPayment();
        $gatewayOrderId = $payment->getAdditionalInformation('omnissolutio_gateway_order_id');

        if (!$gatewayOrderId) {
            throw new LocalizedException(
                __('Gateway order token is missing. Cannot void payment.')
            );
        }

        return [
            'method'   => 'POST',
            'endpoint' => '/api/v1/payin/order/' . $gatewayOrderId . '/cancel',
            'body'     => [],
        ];
    }
}
