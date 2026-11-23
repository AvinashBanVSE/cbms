<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', static function (Blueprint $table): void {
            $table->string('username', 32)->nullable();
            $table->string('mobile_number', 10)->nullable();
            $table->boolean('email_login_enabled')->default(true);
            $table->boolean('username_login_enabled')->default(false);
            $table->boolean('mobile_login_enabled')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('users', static function (Blueprint $table): void {
            $table->dropColumn([
                'username', 'mobile_number', 'email_login_enabled',
                'username_login_enabled', 'mobile_login_enabled',
            ]);
        });
    }
};
