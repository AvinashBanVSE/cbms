<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_transition_policies', function (Blueprint $table): void {
            $table->foreignUuid('business_id')->constrained('business_identities');
            $table->string('target_state', 32);
            $table->json('reviewers');
            $table->unsignedInteger('expiry_minutes')->nullable();
            $table->unsignedTinyInteger('required_votes');
            $table->primary(['business_id', 'target_state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_transition_policies');
    }
};
