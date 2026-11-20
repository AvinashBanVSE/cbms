<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A Usage Limit is a measured, enforceable bound published on a Plan/Add-on Version
        // (§14.5). It resolves against the same version snapshots that resolve quantities.
        Schema::create('commercial_usage_limits', function (Blueprint $table): void {
            $table->string('code', 80)->primary();
            $table->string('name', 150);
            $table->string('resource');
            $table->string('unit');
            $table->string('aggregation');
            $table->string('period');
            $table->string('scope');
            $table->unsignedBigInteger('limit');
            $table->unsignedBigInteger('warning_threshold');
            $table->string('enforcement');
            $table->string('overage');
            $table->string('reset');
            $table->unsignedBigInteger('carry_forward')->nullable();
        });

        // One measurement window per covered business per limit, advanced by period.
        Schema::create('commercial_usage_windows', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('usage_limit_code', 80);
            $table->foreignUuid('business_id')->constrained('business_identities');
            $table->date('period_start');
            $table->date('period_end');
            $table->unsignedBigInteger('consumed')->default(0);
            $table->timestampTz('updated_at');
            $table->unique(['usage_limit_code', 'business_id', 'period_start'], 'commercial_usage_window_period');
        });

        // Append-only usage events; each carries a client-supplied key for idempotent recording.
        Schema::create('commercial_usage_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('usage_limit_code', 80);
            $table->foreignUuid('business_id')->constrained('business_identities');
            $table->uuid('idempotency_key');
            $table->unsignedBigInteger('quantity');
            $table->timestampTz('recorded_at');
            $table->unique(['usage_limit_code', 'business_id', 'idempotency_key'], 'commercial_usage_event_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commercial_usage_events');
        Schema::dropIfExists('commercial_usage_windows');
        Schema::dropIfExists('commercial_usage_limits');
    }
};
