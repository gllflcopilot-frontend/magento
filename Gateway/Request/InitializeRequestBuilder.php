<?php

declare(strict_types=1);

namespace OmnisSolutio\PaymentGateway\Gateway\Request;

use Magento\Payment\Gateway\Helper\SubjectReader;
use Magento\Payment\Gateway\Request\BuilderInterface;

/**
 * Builds the payin create-order payload (Phase D, step 3 — gateway command path).
 *
 * The resulting array is:
 *   {method, endpoint, body}
 * where body is the PLAIN PHP payload. ApiClient encrypts it (AES+RSA+HMAC) before sending.
 *
 * Amount is sent in MAJOR units (e.g. 100.00 for $100) — NOT minor units.
 * See WP class-gateway.php comment: "Do NOT convert to minor units here."
 */
class InitializeRequestBuilder implements BuilderInterface
{
    public function build(array $buildSubject): array
    {
        $paymentDO = SubjectReader::readPayment($buildSubject);
        $order     = $paymentDO->getOrder();

        $billingAddress = $order->getBillingAddress();

        return [
            'method'   => 'POST',
            'endpoint' => '/api/v1/payin/create/order',
            'body'     => [
                'orderRef'      => 'ORD-' . $order->getOrderIncrementId(),
                'receipt'       => $order->getOrderIncrementId(),
                'amount'        => round($order->getGrandTotalAmount(), 2), // MAJOR units
                'currency'      => strtoupper($order->getCurrencyCode()),
                'orderInfo'     => 'Magento order ' . $order->getOrderIncrementId(),
                'maxAttempts'   => 3,
                'expiryMinutes' => 30,
                'customerRef'   => $order->getCustomerId()
                    ? 'CUST-' . $order->getCustomerId()
                    : 'CUST-GUEST-' . $order->getOrderIncrementId(),
                'firstName'     => (string) ($billingAddress ? $billingAddress->getFirstname() : ''),
                'lastName'      => (string) ($billingAddress ? $billingAddress->getLastname() : ''),
                'email'         => (string) ($billingAddress ? $billingAddress->getEmail() : ''),
                'phone'         => (string) ($billingAddress ? $billingAddress->getTelephone() : ''),
                'addressLine1'  => (string) ($billingAddress ? $billingAddress->getStreetLine1() : ''),
                'addressLine2'  => (string) ($billingAddress ? $billingAddress->getStreetLine2() : ''),
                'city'          => (string) ($billingAddress ? $billingAddress->getCity() : ''),
                'state'         => (string) ($billingAddress ? $billingAddress->getRegionCode() : ''),
                'postalCode'    => (string) ($billingAddress ? $billingAddress->getPostcode() : ''),
                'country'       => (string) ($billingAddress ? $billingAddress->getCountryId() : ''),
                'notes'         => new \stdClass(),
            ],
        ];
    }
}
