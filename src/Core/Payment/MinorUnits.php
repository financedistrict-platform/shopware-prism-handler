<?php

declare(strict_types=1);

namespace Fd\PrismPayment\Core\Payment;

final class MinorUnits
{
    private const DEFAULT_EXPONENT = 2;

    private const EXPONENTS = [
        'BIF' => 0, 'CLP' => 0, 'DJF' => 0, 'GNF' => 0, 'ISK' => 0, 'JPY' => 0, 'KMF' => 0, 'KRW' => 0,
        'PYG' => 0, 'RWF' => 0, 'UGX' => 0, 'UYI' => 0, 'VND' => 0, 'VUV' => 0, 'XAF' => 0, 'XOF' => 0,
        'XPF' => 0,
        'BHD' => 3, 'IQD' => 3, 'JOD' => 3, 'KWD' => 3, 'LYD' => 3, 'OMR' => 3, 'TND' => 3,
    ];

    public static function of(string $amount, string $currency): ?int
    {
        $currency = strtoupper($currency);
        if (!is_numeric($amount) || 1 !== preg_match('/^[A-Z]{3}$/', $currency)) {
            return null;
        }

        return (int) round((float) $amount * 10 ** (self::EXPONENTS[$currency] ?? self::DEFAULT_EXPONENT));
    }

    public static function sameMoney(string $amount, string $currency, string $otherAmount, string $otherCurrency): bool
    {
        if (strtoupper($currency) !== strtoupper($otherCurrency)) {
            return false;
        }

        $minor = self::of($amount, $currency);

        return null !== $minor && $minor === self::of($otherAmount, $otherCurrency);
    }
}
