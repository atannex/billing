<?php

declare(strict_types=1);

namespace Atannex\Billing\Tests\Unit;

use Atannex\Billing\Enums\PaymentStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class PaymentStatusTest extends TestCase
{
    #[Test]
    public function it_guards_transitions(): void
    {
        $this->assertTrue(PaymentStatus::Pending->canTransitionTo(PaymentStatus::Processing));
        $this->assertTrue(PaymentStatus::Processing->canTransitionTo(PaymentStatus::Succeeded));
        $this->assertTrue(PaymentStatus::Succeeded->canTransitionTo(PaymentStatus::PartiallyRefunded));

        $this->assertFalse(PaymentStatus::Succeeded->canTransitionTo(PaymentStatus::Pending));
        $this->assertFalse(PaymentStatus::Succeeded->canTransitionTo(PaymentStatus::Failed));
        $this->assertFalse(PaymentStatus::Refunded->canTransitionTo(PaymentStatus::Succeeded));
    }

    #[Test]
    public function the_provider_can_overrule_a_local_expiry(): void
    {
        $this->assertTrue(PaymentStatus::Expired->canTransitionTo(PaymentStatus::Succeeded));
        $this->assertTrue(PaymentStatus::Failed->canTransitionTo(PaymentStatus::Succeeded));
        $this->assertFalse(PaymentStatus::Expired->canTransitionTo(PaymentStatus::Processing));
    }

    #[Test]
    public function it_groups_states(): void
    {
        $this->assertTrue(PaymentStatus::RequiresAction->isOpen());
        $this->assertTrue(PaymentStatus::PartiallyRefunded->isPaid());
        $this->assertTrue(PaymentStatus::Expired->isUnsuccessful());
        $this->assertFalse(PaymentStatus::Succeeded->isOpen());
        $this->assertSame(['pending', 'processing', 'requires_action'], PaymentStatus::openValues());
    }
}
