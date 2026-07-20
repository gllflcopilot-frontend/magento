<?php

declare(strict_types=1);

namespace OmnisSolutio\PaymentGateway\Gateway\Response;

use Magento\Payment\Gateway\Helper\SubjectReader;
use Magento\Payment\Gateway\Response\HandlerInterface;

class RefundHandler implements HandlerInterface
{
    public function handle(array $handlingSubject, array $response): void
    {
        $paymentDO = SubjectReader::readPayment($handlingSubject);
        $payment   = $paymentDO->getPayment();

        if (!isset($response['id'])) {
            return;
        }

        $payment->setTransactionId($response['id']);
        $payment->setIsTransactionClosed(true);
        $payment->setShouldCloseParentTransaction(false);
        $payment->setAdditionalInformation('omnissolutio_refund_id', $response['id']);
    }
}
