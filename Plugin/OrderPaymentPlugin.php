<?php

declare(strict_types=1);

namespace OmnisSolutio\PaymentGateway\Plugin;

use Magento\Sales\Model\Order\Payment;
use OmnisSolutio\PaymentGateway\Model\PaymentMethod;

class OrderPaymentPlugin
{
    /**
     * Example plugin — extend as needed for capture/refund interception.
     */
    public function afterCapture(Payment $subject, Payment $result): Payment
    {
        if ($subject->getMethod() !== PaymentMethod::CODE) {
            return $result;
        }

        // Custom post-capture logic goes here.

        return $result;
    }
}
