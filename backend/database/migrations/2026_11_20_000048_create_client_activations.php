<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Registered-client and activation model for the Application Client Channels (§6D.7).
     * Client registration, user authentication, tenant scope and action authorization stay
     * separate: a client is only an access channel and confers no business permission.
     */
    public function up(): void
    {
        Schema::create('registered_clients', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 100);
            $table->string('kind');
            $table->string('state')->default('Active');
        });

        // First-party Application Client Channels (§3.86). A Warehouse Handheld client is a
        // future channel and is deliberately not registered yet.
        foreach (['desktop' => 'CBM Desktop', 'mobile' => 'CBM Mobile'] as $kind => $name) {
            DB::table('registered_clients')->insert(['id' => Str::uuid7()->toString(), 'name' => $name, 'kind' => $kind]);
        }

        Schema::create('client_activation_codes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('client_id')->constrained('registered_clients');
            // A Desktop code is consumed by one Tenant; a Mobile code binds one user.
            $table->string('scope');
            $table->foreignUuid('tenant_id')->nullable()->constrained('customer_tenants');
            $table->string('code_digest');
            $table->foreignUuid('generated_by')->constrained('users');
            $table->string('state')->default('available');
            $table->unsignedBigInteger('code_version')->default(1);
            $table->timestampTz('consumed_at')->nullable();
            $table->foreignUuid('consumed_by')->nullable()->constrained('client_instances');
            $table->timestampTz('generated_at');
            $table->timestampTz('revoked_at')->nullable();
            $table->unique(['client_id', 'scope', 'tenant_id', 'code_version'], 'client_activation_code_version');
        });

        Schema::create('client_instances', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('client_id')->constrained('registered_clients');
            $table->string('scope');
            $table->foreignUuid('tenant_id')->nullable()->constrained('customer_tenants');
            $table->foreignUuid('user_id')->nullable()->constrained('users');
            // Only the activation-token digest is stored; the bearer secret is shown once.
            $table->string('token_hash');
            $table->string('device_label', 100)->nullable();
            $table->string('state')->default('Active');
            $table->foreignUuid('activated_by')->constrained('users');
            $table->timestampTz('activated_at');
            $table->timestampTz('revoked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_instances');
        Schema::dropIfExists('client_activation_codes');
        Schema::dropIfExists('registered_clients');
    }
};
