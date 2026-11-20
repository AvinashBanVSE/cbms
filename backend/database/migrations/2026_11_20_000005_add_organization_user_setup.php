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
        Schema::create('organization_credential_policies', function (Blueprint $table): void {
            $table->foreignUuid('tenant_id')->primary()->constrained('customer_tenants');
            $table->string('mode');
        });

        Schema::create('organization_user_setups', function (Blueprint $table): void {
            $table->foreignUuid('user_id')->primary()->constrained('customer_accounts', 'user_id');
            $table->string('mode');
            $table->unsignedTinyInteger('issue_count')->default(0);
            $table->timestampTz('last_issued_at')->nullable();
            $table->string('temporary_hash')->nullable();
            $table->string('otp_hash')->nullable();
            $table->string('session_digest', 64)->nullable();
            $table->timestampTz('temporary_expires_at')->nullable();
            $table->timestampTz('otp_expires_at')->nullable();
            $table->timestampTz('session_expires_at')->nullable();
        });

        $portal = DB::table('portal_installation')->where('id', 1)->where('stage', 'ready')->value('portal_id');
        $owner = DB::table('portal_events')->where('action', 'installation.owner_created')->value('actor_id');
        if ($portal && $owner && DB::table('portal_grants')->where([
            'user_id' => $owner, 'business_id' => $portal, 'permission' => 'business_structure.create',
        ])->exists()) {
            DB::table('portal_grants')->insert([
                'user_id' => $owner, 'business_id' => $portal, 'permission' => 'organization_users.manage',
            ]);
        }
    }

    public function down(): void
    {
        DB::table('portal_grants')->where('permission', 'organization_users.manage')->delete();
        Schema::dropIfExists('organization_user_setups');
        Schema::dropIfExists('organization_credential_policies');
    }
};
