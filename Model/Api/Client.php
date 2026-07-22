<?php

declare(strict_types=1);

namespace OmnisSolutio\PaymentGateway\Model\Api;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Serialize\Serializer\Json;
use OmnisSolutio\PaymentGateway\Logger\Logger;
use OmnisSolutio\PaymentGateway\Model\Config;
use OmnisSolutio\PaymentGateway\Model\Crypto;

/**
 * Thin HTTP wrapper for the Omnis Solutio payin API.
 *
 * Auth scheme (from WP class-gateway.php / class-crypto.php reference):
 *   - All requests: Content-Type: application/json, X-TenantID: {tenantId}
 *   - POST with body: body is AES-128-GCM encrypted + RSA-wrapped key + HMAC signed
 *   - POST with empty body (cancel): plain empty POST, no encryption
 *   - GET requests: no body, no signing
 *   - NO Authorization / basic-auth header (upstream returns 500 if present)
 *
 * API paths (live: https://api.solutio.com):
 *   POST /api/v1/payin/create/order          → createOrder()
 *   GET  /api/v1/payin/payment/{ref}/status  → fetchPaymentStatus()
 *   POST /api/v1/payin/order/{token}/cancel  → cancelOrder()
 *   POST /api/v1/payin/payment/{ref}/refund  → refund()  [Phase F]
 */
class Client
{
    private const TIMEOUT = 10;

    public function __construct(
        private readonly Config    $config,
        private readonly UrlProvider $urlProvider,
        private readonly Curl      $curl,
        private readonly Json      $json,
        private readonly Crypto    $crypto,
        private readonly Logger    $logger
    ) {}

    /**
     * POST /api/v1/payin/create/order — creates a gateway order before the iframe opens.
     * The full WP-matched payload (orderRef, amount in MAJOR units, customer address, etc.)
     * must be pre-built by the caller (Controller/Order/Create or InitializeRequestBuilder).
     * Pass the order increment ID as $idempotencyKey.
     */
    public function createOrder(array $payload, string $idempotencyKey = '', int $storeId = null): array
    {
        return $this->postEncrypted('/api/v1/payin/create/order', $payload, $idempotencyKey, $storeId);
    }

    /**
     * GET /api/v1/payin/payment/{paymentRef}/status — server-side re-verification.
     * Called from the Callback controller and the capture gateway command.
     */
    public function fetchPaymentStatus(string $paymentRef, int $storeId = null): array
    {
        return $this->get('/api/v1/payin/payment/' . $paymentRef . '/status', $storeId);
    }

    /** Alias used by the Phase B capture flow. */
    public function fetchPayment(string $paymentId, int $storeId = null): array
    {
        return $this->fetchPaymentStatus($paymentId, $storeId);
    }

    /**
     * POST /api/v1/payin/order/{token}/cancel — cancels an unpaid gateway order.
     * Empty body: no encryption needed.
     */
    public function cancelOrder(string $gatewayOrderToken, int $storeId = null): array
    {
        return $this->postEmpty('/api/v1/payin/order/' . $gatewayOrderToken . '/cancel', $storeId);
    }

    /**
     * POST /api/v1/payin/payment/{ref}/refund — partial or full refund (Phase F).
     * Amount in MAJOR units. Pass the credit memo increment ID as $idempotencyKey.
     */
    public function refund(string $paymentRef, array $data, string $idempotencyKey = '', int $storeId = null): array
    {
        return $this->postEncrypted('/api/v1/payin/payment/' . $paymentRef . '/refund', $data, $idempotencyKey, $storeId);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Internals
    // ─────────────────────────────────────────────────────────────────────────

    private function postEncrypted(string $path, array $payload, string $idempotencyKey, ?int $storeId): array
    {
        $url = $this->urlProvider->getBaseUrl($storeId) . $path;

        try {
            $encrypted = $this->crypto->buildEncryptedRequest(
                $payload,
                $this->config->getPgPublicKey($storeId),
                $this->config->getKeySecret($storeId)
            );

            $headers = $this->baseHeaders($storeId);
            if ($idempotencyKey !== '') {
                $headers['Idempotency-Key'] = $idempotencyKey;
            }

            $this->curl->setHeaders($headers);
            $this->curl->setTimeout(self::TIMEOUT);
            $this->curl->post($url, $this->json->serialize($encrypted));

            $httpStatus = $this->curl->getStatus();
            $response   = $this->json->unserialize($this->curl->getBody());
        } catch (\Exception $e) {
            $this->logger->error('OmnisSolutio API POST failed', ['path' => $path, 'error' => $e->getMessage()]);
            throw new LocalizedException(__('Payment gateway is unreachable. Please try again.'), $e);
        }

        if ($this->config->isDebug($storeId)) {
            $this->logger->debug('OmnisSolutio API POST', ['url' => $url, 'status' => $httpStatus]);
        }

        if ($httpStatus >= 400) {
            $msg = is_array($response)
                ? ($response['message'] ?? $response['error']['description'] ?? 'API error')
                : 'API error';
            $this->logger->warning('OmnisSolutio API error', ['path' => $path, 'status' => $httpStatus, 'msg' => $msg]);
            throw new LocalizedException(__('Payment gateway error: %1', $msg));
        }

        return is_array($response) ? $response : [];
    }

    private function get(string $path, ?int $storeId): array
    {
        $url = $this->urlProvider->getBaseUrl($storeId) . $path;

        try {
            $this->curl->setHeaders($this->baseHeaders($storeId));
            $this->curl->setTimeout(self::TIMEOUT);
            $this->curl->get($url);

            $httpStatus = $this->curl->getStatus();
            $response   = $this->json->unserialize($this->curl->getBody());
        } catch (\Exception $e) {
            $this->logger->error('OmnisSolutio API GET failed', ['path' => $path, 'error' => $e->getMessage()]);
            throw new LocalizedException(__('Payment gateway is unreachable. Please try again.'), $e);
        }

        if ($this->config->isDebug($storeId)) {
            $this->logger->debug('OmnisSolutio API GET', ['url' => $url, 'status' => $httpStatus]);
        }

        if ($httpStatus >= 400) {
            $msg = is_array($response) ? ($response['message'] ?? 'API error') : 'API error';
            throw new LocalizedException(__('Payment gateway error: %1', $msg));
        }

        return is_array($response) ? $response : [];
    }

    private function postEmpty(string $path, ?int $storeId): array
    {
        $url = $this->urlProvider->getBaseUrl($storeId) . $path;

        try {
            $this->curl->setHeaders($this->baseHeaders($storeId));
            $this->curl->setTimeout(self::TIMEOUT);
            $this->curl->post($url, '{}');

            $httpStatus = $this->curl->getStatus();
            $response   = $this->json->unserialize($this->curl->getBody());
        } catch (\Exception $e) {
            $this->logger->error('OmnisSolutio API POST (empty) failed', ['path' => $path, 'error' => $e->getMessage()]);
            throw new LocalizedException(__('Payment gateway is unreachable. Please try again.'), $e);
        }

        return is_array($response) ? $response : [];
    }

    private function baseHeaders(?int $storeId): array
    {
        return [
            'Content-Type' => 'application/json',
            'Accept'       => 'application/json',
            'X-TenantID'   => $this->config->getTenantId($storeId),
        ];
    }
}
