<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Authorization\MainPortalAccess;
use App\Domain\ServiceProviderMigration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

final class ServiceProviderMigrationController
{
    private const INPUT = [
        'source_id' => 'required|uuid',
        'destination_id' => 'required|uuid',
        'tenant_id' => 'nullable|uuid',
    ];

    public function __construct(private readonly MainPortalAccess $access, private readonly ServiceProviderMigration $migration) {}

    public function preview(Request $request): JsonResponse
    {
        $this->access->requirePermission('service_provider.migrate');
        $data = $request->validate(self::INPUT);

        return response()->json(DB::transaction(fn () => $this->migration->preview(
            $data['source_id'], $data['destination_id'], $data['tenant_id'] ?? null,
        ), attempts: 3))->header('Cache-Control', 'no-store');
    }

    public function execute(Request $request): JsonResponse
    {
        $this->access->requirePermission('service_provider.migrate');
        $data = $request->validate(self::INPUT + [
            'operation_id' => 'required|uuid',
            'fingerprint' => 'required|string|regex:/^[a-f0-9]{64}$/',
            'reason' => 'required|string|max:500',
        ]);

        return response()->json($this->migration->execute((string) Auth::id(), $data));
    }
}
