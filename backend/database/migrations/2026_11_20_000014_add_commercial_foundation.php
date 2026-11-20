<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commercial_accounts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('customer_tenants');
            $table->foreignUuid('provider_id')->constrained('business_identities');
            $table->string('billing_party');
        });
        Schema::create('commercial_entitlement_definitions', function (Blueprint $table): void {
            $table->string('code', 80)->primary();
            $table->string('name', 150);
            $table->string('type');
            $table->string('composition');
        });
        Schema::create('commercial_plans', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('provider_id')->constrained('business_identities');
            $table->string('name', 100);
            $table->string('family');
            $table->unique(['provider_id', 'family', 'name']);
        });
        Schema::create('commercial_plan_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('plan_id')->constrained('commercial_plans');
            $table->unsignedInteger('version');
            $table->unsignedBigInteger('amount_paise');
            $table->unsignedInteger('term_days');
            $table->json('entitlements');
            $table->timestampTz('published_at');
            $table->unique(['plan_id', 'version']);
        });
        Schema::create('commercial_subscriptions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('account_id')->constrained('commercial_accounts');
            $table->foreignUuid('plan_version_id')->constrained('commercial_plan_versions');
            $table->string('state');
            $table->string('renewal_mode');
            $table->date('starts_on');
            $table->date('ends_before');
            $table->string('coverage_mode');
        });
        Schema::create('commercial_subscription_coverage', function (Blueprint $table): void {
            $table->foreignUuid('subscription_id')->constrained('commercial_subscriptions');
            $table->foreignUuid('business_id')->constrained('business_identities');
            $table->primary(['subscription_id', 'business_id'], 'commercial_coverage_primary');
        });

        $portal = DB::table('portal_installation')->where('id', 1)->where('stage', 'ready')->value('portal_id');
        $owner = DB::table('portal_events')->where('action', 'installation.owner_created')->value('actor_id');
        if ($portal && $owner && DB::table('portal_grants')->where([
            'user_id' => $owner, 'business_id' => $portal, 'permission' => 'business_structure.create',
        ])->exists()) {
            DB::table('portal_grants')->insert([
                'user_id' => $owner, 'business_id' => $portal, 'permission' => 'commercial.manage',
            ]);
        }
    }

    public function down(): void
    {
        DB::table('portal_grants')->where('permission', 'commercial.manage')->delete();
        foreach (['commercial_subscription_coverage', 'commercial_subscriptions', 'commercial_plan_versions',
            'commercial_plans', 'commercial_entitlement_definitions', 'commercial_accounts'] as $name) {
            Schema::dropIfExists($name);
        }
    }
};
