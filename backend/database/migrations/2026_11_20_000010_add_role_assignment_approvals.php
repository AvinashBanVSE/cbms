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
        Schema::create('role_approval_policies', function (Blueprint $table): void {
            $table->foreignUuid('business_id')->constrained('business_identities');
            $table->string('operation');
            $table->json('stages');
            $table->primary(['business_id', 'operation']);
        });
        Schema::create('role_approval_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('role_id')->constrained('organization_business_roles');
            $table->foreignUuid('user_id')->constrained('customer_accounts', 'user_id');
            $table->foreignUuid('requester_id')->constrained('users');
            $table->boolean('assign');
            $table->json('stages');
            $table->unsignedTinyInteger('stage')->default(0);
            $table->string('status');
            $table->index(['role_id', 'user_id', 'status']);
        });
        Schema::create('role_approval_votes', function (Blueprint $table): void {
            $table->foreignUuid('request_id')->constrained('role_approval_requests');
            $table->unsignedTinyInteger('stage');
            $table->foreignUuid('reviewer_id')->constrained('users');
            $table->primary(['request_id', 'stage', 'reviewer_id']);
        });

        $portal = DB::table('portal_installation')->where('id', 1)->where('stage', 'ready')->value('portal_id');
        $owner = DB::table('portal_events')->where('action', 'installation.owner_created')->value('actor_id');
        if ($portal && $owner && DB::table('portal_grants')->where([
            'user_id' => $owner, 'business_id' => $portal, 'permission' => 'organization_users.manage',
        ])->exists()) {
            DB::table('portal_grants')->insert([
                'user_id' => $owner, 'business_id' => $portal, 'permission' => 'organization_roles.approval_policy',
            ]);
        }
    }

    public function down(): void
    {
        DB::table('portal_grants')->where('permission', 'organization_roles.approval_policy')->delete();
        foreach (['role_approval_votes', 'role_approval_requests', 'role_approval_policies'] as $name) {
            Schema::dropIfExists($name);
        }
    }
};
