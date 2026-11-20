<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('postal_reference_entries', function (Blueprint $table): void {
            $table->string('pin_code', 6);
            $table->string('office', 160);
            $table->string('district', 100);
            $table->string('state_or_union_territory', 100);
            $table->primary(['pin_code', 'office', 'district', 'state_or_union_territory'], 'postal_reference_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('postal_reference_entries');
    }
};
