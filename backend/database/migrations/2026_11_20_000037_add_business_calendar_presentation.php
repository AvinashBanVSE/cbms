<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_calendar_presentation', function (Blueprint $table): void {
            $table->foreignUuid('business_id')->constrained('business_identities');
            $table->string('setting', 24);
            $table->string('value', 24)->nullable();
            $table->boolean('override_allowed')->default(false);
            $table->boolean('locked')->default(false);
            $table->unsignedInteger('version');
            $table->primary(['business_id', 'setting']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_calendar_presentation');
    }
};
