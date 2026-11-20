<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commercial_subscriptions', function (Blueprint $table): void {
            $table->unsignedSmallInteger('renewal_lead_days')->nullable();
            $table->unsignedSmallInteger('renewal_grace_days')->nullable();
            $table->string('renewal_grace_policy')->nullable();
            $table->date('grace_ends_before')->nullable();
            $table->index(['renewal_mode', 'state', 'ends_before'], 'commercial_renewal_candidates');
        });
    }

    public function down(): void
    {
        Schema::table('commercial_subscriptions', function (Blueprint $table): void {
            $table->dropIndex('commercial_renewal_candidates');
            $table->dropColumn(['renewal_lead_days', 'renewal_grace_days', 'renewal_grace_policy', 'grace_ends_before']);
        });
    }
};
