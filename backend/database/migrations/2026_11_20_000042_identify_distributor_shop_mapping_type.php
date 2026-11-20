<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('distributor_shop_mappings', function (Blueprint $table): void {
            $table->string('mapping_type', 64)->default('distributor_shop');
        });
    }

    public function down(): void
    {
        Schema::table('distributor_shop_mappings', function (Blueprint $table): void {
            $table->dropColumn('mapping_type');
        });
    }
};
