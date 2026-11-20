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
        Schema::table('business_identities', function (Blueprint $table): void {
            $table->string('operational_state')->default('Active');
            $table->unsignedBigInteger('lifecycle_version')->default(0);
            $table->uuid('lifecycle_operation_id')->nullable();
        });
        Schema::table('distributor_shop_mappings', function (Blueprint $table): void {
            $table->unsignedBigInteger('lifecycle_version')->default(0);
            $table->uuid('lifecycle_operation_id')->nullable();
        });
        Schema::create('business_lifecycle_operations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('snapshot_id')->unique();
            $table->foreignUuid('business_id')->constrained('business_identities');
            $table->foreignUuid('actor_id')->constrained('users');
            $table->string('target_state');
            $table->text('reason');
            $table->uuid('restores_operation_id')->nullable();
            $table->timestampTz('recorded_at');
            $table->json('snapshot');
            $table->json('result');
        });
        Schema::create('business_lifecycle_history', function (Blueprint $table): void {
            $table->foreignUuid('operation_id')->constrained('business_lifecycle_operations');
            $table->foreignUuid('business_id')->constrained('business_identities');
            $table->string('from_state');
            $table->string('to_state');
            $table->primary(['business_id', 'operation_id']);
        });
        Schema::table('distributor_shop_mapping_history', function (Blueprint $table): void {
            $table->foreignUuid('operation_id')->nullable()->constrained('business_lifecycle_operations');
        });
        $portal = DB::table('portal_installation')->where('id', 1)->where('stage', 'ready')->value('portal_id');
        $owner = DB::table('portal_events')->where('action', 'installation.owner_created')->value('actor_id');
        if ($portal && $owner && DB::table('portal_grants')->where([
            'user_id' => $owner, 'business_id' => $portal, 'permission' => 'business_structure.create',
        ])->exists()) {
            DB::table('portal_grants')->insert([
                'user_id' => $owner, 'business_id' => $portal, 'permission' => 'business_structure.lifecycle',
            ]);
        }

    }

    public function down(): void
    {
        DB::table('portal_grants')->where('permission', 'business_structure.lifecycle')->delete();
        Schema::dropIfExists('business_lifecycle_history');
        Schema::table('distributor_shop_mapping_history', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('operation_id');
        });
        Schema::dropIfExists('business_lifecycle_operations');
        Schema::table('distributor_shop_mappings', function (Blueprint $table): void {
            $table->dropColumn(['lifecycle_version', 'lifecycle_operation_id']);
        });
        Schema::table('business_identities', function (Blueprint $table): void {
            $table->dropColumn(['operational_state', 'lifecycle_version', 'lifecycle_operation_id']);
        });
    }
};
