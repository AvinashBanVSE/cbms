<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_access_delegations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('customer_tenants');
            $table->foreignUuid('business_id')->constrained('business_identities');
            $table->foreignUuid('recipient_id')->constrained('customer_accounts', 'user_id');
            $table->foreignUuid('granted_by')->constrained('users');
            $table->json('allowed_actions');
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->timestampTz('revoked_at')->nullable();
            $table->index(['recipient_id', 'tenant_id', 'business_id', 'ends_at'], 'organization_delegations_access_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_access_delegations');
    }
};
