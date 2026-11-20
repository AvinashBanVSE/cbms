<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class InstallationTest extends TestCase
{
    use RefreshDatabase;

    public function test_fresh_portal_requires_setup(): void
    {
        $this->getJson('/api/v1/installation')->assertOk()->assertExactJson(['stage' => 'setup_required']);
    }

    public function test_missing_installation_is_unavailable(): void
    {
        DB::table('portal_installation')->delete();
        $this->getJson('/api/v1/installation')->assertStatus(503);
    }
}
