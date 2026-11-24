<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D2/D1 governed owner-linked scope rules (Master Decisions §7.5/§7.6).
 *
 * A Main Portal administrator enables an owner-linked purpose per target entity kind here. The
 * OwnerLinkedScope evaluator treats an enabled, active row as the "expressly authorized" Main
 * Portal-governed rule required before a Distributor's scope may reach a shared-owner mapped Shop.
 * Absence of a rule means owner-linked cross-entity authority is disabled — the safe default.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('owner_linked_scope_rules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('action'); // e.g. mapping.initiate
            $table->string('target_kind'); // entity kind the owner-linked scope may reach (e.g. Shop)
            $table->boolean('enabled')->default(false);
            $table->foreignUuid('granted_by')->nullable()->constrained('users');
            $table->unsignedInteger('version')->default(1);
            $table->timestampsTz();
            $table->unique(['action', 'target_kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('owner_linked_scope_rules');
    }
};
