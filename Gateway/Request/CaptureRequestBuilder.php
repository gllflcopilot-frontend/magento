<?php

declare(strict_types=1);

namespace OmnisSolutio\PaymentGateway\Gateway\Request;

use Magento\Framework\Exception\LocalizedException;
use Magento\Payment\Gateway\Helper\SubjectReader;
use Magento\Payment\Gateway\Request\BuilderInterface;

class CaptureRequestBuilder implements BuilderInterface
{
    public function build(array $buildSubject): array
    {
        $paymentDO = SubjectReader::readPayment($buildSubject);
        $payment   = $paymentDO->getPayment();
        $paymentId = $payment->getAdditionalInformation('omnissolutio_payment_id');

        if (!$paymentId) {
            throw new LocalizedException(
                __('Omnis Solutio payment ID is missing. Cannot verify payment server-side.')
            );
        }

        return [
            'method'   => 'GET',
            'endpoint' => '/payments/' . $paymentId,
            'body'     => [],
        ];
    }
}
