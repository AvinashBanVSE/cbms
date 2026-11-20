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
        Schema::table('portal_events', function (Blueprint $table): void {
            $table->index(['recorded_at', 'id'], 'portal_events_chronology');
            $table->index(['subject_id', 'recorded_at', 'id'], 'portal_events_subject_chronology');
        });
        Schema::table('distributor_shop_mapping_history', function (Blueprint $table): void {
            $table->index(['mapping_id', 'recorded_at', 'id'], 'mapping_history_chronology');
        });
        $portal = DB::table('portal_installation')->where('id', 1)->where('stage', 'ready')->value('portal_id');
        $owner = DB::table('portal_events')->where('action', 'installation.owner_created')->value('actor_id');
        if ($portal !== null && $owner !== null && DB::table('portal_grants')->where([
            'user_id' => $owner, 'business_id' => $portal, 'permission' => 'business_structure.create',
        ])->exists()) {
            DB::table('portal_grants')->insertOrIgnore([
                'user_id' => $owner, 'business_id' => $portal, 'permission' => 'audit_trail.view',
            ]);
        }
    }

    public function down(): void
    {
        DB::table('portal_grants')->where('permission', 'audit_trail.view')->delete();
        Schema::table('distributor_shop_mapping_history', function (Blueprint $table): void {
            $table->dropIndex('mapping_history_chronology');
        });
        Schema::table('portal_events', function (Blueprint $table): void {
            $table->dropIndex('portal_events_chronology');
            $table->dropIndex('portal_events_subject_chronology');
        });
    }
};
