<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Authorization\EntityRoleAccess;
use App\Authorization\EntityRoles;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

final class EntityRoleController
{
    public function __construct(private readonly EntityRoles $roles) {}

    public function index(string $business): JsonResponse
    {
        $this->roles->requireManager($this->actor(), $business);
        $roles = DB::table('organization_business_roles')->where(['business_id' => $business, 'governed' => true])->orderBy('name')->orderBy('id')->simplePaginate(30);
        $roles->through(fn (object $role): array => [...(array) $role, ...$this->roles->contents($role->id)]);

        return response()->json($roles)->header('Cache-Control', 'no-store');
    }

    public function store(Request $request, string $business): JsonResponse
    {
        return $this->save($request, $business);
    }

    public function update(Request $request, string $business, string $role): JsonResponse
    {
        return $this->save($request, $business, $role);
    }

    private function save(Request $request, string $business, ?string $role = null): JsonResponse
    {
        $actor = $this->actor();
        $this->roles->requireManager($actor, $business);
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'actions' => 'present|array|list', 'actions.*' => 'required|string|distinct',
            'grant_actions' => 'present|array|list', 'grant_actions.*' => 'required|string|distinct',
            'expected_version' => ($role === null ? 'sometimes' : 'required').'|integer|min:1',
        ]);
        try {
            $result = $this->roles->save($actor, $business, $data, $role);
        } catch (UniqueConstraintViolationException) {
            abort(409, 'This role name already exists in this entity.');
        }

        return response()->json($result, $role === null ? 201 : 200);
    }

    public function copy(Request $request, string $business): JsonResponse
    {
        $actor = $this->actor();
        $this->roles->requireManager($actor, $business);
        $data = $request->validate([
            'source_business' => 'required|uuid', 'source_role' => 'required|uuid',
            'name' => 'required|string|max:100', 'expected_version' => 'required|integer|min:1',
        ]);
        try {
            $result = $this->roles->copy($actor, $business, $data['source_business'], $data['source_role'], $data['name'], (int) $data['expected_version']);
        } catch (UniqueConstraintViolationException) {
            abort(409, 'This role name already exists in this entity.');
        }

        return response()->json($result, 201);
    }

    public function assign(Request $request, string $business, string $role): JsonResponse
    {
        $actor = $this->actor();
        $this->roles->requireManager($actor, $business);
        $data = $request->validate([
            'code' => 'required|regex:/^[A-Z]{2}[0-9]{4}$/', 'expected_version' => 'required|integer|min:1',
            'reviewers' => 'sometimes|array|list', 'reviewers.*' => 'required|distinct|regex:/^[A-Z]{2}[0-9]{4}$/',
        ]);
        $user = DB::table('users')->where('code', $data['code'])->value('id');
        abort_if($user === null, 422, 'Choose an eligible user.');
        $reviewers = DB::table('users')->whereIn('code', $data['reviewers'] ?? [])->pluck('id')->all();
        abort_unless(count($reviewers) === count($data['reviewers'] ?? []), 422, 'Choose eligible reviewers.');
        $result = $this->roles->assign($actor, $business, $role, $user, (int) $data['expected_version'], $reviewers);

        return response()->json($result, isset($result['status']) ? 202 : 200);
    }

    public function revoke(Request $request, string $business, string $role): JsonResponse
    {
        $actor = $this->actor();
        $this->roles->requireManager($actor, $business);
        $data = $request->validate(['code' => 'required|regex:/^[A-Z]{2}[0-9]{4}$/', 'reason' => 'required|string|max:1000']);
        $user = DB::table('users')->where('code', $data['code'])->value('id');
        abort_if($user === null, 422);
        $this->roles->revoke($actor, $business, $role, $user, $data['reason']);

        return response()->json(['assigned' => false]);
    }

    public function denies(string $business): JsonResponse
    {
        $this->roles->requireManager($this->actor(), $business);

        return response()->json(['data' => DB::table('entity_permission_denies')->where('business_id', $business)->orderBy('id')->get()])->header('Cache-Control', 'no-store');
    }

    public function deny(Request $request, string $business): JsonResponse
    {
        $data = $request->validate([
            'user_id' => 'required|uuid', 'action' => 'required|string', 'reason' => 'required|string|max:1000',
            'expires_at' => 'nullable|date|after:now',
        ]);

        return response()->json($this->roles->deny($this->actor(), $business, $data['user_id'], $data['action'], $data['reason'], $data['expires_at'] ?? null), 201);
    }

    public function removeDeny(string $business, string $deny): JsonResponse
    {
        $this->roles->removeDeny($this->actor(), $business, $deny);

        return response()->json(['denied' => false]);
    }

    public function approvals(string $business): JsonResponse
    {
        $actor = $this->actor();
        $access = app(EntityRoleAccess::class);
        $manager = $access->manages($actor, $business);
        abort_unless($manager || $access->allows($actor, $business, 'entity_roles.approve'), 403);
        $requests = DB::table('entity_role_changes as change')
            ->join('organization_business_roles as role', 'role.id', '=', 'change.role_id')
            ->where('role.business_id', $business)->where('change.status', 'pending');
        if (! $manager) {
            $requests->whereExists(fn ($query) => $query->selectRaw('1')->from('entity_role_reviewers as reviewer')
                ->whereColumn('reviewer.change_id', 'change.id')->whereColumn('reviewer.stage', 'change.stage')->where('reviewer.user_id', $actor));
        }

        return response()->json($requests->orderBy('change.id')->simplePaginate(30, ['change.*', 'role.name']))->header('Cache-Control', 'no-store');
    }

    public function decide(Request $request, string $business, string $change): JsonResponse
    {
        $data = $request->validate(['decision' => 'required|in:approve,reject']);

        return response()->json($this->roles->decide($this->actor(), $business, $change, $data['decision']));
    }

    private function actor(): string
    {
        abort_unless(Auth::check(), 401);

        return (string) Auth::id();
    }
}
