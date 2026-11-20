<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['business_closure_policies', 'business_closure_approvals'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->unsignedTinyInteger('required_votes')->default(1);
            });
        }
        Schema::create('business_closure_votes', function (Blueprint $table): void {
            $table->foreignUuid('approval_id')->constrained('business_closure_approvals');
            $table->foreignUuid('reviewer_id')->constrained('users');
            $table->primary(['approval_id', 'reviewer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_closure_votes');
        foreach (['business_closure_policies', 'business_closure_approvals'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn('required_votes'));
        }
    }
};
