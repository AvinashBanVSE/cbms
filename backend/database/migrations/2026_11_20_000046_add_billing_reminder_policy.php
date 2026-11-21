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
            $table->string('billing_reminder_policy')->nullable()->after('renewal_grace_policy');
            $table->unsignedSmallInteger('billing_reminder_days')->nullable()->after('billing_reminder_policy');
        });
        Schema::create('commercial_billing_reminders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('invoice_id')->constrained('commercial_invoices');
            $table->unsignedSmallInteger('days_before');
            $table->date('scheduled_on');
            $table->unsignedBigInteger('outstanding_paise');
            $table->foreignUuid('actor_id')->constrained('users');
            $table->timestampTz('recorded_at');
            // Idempotency: the same reminder for the same invoice on the same business day is recorded once.
            $table->unique(['invoice_id', 'days_before', 'scheduled_on']);
        });
    }

    public function down(): void
    {
        foreach (['commercial_billing_reminders'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('commercial_subscriptions', function (Blueprint $table): void {
            $table->dropColumn(['billing_reminder_policy', 'billing_reminder_days']);
        });
    }
};
