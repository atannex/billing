<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $prefix = (string) config('billing.table_prefix', 'billing_');

        Schema::create($prefix.'payments', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 64)->unique();
            $table->string('gateway', 40);
            $table->string('method', 20)->nullable();
            $table->string('status', 20)->index();
            $table->unsignedBigInteger('amount');
            $table->char('currency', 3);
            $table->unsignedBigInteger('amount_refunded')->default(0);
            $table->nullableMorphs('payable');
            $table->nullableMorphs('customer');
            $table->string('customer_name')->nullable();
            $table->string('customer_email')->nullable();
            $table->string('customer_phone', 20)->nullable();
            $table->string('description')->nullable();
            $table->string('provider_reference')->nullable()->index();
            $table->string('idempotency_key')->nullable()->unique();
            $table->text('return_url')->nullable();
            $table->text('cancel_url')->nullable();
            $table->json('gateway_data')->nullable();
            $table->json('metadata')->nullable();
            $table->string('failure_code', 80)->nullable();
            $table->string('failure_message')->nullable();
            $table->unsignedInteger('checks')->default(0);
            $table->timestamp('next_check_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'next_check_at']);
        });

        Schema::create($prefix.'refunds', function (Blueprint $table) use ($prefix) {
            $table->id();
            $table->foreignId('payment_id')->constrained($prefix.'payments')->cascadeOnDelete();
            $table->string('reference', 64)->unique();
            $table->string('provider_reference')->nullable()->index();
            $table->unsignedBigInteger('amount');
            $table->char('currency', 3);
            $table->string('status', 20)->index();
            $table->string('reason')->nullable();
            $table->json('gateway_data')->nullable();
            $table->string('failure_message')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamps();
        });

        Schema::create($prefix.'payment_events', function (Blueprint $table) use ($prefix) {
            $table->id();
            $table->foreignId('payment_id')->constrained($prefix.'payments')->cascadeOnDelete();
            $table->string('type', 40);
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20)->nullable();
            $table->string('source', 20);
            $table->json('data')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['payment_id', 'id']);
        });

        Schema::create($prefix.'webhook_calls', function (Blueprint $table) use ($prefix) {
            $table->id();
            $table->string('gateway', 40);
            $table->string('event_id', 191);
            $table->string('event_type', 120)->nullable();
            $table->foreignId('payment_id')->nullable()->constrained($prefix.'payments')->nullOnDelete();
            $table->string('status', 20);
            $table->longText('payload')->nullable();
            $table->json('headers')->nullable();
            $table->text('exception')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['gateway', 'event_id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        $prefix = (string) config('billing.table_prefix', 'billing_');

        Schema::dropIfExists($prefix.'webhook_calls');
        Schema::dropIfExists($prefix.'payment_events');
        Schema::dropIfExists($prefix.'refunds');
        Schema::dropIfExists($prefix.'payments');
    }
};
