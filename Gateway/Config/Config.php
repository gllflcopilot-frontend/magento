<?php

declare(strict_types=1);

namespace OmnisSolutio\PaymentGateway\Gateway\Config;

use Magento\Payment\Gateway\Config\Config as GatewayConfig;

class Config extends GatewayConfig
{
    public const METHOD_CODE = 'omnissolutio_gateway';
}
