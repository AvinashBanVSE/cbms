<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Approval\RoleAssignmentWorkflow;
use App\Authorization\MainPortalAccess;
use App\Authorization\OrganizationBusinessRoles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RoleApprovalController
{
    private const PERMISSION = 'organization_users.manage';

    public function __construct(private readonly MainPortalAccess $access, private readonly RoleAssignmentWorkflow $workflow) {}

    public function policy(Request $request, string $tenant, string $business): JsonResponse
    {
        $this->access->requirePermission('organization_roles.approval_policy');
        OrganizationBusinessRoles::businessKind($tenant, $business);
        $data = $request->validate([
            'operation' => 'required|in:assign,revoke',
            'stages' => 'present|array|list|max:10',
            'stages.*' => 'required|array:reviewers,required',
            'stages.*.required' => 'required|integer|min:1|max:10',
            'stages.*.reviewers' => 'required|array|list|max:10',
            'stages.*.reviewers.*' => 'required|regex:/^[A-Z]{2}[0-9]{4}$/',
        ]);

        $stages = [];
        foreach ($data['stages'] as $stage) {
            $ids = DB::table('users')->whereIn('code', $stage['reviewers'])->pluck('id')->all();
            if (count($ids) !== count($stage['reviewers']) || count($ids) < $stage['required']) {
                throw ValidationException::withMessages(['stages' => 'Provide unique reviewers and a valid approval count for each stage.']);
            }
            foreach ($ids as $id) {
                if (! $this->access->allows($id, self::PERMISSION)) {
                    throw ValidationException::withMessages(['stages' => 'Reviewers must be authorized Main Portal users.']);
                }
            }
            $stages[] = ['reviewers' => $ids, 'required' => (int) $stage['required']];
        }

        DB::transaction(function () use ($business, $data, $stages): void {
            DB::table('role_approval_policies')->upsert([
                'business_id' => $business, 'operation' => $data['operation'], 'stages' => json_encode($stages, JSON_THROW_ON_ERROR),
            ], ['business_id', 'operation'], ['stages']);
            $this->workflow->audit('organization_role.approval_policy_changed', $business, (string) Auth::id(), [
                'operation' => $data['operation'], 'stages' => $stages,
            ]);
        });

        return response()->json(['stage_count' => count($stages)]);
    }

    public function index(string $tenant, string $business): JsonResponse
    {
        $this->access->requirePermission(self::PERMISSION);
        OrganizationBusinessRoles::businessKind($tenant, $business);
        $requests = DB::table('role_approval_requests as approval')
            ->join('organization_business_roles as role', 'role.id', '=', 'approval.role_id')
            ->join('users as target', 'target.id', '=', 'approval.user_id')
            ->where('role.business_id', $business)->where('approval.status', 'pending')
            ->orderBy('approval.id')->simplePaginate(30, ['approval.id', 'approval.assign', 'approval.stage', 'role.name as role', 'target.code']);

        return response()->json($requests)->header('Cache-Control', 'no-store');
    }

    public function decide(Request $request, string $tenant, string $business, string $approval): JsonResponse
    {
        $this->access->requirePermission(self::PERMISSION);
        OrganizationBusinessRoles::businessKind($tenant, $business);
        $decision = $request->validate(['decision' => 'required|in:approve,reject'])['decision'];
        $actor = (string) Auth::id();

        return DB::transaction(function () use ($tenant, $business, $approval, $decision, $actor): JsonResponse {
            $query = DB::table('role_approval_requests')->where('id', $approval);
            $pending = $query->lockForUpdate()->first();
            abort_if($pending === null || ! DB::table('organization_business_roles')->where(['id' => $pending->role_id, 'business_id' => $business])->exists(), 404);
            abort_unless($pending->status === 'pending', 409);
            $stages = json_decode($pending->stages, true, flags: JSON_THROW_ON_ERROR);
            $stage = $stages[$pending->stage];
            abort_unless($actor !== $pending->requester_id && in_array($actor, $stage['reviewers'], true), 403);
            $votes = DB::table('role_approval_votes')->where(['request_id' => $approval, 'stage' => $pending->stage]);
            abort_if((clone $votes)->where('reviewer_id', $actor)->exists(), 409);
            $this->workflow->audit('organization_role.approval_decided', $approval, $actor, ['stage' => $pending->stage, 'decision' => $decision]);
            if ($decision === 'reject') {
                $query->update(['status' => 'rejected']);
                $this->workflow->audit('organization_role.approval_finished', $approval, $actor, ['status' => 'rejected']);

                return response()->json(['status' => 'rejected']);
            }

            DB::table('role_approval_votes')->insert(['request_id' => $approval, 'stage' => $pending->stage, 'reviewer_id' => $actor]);
            $count = $votes->pluck('reviewer_id')->filter(fn (string $user): bool => $this->access->allows($user, self::PERMISSION))->count();
            if ($count < $stage['required']) {
                return response()->json(['status' => 'pending', 'stage' => $pending->stage]);
            }
            if (isset($stages[$pending->stage + 1])) {
                $query->update(['stage' => $pending->stage + 1]);

                return response()->json(['status' => 'pending', 'stage' => $pending->stage + 1]);
            }

            abort_unless($this->access->allows($pending->requester_id, self::PERMISSION), 403);
            abort_unless(DB::table('customer_accounts')->where('user_id', $pending->user_id)->where('tenant_id', $tenant)->lockForUpdate()->first() !== null, 409);
            $binding = ['user_id' => $pending->user_id, 'role_id' => $pending->role_id];
            if (DB::table('organization_role_assignments')->where($binding)->exists() !== (bool) $pending->assign) {
                $this->workflow->apply($binding, $business, (bool) $pending->assign, $pending->requester_id, $approval);
            }
            $query->update(['status' => 'approved']);
            $this->workflow->audit('organization_role.approval_finished', $approval, $actor, ['status' => 'approved']);

            return response()->json(['status' => 'approved']);
        }, attempts: 3);
    }
}
