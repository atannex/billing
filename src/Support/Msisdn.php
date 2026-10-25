<?php

declare(strict_types=1);

namespace Atannex\Billing\Support;

use Atannex\Billing\Exceptions\InvalidMsisdn;
use Stringable;

/**
 * A mobile number in international form, with its operator when the
 * country's prefixes are known. "677 12 34 56", "+237677123456" and
 * "00237 677-12-34-56" all become 237677123456 (MTN).
 */
final class Msisdn implements Stringable
{
    /** @var array<string, array{code: string, length: int}> */
    private static array $codes = [];

    /** @var array<string, array<string, list<string>>> */
    private static array $operators = [];

    private function __construct(
        public readonly string $country,
        public readonly string $callingCode,
        public readonly string $national,
    ) {}

    /**
     * @param  array<string, array{code: string, length: int}>  $codes
     * @param  array<string, array<string, list<string>>>  $operators
     */
    public static function configure(array $codes, array $operators): void
    {
        self::$codes = array_change_key_case($codes, CASE_UPPER);
        self::$operators = array_change_key_case($operators, CASE_UPPER);
    }

    public static function parse(string $number, string $country = 'CM'): self
    {
        $country = strtoupper($country);
        $digits = preg_replace('/\D+/', '', $number) ?? '';
        $international = str_starts_with(trim($number), '+') || str_starts_with($digits, '00');
        $digits = str_starts_with($digits, '00') ? substr($digits, 2) : $digits;

        // An international number names its own country.
        if ($international || ! self::fits($digits, $country)) {
            foreach (self::$codes as $iso => $spec) {
                if (str_starts_with($digits, $spec['code']) && strlen($digits) === strlen($spec['code']) + $spec['length']) {
                    return new self($iso, $spec['code'], substr($digits, strlen($spec['code'])));
                }
            }

            if ($international) {
                throw InvalidMsisdn::for($number);
            }
        }

        $spec = self::$codes[$country] ?? throw InvalidMsisdn::for($number, "no calling code configured for {$country}");
        $national = ltrim($digits, '0') === $digits || strlen($digits) === $spec['length'] ? $digits : substr($digits, 1);

        if (strlen($national) !== $spec['length']) {
            throw InvalidMsisdn::for($number);
        }

        return new self($country, $spec['code'], $national);
    }

    public static function tryParse(?string $number, string $country = 'CM'): ?self
    {
        if ($number === null || trim($number) === '') {
            return null;
        }

        try {
            return self::parse($number, $country);
        } catch (InvalidMsisdn) {
            return null;
        }
    }

    /**
     * The operator whose prefix matches, longest first: "mtn", "orange", …
     */
    public function operator(): ?string
    {
        $match = null;
        $length = 0;

        foreach (self::$operators[$this->country] ?? [] as $operator => $prefixes) {
            foreach ($prefixes as $prefix) {
                $prefix = (string) $prefix;

                if (strlen($prefix) > $length && str_starts_with($this->national, $prefix)) {
                    [$match, $length] = [(string) $operator, strlen($prefix)];
                }
            }
        }

        return $match;
    }

    public function is(string $operator): bool
    {
        return $this->operator() === strtolower($operator);
    }

    /** 237677123456 — the form MTN and Orange expect. */
    public function international(): string
    {
        return $this->callingCode.$this->national;
    }

    /** +237677123456 */
    public function e164(): string
    {
        return '+'.$this->international();
    }

    public function __toString(): string
    {
        return $this->international();
    }

    private static function fits(string $digits, string $country): bool
    {
        $spec = self::$codes[$country] ?? null;

        return $spec !== null && (strlen($digits) === $spec['length'] || (strlen($digits) === $spec['length'] + 1 && $digits[0] === '0'));
    }
}
