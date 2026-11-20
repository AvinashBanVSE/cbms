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
        Schema::create('legal_identities', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('legal_name', 200);
            $table->string('evidence_reference', 200);
            $table->foreignUuid('verified_by')->constrained('users');
            $table->timestampTz('verified_at');
        });
        Schema::create('business_legal_identity_links', function (Blueprint $table): void {
            $table->foreignUuid('business_id')->primary()->constrained('business_identities');
            $table->foreignUuid('legal_identity_id')->constrained('legal_identities');
        });
        $portal = DB::table('portal_installation')->where('id', 1)->where('stage', 'ready')->value('portal_id');
        $owner = DB::table('portal_events')->where('action', 'installation.owner_created')->value('actor_id');
        if ($portal !== null && $owner !== null && DB::table('portal_grants')->where([
            'user_id' => $owner, 'business_id' => $portal, 'permission' => 'business_structure.create',
        ])->exists()) {
            DB::table('portal_grants')->insertOrIgnore(['user_id' => $owner, 'business_id' => $portal, 'permission' => 'business_identity.verify']);
        }
    }

    public function down(): void
    {
        DB::table('portal_grants')->where('permission', 'business_identity.verify')->delete();
        Schema::dropIfExists('business_legal_identity_links');
        Schema::dropIfExists('legal_identities');
    }
};
