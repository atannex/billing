<?php

declare(strict_types=1);

namespace Atannex\Billing\Money;

use InvalidArgumentException;

/**
 * Minor-unit sizes for ISO 4217 currencies. Everything not listed has two
 * decimals; config('billing.decimals') adds or overrides codes.
 */
final class Currency
{
    /** @var array<string, int> */
    private const DECIMALS = [
        // zero-decimal
        'BIF' => 0, 'CLP' => 0, 'DJF' => 0, 'GNF' => 0, 'ISK' => 0, 'JPY' => 0,
        'KMF' => 0, 'KRW' => 0, 'PYG' => 0, 'RWF' => 0, 'UGX' => 0, 'UYI' => 0,
        'VND' => 0, 'VUV' => 0, 'XAF' => 0, 'XOF' => 0, 'XPF' => 0,
        // three-decimal
        'BHD' => 3, 'IQD' => 3, 'JOD' => 3, 'KWD' => 3, 'LYD' => 3, 'OMR' => 3, 'TND' => 3,
        // four-decimal
        'CLF' => 4, 'UYW' => 4,
    ];

    /** @var array<string, int> */
    private static array $overrides = [];

    /**
     * @param  array<string, int>  $decimals
     */
    public static function configure(array $decimals): void
    {
        self::$overrides = array_change_key_case(array_map('intval', $decimals), CASE_UPPER);
    }

    public static function normalize(string $code): string
    {
        $code = strtoupper(trim($code));

        if (preg_match('/^[A-Z]{3}$/', $code) !== 1) {
            throw new InvalidArgumentException("[{$code}] is not a three-letter currency code.");
        }

        return $code;
    }

    public static function decimals(string $code): int
    {
        $code = self::normalize($code);

        return self::$overrides[$code] ?? self::DECIMALS[$code] ?? 2;
    }

    public static function isZeroDecimal(string $code): bool
    {
        return self::decimals($code) === 0;
    }
}
