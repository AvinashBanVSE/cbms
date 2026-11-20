<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_services', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('category', 4);
            $table->string('name', 100);
            $table->text('purpose');
            $table->json('scopes');
            $table->unique(['category', 'name']);
        });
        Schema::create('service_capabilities', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('service_id')->constrained('platform_services');
            $table->string('name', 100);
            $table->text('purpose');
            $table->unique(['service_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_capabilities');
        Schema::dropIfExists('platform_services');
    }
};
