<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_authenticators', function (Blueprint $table): void {
            $table->foreignUuid('user_id')->primary()->constrained('users')->cascadeOnDelete();
            $table->text('secret')->nullable();
            $table->unsignedBigInteger('last_used_step')->nullable();
            $table->unsignedInteger('version');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_authenticators');
    }
};
