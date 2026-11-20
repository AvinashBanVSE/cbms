<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['organization_user_setups', 'provider_user_setups'] as $table) {
            Schema::table($table, function (Blueprint $columns): void {
                $columns->unsignedTinyInteger('verification_failures')->default(0);
                $columns->timestampTz('verification_blocked_until')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['organization_user_setups', 'provider_user_setups'] as $table) {
            Schema::table($table, function (Blueprint $columns): void {
                $columns->dropColumn(['verification_failures', 'verification_blocked_until']);
            });
        }
    }
};
