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
        Schema::create('provider_accounts', function (Blueprint $table): void {
            $table->foreignUuid('user_id')->primary()->constrained('users');
            $table->foreignUuid('provider_id')->constrained('business_identities');
            $table->string('state');
            $table->unsignedInteger('session_version')->default(0);
        });
        Schema::create('provider_account_permissions', function (Blueprint $table): void {
            $table->foreignUuid('user_id')->constrained('provider_accounts', 'user_id');
            $table->string('permission');
            $table->primary(['user_id', 'permission']);
        });
        Schema::create('provider_user_setups', function (Blueprint $table): void {
            $table->foreignUuid('user_id')->primary()->constrained('provider_accounts', 'user_id');
            $table->string('mode');
            $table->unsignedTinyInteger('issue_count')->default(0);
            $table->string('otp_hash')->nullable();
            $table->string('session_digest', 64)->nullable();
            $table->timestampTz('last_issued_at')->nullable();
            $table->timestampTz('otp_expires_at')->nullable();
            $table->timestampTz('session_expires_at')->nullable();
        });
        $portal = DB::table('portal_installation')->where('id', 1)->where('stage', 'ready')->value('portal_id');
        $owner = DB::table('portal_events')->where('action', 'installation.owner_created')->value('actor_id');
        if ($portal !== null && $owner !== null && DB::table('portal_grants')->where([
            'user_id' => $owner, 'business_id' => $portal, 'permission' => 'organization_users.manage',
        ])->exists()) {
            DB::table('portal_grants')->insertOrIgnore(['user_id' => $owner, 'business_id' => $portal, 'permission' => 'provider_users.manage']);
        }
    }

    public function down(): void
    {
        DB::table('portal_grants')->where('permission', 'provider_users.manage')->delete();
        Schema::dropIfExists('provider_user_setups');
        Schema::dropIfExists('provider_account_permissions');
        Schema::dropIfExists('provider_accounts');
    }
};
