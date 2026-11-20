<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portal_credential_settings', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedTinyInteger('password_history_count');
        });
        Schema::create('user_password_history', function (Blueprint $table): void {
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('sequence');
            $table->string('password_hash');
            $table->primary(['user_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_password_history');
        Schema::dropIfExists('portal_credential_settings');
    }
};
