<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A Subscription amendment (§14.9) carries an explicit effective instant and preserves
        // the before-and-after contractual terms. The amended subscription links back through
        // the existing renewed_from_id lineage so both Plan Versions, coverage and entitlements
        // remain readable after the change.
        Schema::create('commercial_subscription_amendments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('subscription_id')->constrained('commercial_subscriptions');
            $table->foreignUuid('amended_from_id')->nullable()->constrained('commercial_subscriptions');
            $table->timestampTz('effective_at');
            $table->string('change_type');
            $table->string('timing');
            $table->unsignedBigInteger('credit_paise')->default(0);
            $table->unsignedBigInteger('charge_paise')->default(0);
            $table->string('proration_basis')->nullable();
            $table->string('reason');
            $table->string('actor_id');
            $table->timestampTz('recorded_at');
            $table->index(['subscription_id', 'effective_at']);
        });

        // The billing calculation reference for a change: how the proration amounts were derived.
        Schema::table('commercial_invoices', function (Blueprint $table): void {
            $table->foreignUuid('amendment_id')->nullable()->constrained('commercial_subscription_amendments');
        });
    }

    public function down(): void
    {
        Schema::table('commercial_invoices', function (Blueprint $table): void {
            $table->dropForeign(['amendment_id']);
            $table->dropColumn('amendment_id');
        });
        Schema::dropIfExists('commercial_subscription_amendments');
    }
};
