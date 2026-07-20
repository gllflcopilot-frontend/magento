<?php

declare(strict_types=1);

namespace OmnisSolutio\PaymentGateway\Model;

use Magento\Framework\Exception\LocalizedException;
use Magento\Payment\Model\Method\AbstractMethod;
use Magento\Quote\Api\Data\CartInterface;

class PaymentMethod extends AbstractMethod
{
    public const CODE = 'omnissolutio_gateway';

    protected $_code = self::CODE;
    protected $_isGateway = true;
    protected $_canCapture = true;
    protected $_canCapturePartial = false;
    protected $_canRefund = true;
    protected $_canRefundInvoicePartial = true;
    protected $_canAuthorize = true;
    protected $_canVoid = true;
    protected $_canUseInternal = false;
    protected $_canUseCheckout = true;

    public function isAvailable(CartInterface $quote = null): bool
    {
        if (!$this->getConfigData('key_id') || !$this->getConfigData('key_secret')) {
            return false;
        }

        return parent::isAvailable($quote);
    }
}
