<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('legal_identities', function (Blueprint $table): void {
            $table->string('verification_source')->default('manual');
        });
    }

    public function down(): void
    {
        Schema::table('legal_identities', function (Blueprint $table): void {
            $table->dropColumn('verification_source');
        });
    }
};
