<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('role_approval_policies', function (Blueprint $table): void {
            $table->unsignedInteger('expiry_minutes')->nullable();
        });
        Schema::table('role_approval_requests', function (Blueprint $table): void {
            $table->timestampTz('expires_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('role_approval_requests', function (Blueprint $table): void {
            $table->dropColumn('expires_at');
        });
        Schema::table('role_approval_policies', function (Blueprint $table): void {
            $table->dropColumn('expiry_minutes');
        });
    }
};
