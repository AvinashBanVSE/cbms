<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_identities', function (Blueprint $table): void {
            $table->index(['parent_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::table('business_identities', function (Blueprint $table): void {
            $table->dropIndex(['parent_id', 'kind']);
        });
    }
};
