<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_credential_defaults', function (Blueprint $table): void {
            $table->foreignUuid('provider_id')->primary()->constrained('business_identities');
            $table->string('mode');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_credential_defaults');
    }
};
