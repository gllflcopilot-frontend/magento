<?php

declare(strict_types=1);

namespace OmnisSolutio\PaymentGateway\Model\Api;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Serialize\Serializer\Json;
use OmnisSolutio\PaymentGateway\Logger\Logger;
use OmnisSolutio\PaymentGateway\Model\Config;

class Client
{
    private const TIMEOUT = 10;

    public function __construct(
        private readonly Config      $config,
        private readonly UrlProvider $urlProvider,
        private readonly Curl        $curl,
        private readonly Json        $json,
        private readonly Logger      $logger
    ) {}

    /**
     * POST /orders — creates a gateway order before the iframe opens.
     * Pass the quote/order increment ID as $idempotencyKey.
     */
    public function createOrder(array $data, string $idempotencyKey = '', int $storeId = null): array
    {
        return $this->request('POST', '/orders', $data, $idempotencyKey, $storeId);
    }

    /**
     * GET /payments/{id} — server-side re-verification after iframe completes.
     */
    public function fetchPayment(string $paymentId, int $storeId = null): array
    {
        return $this->request('GET', '/payments/' . $paymentId, [], '', $storeId);
    }

    /**
     * POST /payments/{id}/refund — partial or full refund.
     * Pass the credit memo increment ID as $idempotencyKey.
     */
    public function refund(string $paymentId, array $data, string $idempotencyKey = '', int $storeId = null): array
    {
        return $this->request('POST', '/payments/' . $paymentId . '/refund', $data, $idempotencyKey, $storeId);
    }

    /**
     * POST /orders/{id}/cancel — cancels an unpaid gateway order.
     */
    public function cancelOrder(string $gatewayOrderId, int $storeId = null): array
    {
        return $this->request('POST', '/orders/' . $gatewayOrderId . '/cancel', [], '', $storeId);
    }

    private function request(string $method, string $path, array $body, string $idempotencyKey, ?int $storeId): array
    {
        $url = $this->urlProvider->getBaseUrl($storeId) . $path;

        $headers = ['Content-Type' => 'application/json', 'Accept' => 'application/json'];
        if ($idempotencyKey !== '') {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        try {
            $this->curl->setHeaders($headers);
            $this->curl->setCredentials(
                $this->config->getKeyId($storeId),
                $this->config->getKeySecret($storeId)
            );
            $this->curl->setTimeout(self::TIMEOUT);

            if ($method === 'GET') {
                $this->curl->get($url);
            } else {
                $this->curl->post($url, $this->json->serialize($body));
            }

            $httpStatus = $this->curl->getStatus();
            $response   = $this->json->unserialize($this->curl->getBody());
        } catch (\Exception $e) {
            $this->logger->error('OmnisSolutio API connection failed', [
                'method' => $method,
                'path'   => $path,
                'error'  => $e->getMessage(),
            ]);
            throw new LocalizedException(__('Payment gateway is unreachable. Please try again.'), $e);
        }

        if ($this->config->isDebug($storeId)) {
            $this->logger->debug('OmnisSolutio API', [
                'method'   => $method,
                'url'      => $url,
                'status'   => $httpStatus,
                'body'     => $body,
                'response' => $response,
            ]);
        }

        if ($httpStatus >= 400) {
            $errorMessage = is_array($response)
                ? ($response['error']['description'] ?? $response['message'] ?? 'API error')
                : 'API error';
            $this->logger->warning('OmnisSolutio API error response', [
                'method' => $method,
                'path'   => $path,
                'status' => $httpStatus,
                'error'  => $errorMessage,
            ]);
            throw new LocalizedException(__('Payment gateway error: %1', $errorMessage));
        }

        return is_array($response) ? $response : [];
    }
}
