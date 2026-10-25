<?php

declare(strict_types=1);

namespace Atannex\Billing\Money;

use InvalidArgumentException;
use JsonSerializable;
use NumberFormatter;
use Stringable;

/**
 * An amount in a currency's smallest unit. Never a float: 5000 XAF is 5000,
 * 12.50 USD is 1250. Major-unit input is parsed as a decimal string, so
 * Money::of('19.99', 'USD') is exactly 1999.
 */
final class Money implements JsonSerializable, Stringable
{
    public readonly string $currency;

    public function __construct(public readonly int $amount, string $currency)
    {
        $this->currency = Currency::normalize($currency);
    }

    /**
     * From major units: Money::of(5000, 'XAF'), Money::of('12.50', 'USD').
     */
    public static function of(int|float|string $amount, string $currency): self
    {
        $decimals = Currency::decimals($currency);

        if (is_int($amount)) {
            return new self($amount * (10 ** $decimals), $currency);
        }

        // Floats are formatted before parsing so 0.1 + 0.2 lands on 0.30.
        $value = is_float($amount) ? number_format($amount, $decimals, '.', '') : trim($amount);

        if (preg_match('/^(-)?(\d*)(?:\.(\d*))?$/', $value, $m) !== 1 || ($m[2] === '' && ($m[3] ?? '') === '')) {
            throw new InvalidArgumentException("[{$amount}] is not a valid amount.");
        }

        $fraction = $m[3] ?? '';

        if (strlen(rtrim($fraction, '0')) > $decimals) {
            throw new InvalidArgumentException("[{$amount}] has more decimals than {$currency} allows ({$decimals}).");
        }

        $minor = (int) (($m[2] === '' ? '0' : $m[2]).str_pad(substr($fraction, 0, $decimals), $decimals, '0'));

        return new self($m[1] === '-' ? -$minor : $minor, $currency);
    }

    /**
     * From minor units: Money::minor(1250, 'USD') is 12.50 USD.
     */
    public static function minor(int $amount, string $currency): self
    {
        return new self($amount, $currency);
    }

    public static function zero(string $currency): self
    {
        return new self(0, $currency);
    }

    public function decimals(): int
    {
        return Currency::decimals($this->currency);
    }

    /**
     * The major-unit amount as a plain decimal string: "12.50", "5000".
     */
    public function decimal(): string
    {
        $decimals = $this->decimals();

        if ($decimals === 0) {
            return (string) $this->amount;
        }

        $digits = str_pad((string) abs($this->amount), $decimals + 1, '0', STR_PAD_LEFT);

        return ($this->amount < 0 ? '-' : '').substr($digits, 0, -$decimals).'.'.substr($digits, -$decimals);
    }

    public function format(?string $locale = null): string
    {
        $locale ??= function_exists('app') && app()->bound('translator') ? app()->getLocale() : 'en';

        if (class_exists(NumberFormatter::class)) {
            $formatter = new NumberFormatter($locale, NumberFormatter::CURRENCY);
            $formatter->setAttribute(NumberFormatter::FRACTION_DIGITS, $this->decimals());
            $formatted = $formatter->formatCurrency((float) $this->decimal(), $this->currency);

            if ($formatted !== false) {
                return $formatted;
            }
        }

        $decimals = $this->decimals();

        return number_format(abs($this->amount) / (10 ** $decimals), $decimals, '.', ' ').' '.$this->currency;
    }

    public function plus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amount + $other->amount, $this->currency);
    }

    public function minus(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amount - $other->amount, $this->currency);
    }

    public function compare(self $other): int
    {
        $this->assertSameCurrency($other);

        return $this->amount <=> $other->amount;
    }

    public function equals(self $other): bool
    {
        return $this->currency === $other->currency && $this->amount === $other->amount;
    }

    public function greaterThan(self $other): bool
    {
        return $this->compare($other) > 0;
    }

    public function lessThan(self $other): bool
    {
        return $this->compare($other) < 0;
    }

    public function isZero(): bool
    {
        return $this->amount === 0;
    }

    public function isPositive(): bool
    {
        return $this->amount > 0;
    }

    /**
     * @return array{amount: int, currency: string, decimal: string}
     */
    public function jsonSerialize(): array
    {
        return ['amount' => $this->amount, 'currency' => $this->currency, 'decimal' => $this->decimal()];
    }

    public function __toString(): string
    {
        return $this->decimal().' '.$this->currency;
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException("Cannot combine {$this->currency} with {$other->currency}.");
        }
    }
}
