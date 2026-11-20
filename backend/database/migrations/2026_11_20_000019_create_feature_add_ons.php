<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commercial_subscription_coverage', function (Blueprint $table): void {
            $table->index(['business_id', 'subscription_id'], 'commercial_coverage_business_lookup');
        });
        Schema::create('commercial_add_ons', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('provider_id')->constrained('business_identities');
            $table->string('family');
            $table->string('name', 100);
            $table->string('type');
            $table->unique(['provider_id', 'family', 'name']);
        });
        Schema::create('commercial_add_on_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('add_on_id')->constrained('commercial_add_ons');
            $table->foreignUuid('plan_version_id')->constrained('commercial_plan_versions');
            $table->unsignedInteger('version');
            $table->unsignedBigInteger('amount_paise');
            $table->json('entitlements');
            $table->timestampTz('published_at');
            $table->unique(['add_on_id', 'version']);
            $table->unique(['id', 'add_on_id'], 'commercial_add_on_version_identity');
        });
        Schema::create('commercial_subscription_add_ons', function (Blueprint $table): void {
            $table->foreignUuid('subscription_id')->constrained('commercial_subscriptions');
            $table->foreignUuid('business_id')->constrained('business_identities');
            $table->foreignUuid('add_on_id')->constrained('commercial_add_ons');
            $table->uuid('add_on_version_id');
            $table->primary(['subscription_id', 'business_id', 'add_on_id'], 'commercial_subscription_add_ons_pk');
            $table->foreign(['subscription_id', 'business_id'], 'commercial_add_on_coverage_fk')
                ->references(['subscription_id', 'business_id'])->on('commercial_subscription_coverage');
            $table->foreign(['add_on_version_id', 'add_on_id'], 'commercial_add_on_version_fk')
                ->references(['id', 'add_on_id'])->on('commercial_add_on_versions');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commercial_subscription_add_ons');
        Schema::dropIfExists('commercial_add_on_versions');
        Schema::dropIfExists('commercial_add_ons');
        Schema::table('commercial_subscription_coverage', function (Blueprint $table): void {
            $table->dropIndex('commercial_coverage_business_lookup');
        });
    }
};
