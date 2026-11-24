<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permission_definitions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('domain');
            $table->string('resource');
            $table->string('action');
            $table->boolean('active')->default(true);
            $table->boolean('assignable')->default(true);
            $table->unsignedInteger('version')->default(1);
        });
        Schema::create('permission_applicability', function (Blueprint $table): void {
            $table->foreignUuid('permission_id')->constrained('permission_definitions');
            $table->string('entity_kind');
            $table->string('workspace')->nullable();
            $table->primary(['permission_id', 'entity_kind']);
        });
        $portal = [
            'service_catalogue.view' => 'services',
            'service_catalogue.create' => null,
            'business_structure.view' => 'businesses',
            'business_structure.create' => null,
            'business_identity.verify' => 'identities',
            'business_structure.lifecycle' => null,
            'business_structure.closure_policy' => null,
            'service_provider.migrate' => null,
            'organization_users.manage' => 'users',
            'commercial.manage' => 'commercial',
            'organization_roles.approval_policy' => null,
            'provider_users.manage' => 'provider_users',
            'audit_trail.view' => 'audit',
            'global_business_settings.view' => 'business_settings',
            'global_business_settings.manage' => null,
        ];
        $mapping = ['mapping.initiate', 'mapping.verify', 'mapping.confirm', 'mapping.end',
            'mapping.reject', 'mapping.withdraw', 'mapping.history.view'];
        $organization = ['clients.activate', 'organization_users.provision', 'organization_users.recover',
            'business_time_presentation.update', 'business_calendar_presentation.date_format.update',
            'business_calendar_presentation.working_days.update', 'business_calendar_presentation.week_start.update',
            'business_number_presentation.negative_amount.update'];
        $contexts = [
            'Main Portal' => $portal,
            'Sub-Portal' => ['provider.organizations.view' => null],
            'Organization' => array_fill_keys($organization, null),
            'Shop' => array_fill_keys($mapping, null),
            'Distributor' => array_fill_keys($mapping, null),
        ];
        foreach ($contexts as $kind => $permissions) {
            foreach ($permissions as $code => $workspace) {
                $id = DB::table('permission_definitions')->where('code', $code)->value('id');
                if ($id === null) {
                    $id = (string) Str::uuid7();
                    $parts = explode('.', $code);
                    $action = array_pop($parts);
                    DB::table('permission_definitions')->insert([
                        'id' => $id, 'code' => $code, 'name' => $code,
                        'domain' => $parts[0], 'resource' => implode('.', $parts), 'action' => $action,
                    ]);
                }
                DB::table('permission_applicability')->insert([
                    'permission_id' => $id, 'entity_kind' => $kind, 'workspace' => $workspace,
                ]);
            }
        }
        // Catalogue creation deliberately changes no user grants, roles, scopes or assignments.
    }

    public function down(): void
    {
        Schema::dropIfExists('permission_applicability');
        Schema::dropIfExists('permission_definitions');
    }
};
