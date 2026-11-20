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
        Schema::table('customer_tenants', function (Blueprint $table): void {
            $table->index('provider_id', 'customer_tenants_provider_index');
        });
        Schema::create('service_provider_migrations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('source_id')->constrained('business_identities');
            $table->foreignUuid('destination_id')->constrained('business_identities');
            $table->foreignUuid('actor_id')->constrained('users');
            $table->text('reason');
            $table->string('fingerprint', 64);
            $table->json('manifest');
            $table->timestampTz('recorded_at');
        });

        $portal = DB::table('portal_installation')->where('id', 1)->where('stage', 'ready')->value('portal_id');
        $owner = DB::table('portal_events')->where('action', 'installation.owner_created')->value('actor_id');
        if ($portal && $owner && DB::table('portal_grants')->where([
            'user_id' => $owner, 'business_id' => $portal, 'permission' => 'business_structure.create',
        ])->exists()) {
            DB::table('portal_grants')->insert([
                'user_id' => $owner, 'business_id' => $portal, 'permission' => 'service_provider.migrate',
            ]);
        }
    }

    public function down(): void
    {
        DB::table('portal_grants')->where('permission', 'service_provider.migrate')->delete();
        Schema::dropIfExists('service_provider_migrations');
        Schema::table('customer_tenants', function (Blueprint $table): void {
            $table->dropIndex('customer_tenants_provider_index');
        });
    }
};
