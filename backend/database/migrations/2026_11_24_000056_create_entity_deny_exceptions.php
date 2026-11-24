<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D1 governed deny exceptions.
 *
 * A deny normally blocks an action for a user in a business. An exception is an explicitly
 * authorized carve-out that waives an otherwise-matching deny for a narrower context: a specific
 * target record or an explicitly scoped set, never the whole action. Because adding or widening
 * an exception increases access, it is an access expansion and requires proof of restriction-
 * management authority, a version guard against concurrent edits, and an audit event. Absence of
 * an exception never denies; it only unblocks what the matching deny would have blocked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entity_deny_exceptions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('deny_id')->constrained('entity_permission_denies');
            $table->foreignUuid('business_id')->constrained('business_identities');
            $table->foreignUuid('user_id')->constrained('users');
            $table->string('action');
            $table->string('scope'); // record | selector
            $table->uuid('target_id')->nullable(); // specific record the deny is waived for
            $table->string('selector')->nullable(); // governed selector name when scope=selector
            $table->string('reason', 1000);
            $table->foreignUuid('granted_by')->constrained('users');
            $table->timestampTz('expires_at')->nullable();
            $table->boolean('active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestampsTz();
            $table->index(['user_id', 'business_id', 'action']);
            $table->index('deny_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entity_deny_exceptions');
    }
};
