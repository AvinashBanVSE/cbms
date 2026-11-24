<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Authorization\MainPortalAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

final class PermissionCatalogueController
{
    public function __invoke(MainPortalAccess $access): JsonResponse
    {
        $access->requirePermission('organization_users.manage');
        $permissions = DB::table('permission_definitions')->orderBy('code')->get();
        $contexts = DB::table('permission_applicability')->orderBy('entity_kind')->get()->groupBy('permission_id');
        $permissions->transform(static function (object $permission) use ($contexts): object {
            $permission->contexts = $contexts->get($permission->id, collect())
                ->map(fn (object $context): array => ['entity_kind' => $context->entity_kind, 'workspace' => $context->workspace])->all();

            return $permission;
        });

        return response()->json(['data' => $permissions])->header('Cache-Control', 'no-store');
    }
}
