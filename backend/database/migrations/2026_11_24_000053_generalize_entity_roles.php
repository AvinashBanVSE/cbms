<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organization_business_roles', function (Blueprint $table): void {
            $table->boolean('governed')->default(false);
            $table->boolean('archived')->default(false);
            $table->unsignedInteger('version')->default(1);
            $table->uuid('template_id')->nullable();
            $table->unsignedInteger('template_version')->nullable();
        });
        Schema::table('organization_role_assignments', function (Blueprint $table): void {
            $table->dropForeign(['user_id']);
            $table->foreign('user_id')->references('id')->on('users');
        });
        Schema::table('permission_definitions', function (Blueprint $table): void {
            $table->boolean('requires_approval')->default(true);
        });
        DB::table('permission_definitions')->where('domain', 'mapping')->update(['requires_approval' => false]);
        foreach (['entity_roles.manage', 'entity_roles.approve'] as $code) {
            $id = (string) Str::uuid7();
            DB::table('permission_definitions')->insert([
                'id' => $id, 'code' => $code, 'name' => $code, 'domain' => 'authorization',
                'resource' => 'entity_roles', 'action' => substr($code, strrpos($code, '.') + 1),
            ]);
            foreach (['Main Portal', 'Sub-Portal', 'Organization', 'Distributor', 'Shop'] as $kind) {
                DB::table('permission_applicability')->insert(['permission_id' => $id, 'entity_kind' => $kind]);
            }
        }
        Schema::create('entity_role_grant_limits', function (Blueprint $table): void {
            $table->foreignUuid('role_id')->constrained('organization_business_roles');
            $table->string('action');
            $table->primary(['role_id', 'action']);
        });
        Schema::create('entity_user_scopes', function (Blueprint $table): void {
            $table->foreignUuid('user_id')->constrained('users');
            $table->foreignUuid('business_id')->constrained('business_identities');
            $table->primary(['user_id', 'business_id']);
        });
        Schema::create('entity_authorization_versions', function (Blueprint $table): void {
            $table->foreignUuid('business_id')->primary()->constrained('business_identities');
            $table->unsignedInteger('version')->default(0);
        });
        Schema::create('entity_role_changes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('role_id')->constrained('organization_business_roles');
            $table->foreignUuid('user_id')->constrained('users');
            $table->foreignUuid('requester_id')->constrained('users');
            $table->unsignedInteger('role_version');
            $table->string('fingerprint', 64);
            $table->json('stages');
            $table->unsignedInteger('stage')->default(0);
            $table->string('status');
            $table->timestampTz('expires_at')->nullable();
            $table->index(['role_id', 'user_id', 'status']);
        });
        Schema::create('entity_role_reviewers', function (Blueprint $table): void {
            $table->foreignUuid('change_id')->constrained('entity_role_changes');
            $table->foreignUuid('user_id')->constrained('users');
            $table->unsignedInteger('stage');
            $table->primary(['change_id', 'user_id', 'stage']);
            $table->index(['user_id', 'stage']);
        });
        Schema::create('entity_role_votes', function (Blueprint $table): void {
            $table->foreignUuid('change_id')->constrained('entity_role_changes');
            $table->foreignUuid('user_id')->constrained('users');
            $table->unsignedInteger('stage');
            $table->primary(['change_id', 'user_id', 'stage']);
        });
        // Preserve the already-effective self scopes of platform/provider grants only.
        // No customer scopes, role assignments, or permission grants are added.
        foreach (DB::table('portal_grants')->select('user_id', 'business_id')->distinct()->get() as $grant) {
            DB::table('entity_user_scopes')->insertOrIgnore((array) $grant);
        }
        foreach (DB::table('provider_accounts as account')->join('provider_account_permissions as permission', 'permission.user_id', '=', 'account.user_id')
            ->select('account.user_id', 'account.provider_id as business_id')->distinct()->get() as $grant) {
            DB::table('entity_user_scopes')->insertOrIgnore((array) $grant);
        }
    }

    public function down(): void
    {
        // Destructive rollback would ignore new restrictions or drop live authority.
        throw new RuntimeException('Entity authorization requires a reviewed forward migration; automatic rollback is disabled.');
    }
};
