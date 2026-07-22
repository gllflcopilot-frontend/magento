<?php

declare(strict_types=1);

namespace OmnisSolutio\PaymentGateway\Model\Ui;

use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Asset\Repository as AssetRepository;
use Magento\Store\Model\ScopeInterface;
use OmnisSolutio\PaymentGateway\Model\Config;
use OmnisSolutio\PaymentGateway\Model\PaymentMethod;

/**
 * Feeds window.checkoutConfig.payment.omnissolutio_gateway.
 *
 * ONLY public, client-safe values are exposed — key_secret, webhook_secret,
 * and the PG public key NEVER leave the server.
 *
 * The checkout app (React SPA) is served from the module's static files
 * (/view/frontend/web/checkout-app/index.html) and loaded in an iframe.
 * URL params forwarded to it exactly mirror what the WP checkout.js sends:
 *   token, amount, currency, baseUrl, tenantId, evTeam, evApp
 */
class ConfigProvider implements ConfigProviderInterface
{
    public function __construct(
        private readonly Config               $config,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly UrlInterface         $url,
        private readonly AssetRepository      $assetRepo
    ) {}

    public function getConfig(): array
    {
        if (!$this->config->isActive()) {
            return [];
        }

        return [
            'payment' => [
                PaymentMethod::CODE => [
                    'title'           => $this->getTitle(),
                    'environment'     => $this->config->getEnvironment(),
                    'isSandbox'       => $this->config->isSandbox(),
                    'baseUrl'         => $this->config->getApiBaseUrl(),
                    'tenantId'        => $this->config->getTenantId(),
                    'evervaultTeamId' => $this->config->getEvervaultTeamId(),
                    'evervaultAppId'  => $this->config->getEvervaultAppId(),
                    'checkoutAppUrl'  => $this->getCheckoutAppUrl(),
                    'createOrderUrl'  => $this->url->getUrl('omnissolutio/order/create'),
                    'callbackUrl'     => $this->url->getUrl('omnissolutio/payment/callback'),
                    'cancelUrl'       => $this->url->getUrl('checkout/cart'),
                ],
            ],
        ];
    }

    private function getCheckoutAppUrl(): string
    {
        return $this->assetRepo->getUrl(
            'OmnisSolutio_PaymentGateway::checkout-app/index.html'
        );
    }

    private function getTitle(): string
    {
        $title = (string) $this->scopeConfig->getValue(
            'payment/' . PaymentMethod::CODE . '/title',
            ScopeInterface::SCOPE_STORE
        );

        return $title !== '' ? $title : 'Omnis Solutio';
    }
}
