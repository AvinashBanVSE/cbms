<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commercial_subscriptions', function (Blueprint $table): void {
            $table->string('payment_retry_policy')->nullable()->after('billing_reminder_days');
            $table->unsignedSmallInteger('payment_retry_max_attempts')->nullable()->after('payment_retry_policy');
            $table->unsignedSmallInteger('payment_retry_interval_days')->nullable()->after('payment_retry_max_attempts');
        });
        Schema::create('commercial_payment_retries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('invoice_id')->constrained('commercial_invoices');
            $table->unsignedSmallInteger('attempt');
            $table->date('scheduled_on');
            $table->unsignedBigInteger('outstanding_paise');
            $table->string('state');
            $table->foreignUuid('actor_id')->constrained('users');
            $table->timestampTz('recorded_at');
            // Idempotency: an attempt is scheduled at most once per invoice on a business day.
            $table->unique(['invoice_id', 'attempt', 'scheduled_on']);
        });
    }

    public function down(): void
    {
        foreach (['commercial_payment_retries'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('commercial_subscriptions', function (Blueprint $table): void {
            $table->dropColumn(['payment_retry_policy', 'payment_retry_max_attempts', 'payment_retry_interval_days']);
        });
    }
};
