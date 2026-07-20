<?php

declare(strict_types=1);

namespace OmnisSolutio\PaymentGateway\Gateway\Request;

use Magento\Framework\Exception\LocalizedException;
use Magento\Payment\Gateway\Helper\SubjectReader;
use Magento\Payment\Gateway\Request\BuilderInterface;

class VoidRequestBuilder implements BuilderInterface
{
    public function build(array $buildSubject): array
    {
        $paymentDO      = SubjectReader::readPayment($buildSubject);
        $payment        = $paymentDO->getPayment();
        $gatewayOrderId = $payment->getAdditionalInformation('omnissolutio_gateway_order_id');

        if (!$gatewayOrderId) {
            throw new LocalizedException(
                __('Gateway order ID is missing. Cannot void payment.')
            );
        }

        return [
            'method'   => 'POST',
            'endpoint' => '/orders/' . $gatewayOrderId . '/cancel',
            'body'     => [],
        ];
    }
}
