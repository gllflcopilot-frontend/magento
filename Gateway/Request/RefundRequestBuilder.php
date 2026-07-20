<?php

declare(strict_types=1);

namespace OmnisSolutio\PaymentGateway\Gateway\Request;

use Magento\Framework\Exception\LocalizedException;
use Magento\Payment\Gateway\Helper\SubjectReader;
use Magento\Payment\Gateway\Request\BuilderInterface;

class RefundRequestBuilder implements BuilderInterface
{
    public function build(array $buildSubject): array
    {
        $paymentDO = SubjectReader::readPayment($buildSubject);
        $amount    = SubjectReader::readAmount($buildSubject);
        $payment   = $paymentDO->getPayment();
        $paymentId = $payment->getAdditionalInformation('omnissolutio_payment_id');

        if (!$paymentId) {
            throw new LocalizedException(
                __('Omnis Solutio payment ID is missing. Cannot issue refund.')
            );
        }

        return [
            'method'   => 'POST',
            'endpoint' => '/payments/' . $paymentId . '/refund',
            'body'     => [
                'amount' => (int) round($amount * 100),
                'notes'  => ['reason' => 'Customer refund from Magento'],
            ],
        ];
    }
}
