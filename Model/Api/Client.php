<?php

declare(strict_types=1);

namespace OmnisSolutio\PaymentGateway\Model\Api;

use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Serialize\Serializer\Json;
use OmnisSolutio\PaymentGateway\Model\Config;
use Psr\Log\LoggerInterface;

class Client
{
    public function __construct(
        private readonly Config          $config,
        private readonly Curl            $curl,
        private readonly Json            $json,
        private readonly LoggerInterface $logger
    ) {}

    public function charge(array $payload, int $storeId = null): array
    {
        return $this->request('POST', '/charges', $payload, $storeId);
    }

    public function capture(string $chargeId, array $payload = [], int $storeId = null): array
    {
        return $this->request('POST', "/charges/{$chargeId}/capture", $payload, $storeId);
    }

    public function refund(string $chargeId, array $payload = [], int $storeId = null): array
    {
        return $this->request('POST', "/charges/{$chargeId}/refund", $payload, $storeId);
    }

    private function request(string $method, string $path, array $payload, int $storeId = null): array
    {
        $url = $this->config->getApiEndpoint($storeId) . $path;
        $body = $this->json->serialize($payload);

        $this->curl->setHeaders([
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer ' . $this->config->getKeyId($storeId),
            'Accept'        => 'application/json',
        ]);

        if ($method === 'POST') {
            $this->curl->post($url, $body);
        } else {
            $this->curl->get($url);
        }

        $response = $this->json->unserialize($this->curl->getBody());

        if ($this->config->isDebug($storeId)) {
            $this->logger->debug('OmnisSolutio API', [
                'method'   => $method,
                'url'      => $url,
                'payload'  => $payload,
                'response' => $response,
            ]);
        }

        return $response;
    }
}
