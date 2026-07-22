<?php

declare(strict_types=1);

namespace OmnisSolutio\PaymentGateway\Gateway\Request;

use Magento\Framework\Exception\LocalizedException;
use Magento\Payment\Gateway\Helper\SubjectReader;
use Magento\Payment\Gateway\Request\BuilderInterface;

/**
 * Builds the refund request (Phase F).
 *
 * Amount is sent in MAJOR units matching the create-order contract
 * (see WP class-gateway.php: "Do NOT convert to minor units").
 */
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
            'endpoint' => '/api/v1/payin/payment/' . $paymentId . '/refund',
            'body'     => [
                'amount' => round((float) $amount, 2), // MAJOR units
                'notes'  => ['reason' => 'Customer refund from Magento'],
            ],
        ];
    }
}
