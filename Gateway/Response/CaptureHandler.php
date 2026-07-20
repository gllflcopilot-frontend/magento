<?php

declare(strict_types=1);

namespace OmnisSolutio\PaymentGateway\Gateway\Response;

use Magento\Payment\Gateway\Helper\SubjectReader;
use Magento\Payment\Gateway\Response\HandlerInterface;

class CaptureHandler implements HandlerInterface
{
    public function handle(array $handlingSubject, array $response): void
    {
        $paymentDO = SubjectReader::readPayment($handlingSubject);
        $payment   = $paymentDO->getPayment();

        if (!isset($response['id'])) {
            return;
        }

        $payment->setTransactionId($response['id']);
        $payment->setIsTransactionClosed(false);
        $payment->setAdditionalInformation('omnissolutio_payment_id', $response['id']);

        if (isset($response['method'])) {
            $payment->setAdditionalInformation('omnissolutio_payment_method', $response['method']);
        }
        if (isset($response['status'])) {
            $payment->setAdditionalInformation('omnissolutio_payment_status', $response['status']);
        }
        if (isset($response['order_id'])) {
            $payment->setAdditionalInformation('omnissolutio_gateway_order_id', $response['order_id']);
        }
    }
}
