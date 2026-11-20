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
            $table->foreignUuid('renewed_from_id')->nullable()->unique()->constrained('commercial_subscriptions');
        });
    }

    public function down(): void
    {
        Schema::table('commercial_subscriptions', function (Blueprint $table): void {
            $table->dropForeign(['renewed_from_id']);
            $table->dropUnique(['renewed_from_id']);
            $table->dropColumn('renewed_from_id');
        });
    }
};
