<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Approval\RoleAssignmentWorkflow;
use App\Authorization\MainPortalAccess;
use App\Authorization\MappingPermissions;
use App\Authorization\OrganizationBusinessRoles;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class OrganizationRoleController
{
    public function __construct(private readonly MainPortalAccess $access, private readonly RoleAssignmentWorkflow $workflow) {}

    public function index(string $tenant, string $business): JsonResponse
    {
        $this->access->requirePermission('organization_users.manage');
        OrganizationBusinessRoles::businessKind($tenant, $business);
        $roles = DB::table('organization_business_roles')->where('business_id', $business)->orderBy('name')->orderBy('id')->simplePaginate(30);
        $permissions = DB::table('organization_role_permissions')->whereIn('role_id', $roles->getCollection()->pluck('id'))
            ->orderBy('action')->get()->groupBy('role_id');
        $roles->through(static function (object $role) use ($permissions): object {
            $role->actions = $permissions->get($role->id, collect())->pluck('action')->all();

            return $role;
        });

        return response()->json($roles)->header('Cache-Control', 'no-store');
    }

    public function store(Request $request, string $tenant, string $business): JsonResponse
    {
        $this->access->requirePermission('organization_users.manage');
        $kind = OrganizationBusinessRoles::businessKind($tenant, $business);
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'actions' => 'required|array|list|max:'.MappingPermissions::maxActions(),
            'actions.*' => 'required|string|distinct',
        ]);
        MappingPermissions::validate($kind, $data['actions']);
        $role = Str::uuid7()->toString();
        try {
            DB::transaction(function () use ($business, $role, $data): void {
                DB::table('organization_business_roles')->insert(['id' => $role, 'business_id' => $business, 'name' => $data['name']]);
                foreach ($data['actions'] as $action) {
                    DB::table('organization_role_permissions')->insert(['role_id' => $role, 'action' => $action]);
                }
                $this->audit('organization_role.created', $role, ['business_id' => $business, ...$data]);
            });
        } catch (UniqueConstraintViolationException) {
            abort(409, 'This role name is already registered for this business.');
        }

        return response()->json(['id' => $role], 201);
    }

    public function assign(Request $request, string $tenant, string $business, string $role): JsonResponse
    {
        return $this->setAssignment($request, $tenant, $business, $role, true);
    }

    public function revoke(Request $request, string $tenant, string $business, string $role): JsonResponse
    {
        return $this->setAssignment($request, $tenant, $business, $role, false);
    }

    private function setAssignment(Request $request, string $tenant, string $business, string $role, bool $assigned): JsonResponse
    {
        $this->access->requirePermission('organization_users.manage');
        OrganizationBusinessRoles::businessKind($tenant, $business);
        abort_unless(DB::table('organization_business_roles')->where(['id' => $role, 'business_id' => $business, 'governed' => false])->exists(), 404);
        $data = $request->validate(['code' => 'required|regex:/^[A-Z]{2}[0-9]{4}$/']);

        return DB::transaction(function () use ($tenant, $business, $role, $assigned, $data): JsonResponse {
            DB::table('business_identities')->where('id', $business)->lockForUpdate()->first(['id']);
            $this->access->requirePermission('organization_users.manage');
            $user = DB::table('customer_accounts as account')->join('users', 'users.id', '=', 'account.user_id')
                ->where('account.tenant_id', $tenant)->where('users.code', $data['code'])->lockForUpdate()->value('account.user_id');
            abort_if($user === null, 404);
            $result = $this->workflow->request($business, $role, $user, (string) Auth::id(), $assigned);

            return response()->json($result, isset($result['status']) ? 202 : 200);
        }, attempts: 3);
    }

    private function audit(string $action, string $subject, array $details): void
    {
        DB::table('portal_events')->insert([
            'id' => Str::uuid7()->toString(), 'action' => $action, 'actor_id' => Auth::id(),
            'subject_id' => $subject, 'recorded_at' => now(), 'details' => json_encode($details, JSON_THROW_ON_ERROR),
        ]);
    }
}
