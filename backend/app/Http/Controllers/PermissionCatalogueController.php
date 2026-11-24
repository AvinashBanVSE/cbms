<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Authorization\MainPortalAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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

    /**
     * Governed catalogue edit (plan §7): only a definition's presentation and lifecycle fields
     * (name, active, assignable) may change, and only with an expected_version to prevent lost
     * updates. The operation identity (code, domain, resource, action) and its applicability are
     * immutable here — published codes and operation bindings cannot be renamed or repointed.
     */
    public function update(Request $request, string $code, MainPortalAccess $access): JsonResponse
    {
        $access->requirePermission('organization_users.manage');
        $actor = (string) Auth::id();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'active' => ['required', 'boolean'],
            'assignable' => ['required', 'boolean'],
            'expected_version' => ['required', 'integer', 'min:1'],
        ]);

        $updated = DB::transaction(function () use ($code, $data): ?object {
            $definition = DB::table('permission_definitions')->where('code', $code)->lockForUpdate()->first(['id', 'name', 'active', 'assignable', 'version']);
            if ($definition === null || (int) $definition->version !== (int) $data['expected_version']) {
                return null;
            }

            DB::table('permission_definitions')->where('id', $definition->id)->update([
                'name' => $data['name'],
                'active' => (bool) $data['active'],
                'assignable' => (bool) $data['assignable'],
                'version' => (int) $definition->version + 1,
            ]);

            return DB::table('permission_definitions')->where('id', $definition->id)->first();
        });

        if ($updated === null) {
            abort(409, 'The permission catalogue changed. Reload and retry.');
        }

        return response()->json(['data' => $updated, 'updated_by' => $actor])->header('Cache-Control', 'no-store');
    }
}
