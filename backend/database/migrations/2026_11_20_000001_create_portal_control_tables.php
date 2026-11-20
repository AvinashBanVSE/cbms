<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_identities', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('kind');
            $table->string('legal_name')->nullable();
            $table->string('professional_name');
            $table->foreignUuid('parent_id')->nullable()->constrained('business_identities');
        });
        Schema::create('ownership_holders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
        });
        Schema::create('ownership_links', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('owner_id')->constrained('ownership_holders');
            $table->foreignUuid('business_id')->constrained('business_identities');
            $table->unique(['owner_id', 'business_id']);
        });
        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 6)->unique();
            $table->string('email');
            $table->string('password');
        });
        Schema::create('portal_grants', function (Blueprint $table): void {
            $table->foreignUuid('user_id')->constrained();
            $table->foreignUuid('business_id')->constrained('business_identities');
            $table->string('permission');
            $table->primary(['user_id', 'business_id', 'permission']);
        });
        Schema::table('portal_installation', function (Blueprint $table): void {
            $table->foreignUuid('portal_id')->nullable()->constrained('business_identities');
        });
        Schema::create('portal_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('action');
            $table->uuid('subject_id')->nullable();
            $table->uuid('actor_id')->nullable();
            $table->timestampTz('recorded_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_events');
        Schema::table('portal_installation', fn (Blueprint $table) => $table->dropConstrainedForeignId('portal_id'));
        foreach (['portal_grants', 'users', 'ownership_links', 'ownership_holders', 'business_identities'] as $name) {
            Schema::dropIfExists($name);
        }
    }
};
