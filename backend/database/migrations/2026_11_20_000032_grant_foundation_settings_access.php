<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $portal = DB::table('portal_installation')->where('id', 1)->where('stage', 'ready')->value('portal_id');
        $owner = DB::table('portal_events')->where('action', 'installation.owner_created')->value('actor_id');
        if ($portal !== null && $owner !== null && DB::table('portal_grants')->where([
            'user_id' => $owner, 'business_id' => $portal, 'permission' => 'business_structure.create',
        ])->exists()) {
            DB::table('portal_grants')->insertOrIgnore([
                'user_id' => $owner, 'business_id' => $portal, 'permission' => 'global_business_settings.view',
            ]);
        }
    }

    public function down(): void
    {
        DB::table('portal_grants')->where('permission', 'global_business_settings.view')->delete();
    }
};
