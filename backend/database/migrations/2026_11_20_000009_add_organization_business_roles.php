<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_business_roles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained('business_identities');
            $table->string('name', 100);
            $table->unique(['business_id', 'name']);
        });
        Schema::create('organization_role_permissions', function (Blueprint $table): void {
            $table->foreignUuid('role_id')->constrained('organization_business_roles');
            $table->string('action');
            $table->primary(['role_id', 'action']);
        });
        Schema::create('organization_role_assignments', function (Blueprint $table): void {
            $table->foreignUuid('role_id')->constrained('organization_business_roles');
            $table->foreignUuid('user_id')->constrained('customer_accounts', 'user_id');
            $table->primary(['role_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_role_assignments');
        Schema::dropIfExists('organization_role_permissions');
        Schema::dropIfExists('organization_business_roles');
    }
};
