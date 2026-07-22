<?php

declare(strict_types=1);

namespace OmnisSolutio\PaymentGateway\Model;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Serialize\Serializer\Json;

/**
 * PHP port of the WP plugin's class-crypto.php
 * (which itself ports /crypto/{aesUtil,rsaUtil,hmacUtil,merchantService}.ts).
 *
 * Produces the hybrid-encrypted request body required by the Omnis Solutio
 * payin API for all POST calls that carry a payload:
 *   1. AES-128-GCM encrypts the JSON payload   → base64(IV‖ciphertext‖tag)
 *   2. RSA/PKCS1v1.5 wraps base64(aesKey)      → base64
 *   3. HMAC-SHA256 signs the canonical string   → base64
 *
 * The HMAC signed-path is intentionally different from the POST URL — this
 * matches the TS reference (merchantService.ts / class-crypto.php comment).
 */
class Crypto
{
    /** Canonical path used inside the HMAC stringToSign — never changes. */
    public const SIGNED_PATH = '/api/v1/payin/order';

    public function __construct(private readonly Json $json) {}

    /**
     * Build the full encrypted request body array ready to JSON-encode and POST.
     *
     * @param array  $payload      Plain PHP array (will be JSON-encoded internally)
     * @param string $pgPublicPem  PG RSA public key, full PEM or bare base64 body
     * @param string $apiSecret    HMAC signing secret (base64-encoded or raw)
     * @return array{data:string, encryptedKey:string, signature:string, timestamp:string, nonce:string}
     * @throws LocalizedException
     */
    public function buildEncryptedRequest(array $payload, string $pgPublicPem, string $apiSecret): array
    {
        $pem = $this->normalizePem($pgPublicPem);

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new LocalizedException(__('Failed to JSON-encode payment payload.'));
        }

        $aesKey      = random_bytes(16);
        $data        = $this->aesEncrypt($json, $aesKey);
        $encryptedKey = $this->rsaEncryptKeyPkcs1($aesKey, $pem);

        $timestamp   = (string) time();
        $nonce       = $this->uuidV4();
        $stringToSign = "POST\n" . self::SIGNED_PATH . "\n" . $timestamp . "\n" . $nonce . "\n" . $data;
        $signature   = base64_encode(hash_hmac('sha256', $stringToSign, $apiSecret, true));

        return [
            'data'         => $data,
            'encryptedKey' => $encryptedKey,
            'signature'    => $signature,
            'timestamp'    => $timestamp,
            'nonce'        => $nonce,
        ];
    }

    /**
     * AES-128-GCM encrypt. Mirrors AesUtil.encrypt().
     * Returns base64(IV[12] ‖ ciphertext ‖ authTag[16]).
     */
    private function aesEncrypt(string $plaintext, string $aesKey): string
    {
        $iv  = random_bytes(12);
        $tag = '';
        $ct  = openssl_encrypt($plaintext, 'aes-128-gcm', $aesKey, OPENSSL_RAW_DATA, $iv, $tag, '', 16);

        if ($ct === false) {
            throw new LocalizedException(__('AES-GCM encryption failed: %1', openssl_error_string()));
        }

        return base64_encode($iv . $ct . $tag);
    }

    /**
     * RSA/PKCS1v1.5-encrypt the base64-encoded AES key.
     * Mirrors RsaUtil.encryptKeyPkcs1() — Java side wraps base64(aesKey.getEncoded()).
     */
    private function rsaEncryptKeyPkcs1(string $aesKey, string $pem): string
    {
        $pubKey = openssl_pkey_get_public($pem);
        if ($pubKey === false) {
            throw new LocalizedException(__('Invalid PG public key PEM: %1', openssl_error_string()));
        }

        $encrypted = '';
        $ok = openssl_public_encrypt(base64_encode($aesKey), $encrypted, $pubKey, OPENSSL_PKCS1_PADDING);
        if (!$ok) {
            throw new LocalizedException(__('RSA key wrapping failed: %1', openssl_error_string()));
        }

        return base64_encode($encrypted);
    }

    /**
     * Accept a PEM block or bare base64 body (with or without whitespace)
     * and return a well-formed PEM — same logic as omnis_normalize_pem() in WP.
     */
    public function normalizePem(string $input): string
    {
        $input = trim($input);
        if ($input === '') {
            throw new LocalizedException(__('PG public key is empty.'));
        }

        if (str_contains($input, '-----BEGIN')) {
            return $input;
        }

        $body    = preg_replace('/\s+/', '', $input);
        $wrapped = chunk_split($body, 64, "\n");

        return "-----BEGIN PUBLIC KEY-----\n" . $wrapped . "-----END PUBLIC KEY-----";
    }

    private function uuidV4(): string
    {
        $data    = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
