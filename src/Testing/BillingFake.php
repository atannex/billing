<?php

declare(strict_types=1);

namespace Atannex\Billing\Testing;

use Atannex\Billing\Enums\PaymentStatus;
use Atannex\Billing\Enums\RefundStatus;
use Atannex\Billing\Models\Payment;
use Atannex\Billing\Models\Refund;
use Atannex\Billing\Processing\PaymentProcessor;
use Closure;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Assert as PHPUnit;

/**
 * Scripted provider behaviour and assertions for tests. Every gateway keeps
 * its configured name, currencies, methods and operators, so routing is
 * exercised for real; only the provider calls are faked.
 *
 *     $fake = Billing::fake()->requireAction();
 *     $payment = Billing::amount(5000)->phone('677123456')->charge();
 *     $fake->settle($payment, PaymentStatus::Succeeded);   // provider confirms later
 *     $fake->assertCharged(fn (Payment $p) => $p->gateway === 'mtn_momo');
 */
class BillingFake
{
    public PaymentStatus $outcome = PaymentStatus::Succeeded;

    /** What the provider reports on later checks; null keeps the current status. */
    public ?PaymentStatus $eventually = null;

    public ?string $failureCode = null;

    public ?string $failureMessage = null;

    public bool $unavailable = false;

    public RefundStatus $refundOutcome = RefundStatus::Succeeded;

    /** @var array<string, PaymentStatus> provider-side status per payment reference */
    public array $provider = [];

    /** @var list<string> references sent to a provider */
    public array $charged = [];

    /** @var list<string> refund references sent to a provider */
    public array $refunded = [];

    public function succeed(): static
    {
        return $this->will(PaymentStatus::Succeeded);
    }

    public function fail(string $code = 'declined', string $message = 'The payment was declined.'): static
    {
        $this->failureCode = $code;
        $this->failureMessage = $message;

        return $this->will(PaymentStatus::Failed);
    }

    /** Payments wait for the payer, like a mobile-money push. */
    public function process(): static
    {
        return $this->will(PaymentStatus::Processing);
    }

    /** Payments return a redirect URL, like a hosted checkout. */
    public function requireAction(): static
    {
        return $this->will(PaymentStatus::RequiresAction);
    }

    /** The provider cannot be reached. */
    public function unavailable(bool $unavailable = true): static
    {
        $this->unavailable = $unavailable;

        return $this;
    }

    public function eventually(?PaymentStatus $status): static
    {
        $this->eventually = $status;

        return $this;
    }

    public function refunds(RefundStatus $status): static
    {
        $this->refundOutcome = $status;

        return $this;
    }

    /**
     * The provider now reports this status; billing checks it like a
     * webhook would and the payment moves through the real processor.
     */
    public function settle(Payment $payment, PaymentStatus $status): Payment
    {
        $this->provider[$payment->reference] = $status;

        return app(PaymentProcessor::class)->refresh($payment, 'webhook');
    }

    // Assertions ---------------------------------------------------------

    /**
     * @param  (Closure(Payment): bool)|null  $callback
     */
    public function assertCharged(?Closure $callback = null): void
    {
        PHPUnit::assertTrue($this->matching($callback)->isNotEmpty(), 'No matching payment was charged.');
    }

    public function assertChargedTimes(int $times, ?Closure $callback = null): void
    {
        $count = $this->matching($callback)->count();

        PHPUnit::assertSame($times, $count, "Expected {$times} matching payments, {$count} were charged.");
    }

    public function assertNotCharged(?Closure $callback = null): void
    {
        PHPUnit::assertTrue($this->matching($callback)->isEmpty(), 'An unexpected payment was charged.');
    }

    public function assertNothingCharged(): void
    {
        PHPUnit::assertSame([], $this->charged, count($this->charged).' payments were charged.');
    }

    /**
     * @param  (Closure(Refund): bool)|null  $callback
     */
    public function assertRefunded(?Closure $callback = null): void
    {
        $refunds = Refund::query()->whereIn('reference', $this->refunded)->get();

        PHPUnit::assertTrue(
            ($callback ? $refunds->filter($callback) : $refunds)->isNotEmpty(),
            'No matching refund was sent.',
        );
    }

    public function assertNothingRefunded(): void
    {
        PHPUnit::assertSame([], $this->refunded, count($this->refunded).' refunds were sent.');
    }

    /**
     * @return Collection<int, Payment>
     */
    private function matching(?Closure $callback): Collection
    {
        $payments = Payment::query()->whereIn('reference', $this->charged)->get()->collect();

        return $callback ? $payments->filter($callback)->values() : $payments;
    }

    private function will(PaymentStatus $status): static
    {
        $this->outcome = $status;

        return $this;
    }
}
