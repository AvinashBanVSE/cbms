<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PERMISSIONS = ['view', 'create'];

    public function up(): void
    {
        Schema::create('customer_tenants', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->unique()->constrained('business_identities');
            $table->foreignUuid('provider_id')->constrained('business_identities');
        });

        $portal = DB::table('portal_installation')->where('id', 1)->where('stage', 'ready')->value('portal_id');
        $owner = DB::table('portal_events')->where('action', 'installation.owner_created')->value('actor_id');
        if ($portal !== null && $owner !== null && DB::table('users')->where('id', $owner)->exists()) {
            foreach (self::PERMISSIONS as $action) {
                $grant = ['user_id' => $owner, 'business_id' => $portal];
                if (DB::table('portal_grants')->where($grant)->where('permission', 'service_catalogue.'.$action)->exists()) {
                    DB::table('portal_grants')->insert([...$grant, 'permission' => 'business_structure.'.$action]);
                }
            }
        }
    }

    public function down(): void
    {
        DB::table('portal_grants')->whereIn('permission', array_map(static fn (string $action): string => 'business_structure.'.$action, self::PERMISSIONS))->delete();
        Schema::dropIfExists('customer_tenants');
    }
};
