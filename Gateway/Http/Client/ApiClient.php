<?php

declare(strict_types=1);

namespace OmnisSolutio\PaymentGateway\Gateway\Http\Client;

use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Payment\Gateway\Http\ClientException;
use Magento\Payment\Gateway\Http\ClientInterface;
use Magento\Payment\Gateway\Http\TransferInterface;
use OmnisSolutio\PaymentGateway\Model\Config;
use OmnisSolutio\PaymentGateway\Model\Crypto;
use Psr\Log\LoggerInterface;

/**
 * Gateway command HTTP transport.
 *
 * Auth scheme (per the WP reference implementation):
 *   - GET  requests: X-TenantID header only (no body, no signing).
 *   - POST with body: X-TenantID + AES-128-GCM encrypted + RSA-wrapped key + HMAC signed
 *                     (via Crypto::buildEncryptedRequest).
 *   - POST with empty body (e.g. cancel): X-TenantID only, no encryption.
 *
 * No basic-auth / Authorization header is ever sent — the upstream Spring gateway
 * returns opaque 500 errors when an Authorization header is present.
 */
class ApiClient implements ClientInterface
{
    public function __construct(
        private readonly Config          $config,
        private readonly Crypto          $crypto,
        private readonly Curl            $curl,
        private readonly Json            $json,
        private readonly LoggerInterface $logger
    ) {}

    public function placeRequest(TransferInterface $transferObject): array
    {
        $uri     = $transferObject->getUri();
        $method  = $transferObject->getMethod();
        $body    = $transferObject->getBody();
        $headers = $transferObject->getHeaders();

        try {
            $this->curl->setHeaders($headers);
            $this->curl->setTimeout(10);

            if ($method === 'GET') {
                $this->curl->get($uri);
            } elseif ($method === 'POST') {
                $postBody = $this->buildPostBody($body);
                $this->curl->post($uri, $postBody);
            } else {
                throw new ClientException(__('Unsupported HTTP method: %1', $method));
            }

            $httpStatus = $this->curl->getStatus();
            $response   = $this->json->unserialize($this->curl->getBody());
        } catch (ClientException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new ClientException(__($e->getMessage()), $e);
        }

        if ($this->config->isDebug()) {
            $this->logger->debug('OmnisSolutio Gateway ApiClient', [
                'method' => $method,
                'uri'    => $uri,
                'status' => $httpStatus,
            ]);
        }

        return is_array($response) ? $response : [];
    }

    private function buildPostBody(array $body): string
    {
        if (empty($body)) {
            return '{}';
        }

        $encrypted = $this->crypto->buildEncryptedRequest(
            $body,
            $this->config->getPgPublicKey(),
            $this->config->getKeySecret()
        );

        return $this->json->serialize($encrypted);
    }
}
