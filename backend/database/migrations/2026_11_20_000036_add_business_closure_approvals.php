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
        Schema::create('business_closure_policies', function (Blueprint $table): void {
            $table->foreignUuid('business_id')->primary()->constrained('business_identities');
            $table->json('reviewers');
            $table->unsignedInteger('expiry_minutes')->nullable();
        });

        Schema::create('business_closure_approvals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained('business_identities');
            $table->foreignUuid('requester_id')->constrained('users');
            $table->json('command');
            $table->json('reviewers');
            $table->string('status');
            $table->timestampTz('expires_at')->nullable();
            $table->index(['business_id', 'status', 'id']);
        });

        $portal = DB::table('portal_installation')->where('id', 1)->where('stage', 'ready')->value('portal_id');
        $owner = DB::table('portal_events')->where('action', 'installation.owner_created')->value('actor_id');
        if ($portal !== null && $owner !== null && DB::table('portal_grants')->where([
            'user_id' => $owner, 'business_id' => $portal, 'permission' => 'business_structure.lifecycle',
        ])->exists()) {
            DB::table('portal_grants')->insertOrIgnore([
                'user_id' => $owner, 'business_id' => $portal, 'permission' => 'business_structure.closure_policy',
            ]);
        }
    }

    public function down(): void
    {
        DB::table('portal_grants')->where('permission', 'business_structure.closure_policy')->delete();
        Schema::dropIfExists('business_closure_approvals');
        Schema::dropIfExists('business_closure_policies');
    }
};
