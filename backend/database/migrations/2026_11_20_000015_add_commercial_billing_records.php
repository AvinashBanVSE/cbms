<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commercial_invoices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('subscription_id')->unique()->constrained('commercial_subscriptions');
            $table->unsignedBigInteger('amount_paise');
            $table->date('issued_on');
            $table->date('due_on');
        });
        Schema::create('commercial_payments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('invoice_id')->constrained('commercial_invoices');
            $table->unsignedBigInteger('amount_paise');
            $table->string('receipt_reference', 120);
            $table->timestampTz('recorded_at');
            $table->unique(['invoice_id', 'receipt_reference']);
        });
        Schema::create('commercial_billing_ledger', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('invoice_id')->constrained('commercial_invoices');
            $table->foreignUuid('payment_id')->constrained('commercial_payments');
            $table->foreignUuid('actor_id')->constrained('users');
            $table->string('entry_type');
            $table->bigInteger('delta_paise');
            $table->string('reason', 500)->nullable();
            $table->timestampTz('recorded_at');
            $table->unique(['payment_id', 'entry_type']);
        });
    }

    public function down(): void
    {
        foreach (['commercial_billing_ledger', 'commercial_payments', 'commercial_invoices'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
