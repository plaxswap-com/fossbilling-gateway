<?php

declare(strict_types=1);
/**
 * Plaxswap crypto payments for FOSSBilling.
 *
 * @license Apache-2.0
 * SPDX-License-Identifier: Apache-2.0
 */

namespace Plaxswap\FOSSBilling;

/**
 * Checks the exact webhook bytes Plaxswap sent, as documented at https://plaxswap.com/developers/payments:
 * header x-px-signature = "t=<unix seconds>,v1=<hex HMAC-SHA256 of "t.body">", keyed with the whole whsec_ secret.
 * The header can carry more than one v1 value; one match is enough. Timestamps older than 5 minutes are refused.
 */
final class Signature
{
    public const MAX_BODY_BYTES = 262144;
    public const TOLERANCE_SECONDS = 300;

    /** @param string[] $secrets more than one secret allows a safe secret rotation */
    public static function verify(string $body, string $header, array $secrets, int $now, int $tolerance = self::TOLERANCE_SECONDS): bool
    {
        if ($body === '' || strlen($body) > self::MAX_BODY_BYTES || $header === '' || strlen($header) > 1000
            || $now < 0 || $tolerance < 0 || $tolerance > self::TOLERANCE_SECONDS) {
            return false;
        }

        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            $pair = explode('=', trim($part), 2);
            if (count($pair) !== 2 || $pair[0] === '' || $pair[1] === '') {
                return false;
            }
            if ($pair[0] === 't') {
                if ($timestamp !== null || !preg_match('/^[0-9]{10}$/D', $pair[1])) {
                    return false;
                }
                $timestamp = $pair[1];
            } elseif ($pair[0] === 'v1') {
                if (!preg_match('/^[a-f0-9]{64}$/D', $pair[1])) {
                    return false;
                }
                $signatures[] = $pair[1];
            }
        }

        if ($timestamp === null || abs($now - (int) $timestamp) > $tolerance || !$signatures) {
            return false;
        }

        foreach ($secrets as $secret) {
            if (!is_string($secret) || $secret === '') {
                continue;
            }
            $expected = hash_hmac('sha256', $timestamp . '.' . $body, $secret);
            foreach ($signatures as $signature) {
                if (hash_equals($expected, $signature)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Decode only after verify(). This checks the envelope, never whether anything is paid. */
    public static function decodeEvent(string $body): array
    {
        if ($body === '' || strlen($body) > self::MAX_BODY_BYTES) {
            throw new \UnexpectedValueException('invalid_webhook_body');
        }
        $event = json_decode($body, true, 32, JSON_BIGINT_AS_STRING);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($event)
            || !isset($event['id']) || !is_string($event['id']) || !preg_match('/^evt_[a-z0-9]{16}$/D', $event['id'])
            || !isset($event['event']) || !is_string($event['event'])
            || !in_array($event['event'], ['payment.confirming', 'payment.paid', 'payment.partially_paid', 'payment.expired', 'payment.cancelled', 'payment.failed', 'payment.updated', 'ping'], true)
            || !isset($event['livemode']) || !is_bool($event['livemode'])
            || !self::validDate($event['createdAt'] ?? null)
            || !isset($event['data']) || !is_array($event['data'])) {
            throw new \UnexpectedValueException('invalid_webhook_envelope');
        }
        if ($event['event'] === 'ping') {
            if (($event['data']['ping'] ?? null) !== true || !is_string($event['data']['merchantId'] ?? null)
                || !preg_match('/^mer_(?:[a-z0-9]{12}|[a-z0-9]{16})$/D', $event['data']['merchantId'])) {
                throw new \UnexpectedValueException('invalid_webhook_ping');
            }
        } elseif (!is_string($event['data']['id'] ?? null) || !preg_match('/^pay_[a-z0-9]{16}$/D', $event['data']['id'])) {
            throw new \UnexpectedValueException('invalid_webhook_payment');
        }

        return $event;
    }

    public static function validDate($value): bool
    {
        if (!is_string($value) || !preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]{1,6})?(?:Z|[+-][0-9]{2}:[0-9]{2})$/D', $value)) {
            return false;
        }

        try {
            new \DateTimeImmutable($value);
            $errors = \DateTimeImmutable::getLastErrors();

            return $errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0);
        } catch (\Exception) {
            return false;
        }
    }
}
