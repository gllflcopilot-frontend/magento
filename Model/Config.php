<?php

declare(strict_types=1);

namespace OmnisSolutio\PaymentGateway\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;

class Config
{
    private const XML_PATH_PREFIX = 'payment/omnissolutio_gateway/';

    /** Live API base — from env / WP plugin reference. No trailing slash, no /v1 suffix. */
    public const API_BASE_URL = 'https://api.solutio.com';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor
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

    /** The HMAC signing secret (= VITE_API_SECRET in the WP env file). */
    public function getKeySecret(int $storeId = null): string
    {
        $value = (string) $this->scopeConfig->getValue(
            self::XML_PATH_PREFIX . 'key_secret',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        return $value !== '' ? $this->encryptor->decrypt($value) : '';
    }

    public function getWebhookSecret(int $storeId = null): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PATH_PREFIX . 'webhook_secret',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * X-TenantID header value (= VITE_TENANT_ID).
     * Platform constant; overridable per store.
     */
    public function getTenantId(int $storeId = null): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PATH_PREFIX . 'tenant_id',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * PG RSA public key — bare base64 body or full PEM.
     * Used to wrap the per-request AES key (= VITE_PG_PUBLIC_KEY).
     */
    public function getPgPublicKey(int $storeId = null): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PATH_PREFIX . 'pg_public_key',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /** Evervault team ID — public, goes to the browser via checkoutConfig. */
    public function getEvervaultTeamId(int $storeId = null): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PATH_PREFIX . 'evervault_team_id',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /** Evervault app ID — public, goes to the browser via checkoutConfig. */
    public function getEvervaultAppId(int $storeId = null): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PATH_PREFIX . 'evervault_app_id',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Returns the API base URL. Merchants may override with a custom URL
     * (e.g. for staging); falls back to the official live URL.
     */
    public function getApiBaseUrl(int $storeId = null): string
    {
        $custom = (string) $this->scopeConfig->getValue(
            self::XML_PATH_PREFIX . 'base_url',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        return rtrim($custom !== '' ? $custom : self::API_BASE_URL, '/');
    }

    /** Alias kept for backward-compat with Phase B TransferFactory. */
    public function getApiEndpoint(int $storeId = null): string
    {
        return $this->getApiBaseUrl($storeId);
    }
}
