<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entity_permission_denies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained('business_identities');
            $table->foreignUuid('user_id')->constrained('users');
            $table->string('action');
            $table->string('reason', 1000);
            $table->foreignUuid('created_by')->constrained('users');
            $table->timestampTz('expires_at')->nullable();
            $table->timestampsTz();
            $table->unique(['business_id', 'user_id', 'action']);
            $table->index(['user_id', 'business_id', 'action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entity_permission_denies');
    }
};
