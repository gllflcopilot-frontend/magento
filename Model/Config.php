<?php

declare(strict_types=1);

namespace OmnisSolutio\PaymentGateway\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

class Config
{
    private const XML_PATH_PREFIX = 'payment/omnissolutio_gateway/';

    public const API_ENDPOINT_LIVE    = 'https://api.omnissolutio.com/v1';
    public const API_ENDPOINT_SANDBOX = 'https://sandbox.omnissolutio.com/v1';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {}

    public function isActive(int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_PREFIX . 'active',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function getEnvironment(int $storeId = null): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PATH_PREFIX . 'environment',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function isSandbox(int $storeId = null): bool
    {
        return $this->getEnvironment($storeId) !== 'live';
    }

    public function isDebug(int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_PREFIX . 'debug',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function getKeyId(int $storeId = null): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PATH_PREFIX . 'key_id',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function getKeySecret(int $storeId = null): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PATH_PREFIX . 'key_secret',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function getWebhookSecret(int $storeId = null): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PATH_PREFIX . 'webhook_secret',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function getApiEndpoint(int $storeId = null): string
    {
        return $this->isSandbox($storeId)
            ? self::API_ENDPOINT_SANDBOX
            : self::API_ENDPOINT_LIVE;
    }
}
