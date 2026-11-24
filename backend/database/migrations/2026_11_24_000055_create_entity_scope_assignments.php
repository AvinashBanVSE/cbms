<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D2 scope selectors (entity-authorization plan §7, decision record D2).
 *
 * A governed selector records HOW a user's entity scope is established: self, an explicit
 * selected set, a captured snapshot of currently-eligible entities, or a dynamic future-eligible
 * selector, each optionally narrowed by exclusions. Eligibility itself is still evaluated on
 * every access from the live relationship/association model — a selector never manufactures an
 * association, grants an operation, or widens the tenant boundary. Existing
 * entity_user_scopes / customer_business_scopes rows remain authoritative for the current
 * implicit self-scope and are not migrated or altered by this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entity_scope_assignments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('business_id')->constrained('business_identities');
            $table->foreignUuid('user_id')->constrained('users');
            $table->foreignUuid('granted_by')->constrained('users');
            $table->string('selector'); // self | selected | snapshot | dynamic
            $table->uuid('anchor_id')->nullable(); // fixed anchor for dynamic scope
            $table->json('entities')->nullable(); // explicit selected eligible ids
            $table->json('exclusions')->nullable(); // explicit excluded eligible ids
            $table->json('predicates')->nullable(); // supported kind/relationship predicates
            $table->boolean('dynamic')->default(false); // separately governed future-eligible
            $table->boolean('active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->timestampsTz();
            $table->unique(['business_id', 'user_id', 'selector']);
            $table->index(['user_id', 'business_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entity_scope_assignments');
    }
};
