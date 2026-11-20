<?php

declare(strict_types=1);

namespace App\Approval;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class RoleAssignmentWorkflow
{
    public function request(string $business, string $role, string $user, string $actor, bool $assign): array
    {
        $binding = ['user_id' => $user, 'role_id' => $role];
        if (DB::table('organization_role_assignments')->where($binding)->exists() === $assign) {
            return ['assigned' => $assign];
        }

        $pending = DB::table('role_approval_requests')->where($binding)->where('status', 'pending')->first();
        if ($pending !== null && $this->expireIfDue($pending, $actor)) {
            $pending = null;
        }
        if ($pending !== null) {
            abort_unless($pending->requester_id === $actor, 409);

            return ['status' => 'pending', 'id' => $pending->id];
        }

        $policy = DB::table('role_approval_policies')->where('business_id', $business)
            ->where('operation', $assign ? 'assign' : 'revoke')->first(['stages', 'expiry_minutes']);
        $stages = $policy === null ? [] : json_decode($policy->stages, true, flags: JSON_THROW_ON_ERROR);
        if ($stages === []) {
            $this->apply($binding, $business, $assign, $actor);

            return ['assigned' => $assign];
        }

        foreach ($stages as $stage) {
            if (count(array_diff($stage['reviewers'], [$actor])) < $stage['required']) {
                throw ValidationException::withMessages(['approval' => 'The approval policy needs enough reviewers other than the requester.']);
            }
        }

        $id = Str::uuid7()->toString();
        $expiresAt = $policy->expiry_minutes === null ? null : now()->addMinutes((int) $policy->expiry_minutes);
        DB::table('role_approval_requests')->insert([
            ...$binding, 'id' => $id, 'requester_id' => $actor, 'assign' => $assign,
            'stages' => $policy->stages, 'status' => 'pending', 'expires_at' => $expiresAt,
        ]);
        $this->audit('organization_role.approval_requested', $id, $actor, [
            'business_id' => $business, 'role_id' => $role, 'user_id' => $user, 'assign' => $assign,
            'expires_at' => $expiresAt?->toISOString(),
        ]);

        return ['status' => 'pending', 'id' => $id];
    }

    public function expireIfDue(object $request, string $actor): bool
    {
        if ($request->expires_at === null || now()->lt($request->expires_at)) {
            return false;
        }
        DB::table('role_approval_requests')->where('id', $request->id)->update(['status' => 'expired']);
        $this->audit('organization_role.approval_finished', $request->id, $actor, ['status' => 'expired']);

        return true;
    }

    public function cancelPending(string $business, bool $assign, string $actor): int
    {
        $requests = DB::table('role_approval_requests')->where('status', 'pending')->where('assign', $assign)
            ->where(fn ($live) => $live->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->whereIn('role_id', DB::table('organization_business_roles')->where('business_id', $business)->select('id'));
        $ids = $requests->orderBy('id')->pluck('id');
        $requests->update(['status' => 'cancelled']);
        foreach ($ids as $id) {
            $this->audit('organization_role.approval_finished', $id, $actor, [
                'status' => 'cancelled', 'reason' => 'policy_changed', 'business_id' => $business,
            ]);
        }

        return $ids->count();
    }

    public function apply(array $binding, string $business, bool $assign, string $actor, ?string $approval = null): void
    {
        if ($assign) {
            DB::table('organization_role_assignments')->insert($binding);
        } else {
            DB::table('organization_role_assignments')->where($binding)->delete();
        }

        $this->audit($assign ? 'organization_role.assigned' : 'organization_role.revoked', $binding['user_id'], $actor, [
            'role_id' => $binding['role_id'], 'business_id' => $business, 'approval_id' => $approval,
        ]);
    }

    public function audit(string $action, string $subject, string $actor, array $details): void
    {
        DB::table('portal_events')->insert([
            'id' => Str::uuid7()->toString(), 'action' => $action, 'actor_id' => $actor,
            'subject_id' => $subject, 'recorded_at' => now(), 'details' => json_encode($details, JSON_THROW_ON_ERROR),
        ]);
    }
}
