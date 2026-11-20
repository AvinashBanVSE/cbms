<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Authorization\MainPortalAccess;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class ServiceRegistryController
{
    public function __construct(private readonly MainPortalAccess $access) {}

    private const SCOPES = ['Ecosystem-wide', 'Service Provider-scoped', 'Tenant-scoped', 'Business-context-scoped'];

    public function index(): JsonResponse
    {
        $this->access->requirePermission('service_catalogue.view');

        return response()->json(DB::table('platform_services')->orderBy('name')->orderBy('id')->simplePaginate(30))
            ->header('Cache-Control', 'no-store');
    }

    public function createService(Request $request): JsonResponse
    {
        $this->access->requirePermission('service_catalogue.create');
        $data = $request->validate([
            'category' => ['required', Rule::in(array_keys(config('services_catalogue')))],
            'name' => 'required|string|max:100',
            'purpose' => 'required|string|max:4000',
            'scopes' => 'required|array|list|min:1|max:4',
            'scopes.*' => ['required', 'string', 'distinct', Rule::in(self::SCOPES)],
        ]);
        $data['scopes'] = json_encode($data['scopes'], JSON_THROW_ON_ERROR);

        return $this->create('platform_services', $data, 'service.created');
    }

    public function capabilities(string $service): JsonResponse
    {
        $this->access->requirePermission('service_catalogue.view');
        $scopes = $this->serviceScopes($service);
        $capabilities = DB::table('service_capabilities')->where('service_id', $service)
            ->orderBy('name')->orderBy('id')->simplePaginate(30)
            ->through(static function (object $capability) use ($scopes): object {
                $declared = $capability->scopes === null ? null : json_decode($capability->scopes, true, flags: JSON_THROW_ON_ERROR);
                $capability->effective_scopes = $declared === null ? $scopes : array_values(array_intersect($declared, $scopes));
                $capability->scope_source = $declared === null ? 'service' : 'capability';

                return $capability;
            });

        return response()->json($capabilities)->header('Cache-Control', 'no-store');
    }

    public function createCapability(Request $request, string $service): JsonResponse
    {
        $this->access->requirePermission('service_catalogue.create');
        $scopes = $this->serviceScopes($service);
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'purpose' => 'required|string|max:4000',
            'scopes' => 'sometimes|array|list|min:1|max:4',
            'scopes.*' => ['required', 'string', 'distinct', Rule::in($scopes)],
        ]);
        if (isset($data['scopes'])) {
            $data['scopes'] = json_encode($data['scopes'], JSON_THROW_ON_ERROR);
        }

        return $this->create('service_capabilities', [...$data, 'service_id' => $service], 'capability.created');
    }

    /** @return list<string> */
    private function serviceScopes(string $service): array
    {
        $scopes = DB::table('platform_services')->where('id', $service)->value('scopes');
        abort_if($scopes === null, 404);

        return json_decode($scopes, true, flags: JSON_THROW_ON_ERROR);
    }

    private function create(string $table, array $record, string $action): JsonResponse
    {
        $id = (string) Str::uuid7();
        try {
            DB::transaction(function () use ($id, $table, $record, $action): void {
                DB::table($table)->insert(['id' => $id, ...$record]);
                DB::table('portal_events')->insert([
                    'id' => (string) Str::uuid7(), 'action' => $action, 'actor_id' => Auth::id(),
                    'subject_id' => $id, 'recorded_at' => now(),
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            abort(409, 'This name is already registered in this context.');
        }

        return response()->json(['id' => $id], 201);
    }
}
