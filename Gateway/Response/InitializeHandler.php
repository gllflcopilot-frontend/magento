<?php

declare(strict_types=1);

namespace OmnisSolutio\PaymentGateway\Gateway\Response;

use Magento\Payment\Gateway\Helper\SubjectReader;
use Magento\Payment\Gateway\Response\HandlerInterface;

class InitializeHandler implements HandlerInterface
{
    public function handle(array $handlingSubject, array $response): void
    {
        $paymentDO = SubjectReader::readPayment($handlingSubject);
        $payment   = $paymentDO->getPayment();

        if (isset($response['id'])) {
            $payment->setAdditionalInformation('omnissolutio_gateway_order_id', $response['id']);
        }
    }
}
