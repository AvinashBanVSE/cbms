<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portal_installation', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->string('stage');
        });

        DB::table('portal_installation')->insert(['id' => 1, 'stage' => 'setup_required']);
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_installation');
    }
};
