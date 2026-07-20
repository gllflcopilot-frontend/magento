<?php

declare(strict_types=1);

namespace OmnisSolutio\PaymentGateway\Model\Api;

use OmnisSolutio\PaymentGateway\Model\Config;

class UrlProvider
{
    public function __construct(private readonly Config $config) {}

    public function getBaseUrl(int $storeId = null): string
    {
        return $this->config->getApiEndpoint($storeId);
    }
}
