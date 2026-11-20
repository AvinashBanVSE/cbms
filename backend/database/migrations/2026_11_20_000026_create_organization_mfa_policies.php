<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_mfa_policies', function (Blueprint $table): void {
            $table->foreignUuid('tenant_id')->primary()->constrained('customer_tenants');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_mfa_policies');
    }
};
