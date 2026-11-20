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
        Schema::create('business_time_presentation', function (Blueprint $table): void {
            $table->foreignUuid('business_id')->primary()->constrained('business_identities');
            $table->string('clock')->nullable();
            $table->boolean('override_allowed')->default(false);
            $table->boolean('locked')->default(false);
            $table->unsignedInteger('version');
        });

        $portal = DB::table('portal_installation')->where('id', 1)->where('stage', 'ready')->value('portal_id');
        $owner = DB::table('portal_events')->where('action', 'installation.owner_created')->value('actor_id');
        if ($portal !== null && $owner !== null && DB::table('portal_grants')->where([
            'user_id' => $owner, 'business_id' => $portal, 'permission' => 'global_business_settings.view',
        ])->exists()) {
            DB::table('portal_grants')->insertOrIgnore([
                'user_id' => $owner, 'business_id' => $portal, 'permission' => 'global_business_settings.manage',
            ]);
        }
    }

    public function down(): void
    {
        DB::table('portal_grants')->where('permission', 'global_business_settings.manage')->delete();
        Schema::dropIfExists('business_time_presentation');
    }
};
