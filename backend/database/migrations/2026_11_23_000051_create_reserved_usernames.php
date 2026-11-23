<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reserved_usernames', static function (Blueprint $table): void {
            $table->string('username', 32)->primary();
            $table->foreignUuid('reserved_by')->nullable()->constrained('users');
            $table->timestampTz('reserved_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reserved_usernames');
    }
};
