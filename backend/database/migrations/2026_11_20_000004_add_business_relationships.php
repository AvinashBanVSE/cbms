<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_accounts', function (Blueprint $table): void {
            $table->foreignUuid('user_id')->primary()->constrained('users');
            $table->foreignUuid('tenant_id')->constrained('customer_tenants');
            $table->string('state');
        });
        Schema::create('customer_business_associations', function (Blueprint $table): void {
            $table->foreignUuid('user_id')->constrained('customer_accounts', 'user_id');
            $table->foreignUuid('business_id')->constrained('business_identities');
            $table->boolean('active');
            $table->primary(['user_id', 'business_id']);
        });
        Schema::create('customer_business_scopes', function (Blueprint $table): void {
            $table->foreignUuid('user_id')->constrained('customer_accounts', 'user_id');
            $table->foreignUuid('business_id')->constrained('business_identities');
            $table->primary(['user_id', 'business_id']);
        });
        Schema::create('customer_permissions', function (Blueprint $table): void {
            $table->foreignUuid('user_id')->constrained('customer_accounts', 'user_id');
            $table->foreignUuid('business_id')->constrained('business_identities');
            $table->string('action');
            $table->primary(['user_id', 'business_id', 'action']);
        });
        Schema::create('distributor_shop_mappings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('shop_id')->constrained('business_identities');
            $table->foreignUuid('distributor_id')->constrained('business_identities');
            $table->string('state');
            $table->string('code_digest', 64)->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->index(['shop_id', 'state']);
            $table->index(['distributor_id', 'state']);
        });
        Schema::create('distributor_shop_mapping_history', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('mapping_id')->constrained('distributor_shop_mappings');
            $table->foreignUuid('actor_id')->constrained('users');
            $table->string('action');
            $table->timestampTz('recorded_at');
        });
    }

    public function down(): void
    {
        foreach (['distributor_shop_mapping_history', 'distributor_shop_mappings', 'customer_permissions', 'customer_business_scopes', 'customer_business_associations', 'customer_accounts'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
