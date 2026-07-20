<?php

declare(strict_types=1);

namespace OmnisSolutio\PaymentGateway\Gateway\Request;

use Magento\Payment\Gateway\Helper\SubjectReader;
use Magento\Payment\Gateway\Request\BuilderInterface;

class InitializeRequestBuilder implements BuilderInterface
{
    public function build(array $buildSubject): array
    {
        $paymentDO = SubjectReader::readPayment($buildSubject);
        $order     = $paymentDO->getOrder();

        return [
            'method'   => 'POST',
            'endpoint' => '/orders',
            'body'     => [
                'amount'   => (int) round($order->getGrandTotalAmount() * 100),
                'currency' => strtolower($order->getCurrencyCode()),
                'receipt'  => $order->getOrderIncrementId(),
            ],
        ];
    }
}
