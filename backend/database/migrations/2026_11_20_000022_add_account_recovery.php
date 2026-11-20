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
        foreach (['organization_user_setups', 'provider_user_setups'] as $setups) {
            Schema::table($setups, function (Blueprint $table): void {
                $table->string('purpose')->default('setup');
                $table->string('prior_password_hash')->nullable();
            });
        }
        Schema::table('provider_user_setups', function (Blueprint $table): void {
            $table->string('temporary_hash')->nullable();
            $table->timestampTz('temporary_expires_at')->nullable();
        });
    }

    public function down(): void
    {
        // A rollback clears outstanding recovery credentials. The invalidated password stays invalid.
        foreach (['organization_user_setups', 'provider_user_setups'] as $setups) {
            DB::table($setups)->where('purpose', 'recovery')->delete();
            Schema::table($setups, function (Blueprint $table): void {
                $table->dropColumn(['purpose', 'prior_password_hash']);
            });
        }
        Schema::table('provider_user_setups', function (Blueprint $table): void {
            $table->dropColumn(['temporary_hash', 'temporary_expires_at']);
        });
    }
};
