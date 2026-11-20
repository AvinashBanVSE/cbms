<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('business_calendar_presentation', 'business_presentation_preferences');
    }

    public function down(): void
    {
        Schema::rename('business_presentation_preferences', 'business_calendar_presentation');
    }
};
