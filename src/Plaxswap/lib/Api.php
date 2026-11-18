<?php

declare(strict_types=1);
/**
 * Plaxswap crypto payments for FOSSBilling.
 *
 * @license Apache-2.0
 * SPDX-License-Identifier: Apache-2.0
 */

namespace Plaxswap\FOSSBilling;

final class ApiException extends \RuntimeException
{
    /** A fixed lowercase code (Plaxswap's error code, or one of this adapter's), never free text from a response. */
    public string $apiCode;

    public function __construct(string $code)
    {
        $this->apiCode = preg_match('/^[a-z_]{1,60}$/D', $code) ? $code : 'request_failed';
        parent::__construct('Plaxswap API: ' . $this->apiCode);
    }
}

/**
 * The Plaxswap payment gateway API (https://plaxswap.com/api/v1/openapi.json) with the merchant's secret key.
 * The HTTP client is FOSSBilling's Symfony HttpClient ($di['http_client']); anything with the same
 * request()/getStatusCode()/getContent(false) methods works, which is how the tests replace it.
 */
final class Api
{
    public const LIVE_ORIGIN = 'https://plaxswap.com';
    public const KEY_PATTERN = '/^pxs_(live|test)_[a-z0-9]{10}_[A-Za-z0-9_-]{32,64}$/D';
    public const SECRET_PATTERN = '/^whsec_[A-Za-z0-9_-]{24,100}$/D';
    public const PAYMENT_ID_PATTERN = '/^pay_[a-z0-9]{16}$/D';

    private string $key;
    private string $origin;
    private bool $live;
    private string $userAgent;

    public function __construct(string $key, private object $http, string $userAgent = 'Plaxswap-FOSSBilling')
    {
        if (!preg_match(self::KEY_PATTERN, $key, $match)) {
            throw new ApiException('invalid_api_key');
        }
        $this->key = $key;
        $this->live = $match[1] === 'live';
        $this->origin = self::origin($this->live);
        $this->userAgent = $userAgent;
    }

    /**
     * Live keys always talk to plaxswap.com. A developer can point TEST keys at a Plaxswap copy on the same computer
     * by defining PLAXSWAP_FOSSBILLING_SANDBOX_URL (http://127.0.0.1:port only) in FOSSBilling's config.php.
     */
    public static function origin(bool $live): string
    {
        if (!$live && defined('PLAXSWAP_FOSSBILLING_SANDBOX_URL')) {
            $base = rtrim((string) constant('PLAXSWAP_FOSSBILLING_SANDBOX_URL'), '/');
            if (!preg_match('#^http://127\.0\.0\.1:[0-9]{2,5}$#D', $base)) {
                throw new ApiException('invalid_sandbox_origin');
            }

            return $base;
        }

        return self::LIVE_ORIGIN;
    }

    public function isLive(): bool
    {
        return $this->live;
    }

    public function getOrigin(): string
    {
        return $this->origin;
    }

    /** POST /api/v1/payments. The same Idempotency-Key with the same body returns the same payment. */
    public function createPayment(array $body, string $idempotencyKey): array
    {
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 100) {
            throw new ApiException('invalid_idempotency_key');
        }

        return $this->request('POST', '/api/v1/payments', [], $body, $idempotencyKey);
    }

    /** GET /api/v1/payments/{id}: the payment as it stands now. */
    public function getPayment(string $id): array
    {
        if (!preg_match(self::PAYMENT_ID_PATTERN, $id)) {
            throw new ApiException('invalid_payment_id');
        }

        return $this->request('GET', '/api/v1/payments/' . $id);
    }

    /** GET /api/v1/payments?orderId=: the payments made for one of our references (newest first). */
    public function findPaymentsByOrderId(string $orderId): array
    {
        $list = $this->request('GET', '/api/v1/payments', ['orderId' => $orderId, 'limit' => 10]);
        if (!isset($list['data']) || !is_array($list['data'])) {
            throw new ApiException('invalid_response');
        }

        return array_values(array_filter($list['data'], fn ($row): bool => is_array($row) && ($row['orderId'] ?? null) === $orderId));
    }

    private function request(string $method, string $path, array $query = [], ?array $body = null, string $idempotencyKey = ''): array
    {
        $headers = [
            'Authorization' => 'Bearer ' . $this->key,
            'Accept' => 'application/json',
            'User-Agent' => $this->userAgent,
        ];
        $options = ['headers' => $headers, 'timeout' => 20, 'max_redirects' => 0];
        if ($idempotencyKey !== '') {
            $options['headers']['Idempotency-Key'] = $idempotencyKey;
        }
        if ($body !== null) {
            $options['headers']['Content-Type'] = 'application/json';
            $options['body'] = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }
        $url = $this->origin . $path . ($query ? '?' . http_build_query($query) : '');

        try {
            $response = $this->http->request($method, $url, $options);
            $status = (int) $response->getStatusCode();
            $raw = (string) $response->getContent(false);
        } catch (\Throwable) {
            throw new ApiException('connection_unavailable');
        }
        if (strlen($raw) > 1048576) {
            throw new ApiException('response_too_large');
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new ApiException($status >= 500 ? 'connection_unavailable' : 'invalid_response');
        }
        if ($status < 200 || $status >= 300) {
            throw new ApiException((string) ($data['error']['code'] ?? 'request_failed'));
        }

        return $data;
    }
}
