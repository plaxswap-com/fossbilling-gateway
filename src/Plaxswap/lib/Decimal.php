<?php

declare(strict_types=1);
/**
 * Plaxswap crypto payments for FOSSBilling.
 *
 * @license Apache-2.0
 * SPDX-License-Identifier: Apache-2.0
 */

namespace Plaxswap\FOSSBilling;

/** Exact decimal strings only. These operations never turn money into floats. */
final class Decimal
{
    /** Fiat codes the Plaxswap gateway prices payments in (the same list as the WHMCS module). */
    private const CURRENCIES = 'USD EUR GBP BRL MXN ARS NGN ZAR JPY AED AUD BDT BGN BHD BOB CAD CHF CLP CNY COP CRC CZK DKK DOP EGP GEL GHS GTQ HKD HUF IDR ILS INR ISK JMD KES KRW KWD KZT LKR MAD MYR NOK NZD OMR PEN PHP PKR PLN PYG QAR RON RSD SAR SEK SGD THB TRY TTD TWD TZS UAH UGX UYU VND XAF XOF';

    /** Currencies whose minor unit is not 2 digits (ISO 4217). */
    private const FRACTION_DIGITS = [
        'JPY' => 0, 'KRW' => 0, 'VND' => 0, 'CLP' => 0, 'PYG' => 0, 'UGX' => 0, 'XAF' => 0, 'XOF' => 0, 'ISK' => 0,
        'BHD' => 3, 'KWD' => 3, 'OMR' => 3,
    ];

    public static function normalize($value): string
    {
        if (!is_string($value) || strlen($value) > 80 || !preg_match('/^[0-9]+(?:\.[0-9]+)?$/D', $value)) {
            throw new \InvalidArgumentException('invalid_decimal');
        }

        $parts = explode('.', $value, 2);
        $whole = ltrim($parts[0], '0');
        $fraction = isset($parts[1]) ? rtrim($parts[1], '0') : '';

        return ($whole === '' ? '0' : $whole) . ($fraction === '' ? '' : '.' . $fraction);
    }

    public static function equal($left, $right): bool
    {
        try {
            return self::normalize($left) === self::normalize($right);
        } catch (\InvalidArgumentException) {
            return false;
        }
    }

    public static function positive($value): bool
    {
        try {
            return self::normalize($value) !== '0';
        } catch (\InvalidArgumentException) {
            return false;
        }
    }

    public static function currencySupported(string $currency): bool
    {
        return in_array($currency, explode(' ', self::CURRENCIES), true);
    }

    /** The amount as people read prices: "49.5" USD shows as "49.50", "1200" JPY as "1200". Display only. */
    public static function forDisplay(string $amount, string $currency): string
    {
        $digits = self::FRACTION_DIGITS[$currency] ?? 2;
        $parts = explode('.', self::normalize($amount), 2);
        $fraction = $parts[1] ?? '';

        return $parts[0] . ($digits > 0 || $fraction !== '' ? '.' . str_pad($fraction, $digits, '0') : '');
    }

    /**
     * FOSSBilling keeps invoice totals as floats. This turns one into the exact decimal text Plaxswap expects, rounded
     * to the currency's minor unit, for example 49.5 USD to "49.5" and 1200.0 JPY to "1200".
     */
    public static function fromInvoiceTotal(float $total, string $currency): string
    {
        $digits = self::FRACTION_DIGITS[$currency] ?? 2;
        if (!is_finite($total) || $total < 0) {
            throw new \InvalidArgumentException('invalid_total');
        }

        return self::normalize(number_format(round($total, $digits), $digits, '.', ''));
    }
}
