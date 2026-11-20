<?php

declare(strict_types=1);

namespace App\Approval;

use App\Authorization\MainPortalAccess;
use App\Domain\BusinessLifecycle;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class BusinessClosureApproval
{
    public function __construct(
        private readonly MainPortalAccess $access,
        private readonly BusinessLifecycle $lifecycle,
    ) {}

    public function configure(string $business, string $actor, array $input): void
    {
        DB::transaction(function () use ($business, $actor, $input): void {
            $this->business($business);
            $this->access->requirePermission('business_structure.closure_policy');
            $reviewers = DB::table('users')->whereIn('code', $input['reviewers'])->orderBy('id')->pluck('id');
            if ($reviewers->count() !== count($input['reviewers'])
                || $reviewers->contains(fn (string $user): bool => ! $this->access->allows($user, 'business_structure.lifecycle'))) {
                throw ValidationException::withMessages(['reviewers' => 'Select authorized Main Portal lifecycle reviewers.']);
            }
            $policy = ['reviewers' => json_encode($reviewers->all(), JSON_THROW_ON_ERROR), 'expiry_minutes' => $input['expiry_minutes'] ?? null];
            DB::table('business_closure_policies')->upsert(['business_id' => $business, ...$policy], ['business_id'], array_keys($policy));
            $this->record('business.closure_policy_changed', $business, $actor, [
                'reviewers' => $reviewers->all(), 'expiry_minutes' => $policy['expiry_minutes'],
            ]);
        }, attempts: 3);
    }

    public function request(string $business, string $actor, array $command): array
    {
        return DB::transaction(function () use ($business, $actor, $command): array {
            $entity = $this->business($business);
            $this->access->requirePermission('business_structure.lifecycle');
            $operation = $command['operation_id'];
            $existing = DB::table('business_closure_approvals')->where('id', $operation)->first();
            if ($existing !== null) {
                abort_unless($existing->business_id === $business && $existing->requester_id === $actor
                    && json_decode($existing->command, true, flags: JSON_THROW_ON_ERROR) === $command, 409);
                if ($existing->status === 'approved') {
                    return $this->lifecycle->transition($business, $actor, $command);
                }
                abort_unless($existing->status === 'pending' && ($existing->expires_at === null || now()->lt($existing->expires_at)), 409);

                return ['status' => 'pending', 'approval_id' => $operation];
            }

            $policy = DB::table('business_closure_policies')->where('business_id', $business)->first();
            $reviewers = $policy === null ? [] : json_decode($policy->reviewers, true, flags: JSON_THROW_ON_ERROR);
            if ($reviewers === [] || DB::table('business_lifecycle_operations')->where('id', $operation)->exists()) {
                return $this->lifecycle->transition($business, $actor, $command);
            }

            abort_unless((int) $entity->lifecycle_version === $command['expected_version']
                && in_array('Closed', BusinessLifecycle::TRANSITIONS[$entity->operational_state] ?? [], true), 409);
            if (collect($reviewers)->every(fn (string $user): bool => $user === $actor || ! $this->access->allows($user, 'business_structure.lifecycle'))) {
                throw ValidationException::withMessages(['approval' => 'An authorized reviewer other than the requester is required.']);
            }
            $expires = $policy->expiry_minutes === null ? null : now()->addMinutes((int) $policy->expiry_minutes);
            DB::table('business_closure_approvals')->insert([
                'id' => $operation, 'business_id' => $business, 'requester_id' => $actor,
                'command' => json_encode($command, JSON_THROW_ON_ERROR),
                'reviewers' => $policy->reviewers, 'status' => 'pending', 'expires_at' => $expires,
            ]);
            $this->record('business.closure_requested', $operation, $actor, [
                'business_id' => $business, 'expected_version' => $command['expected_version'], 'expires_at' => $expires?->toISOString(),
            ]);

            return ['status' => 'pending', 'approval_id' => $operation];
        }, attempts: 3);
    }

    public function decide(string $business, string $approval, string $actor, string $decision): array
    {
        return DB::transaction(function () use ($business, $approval, $actor, $decision): array {
            $entity = $this->business($business);
            $this->access->requirePermission('business_structure.lifecycle');
            $query = DB::table('business_closure_approvals')->where(['id' => $approval, 'business_id' => $business]);
            $request = $query->lockForUpdate()->first();
            abort_if($request === null, 404);
            abort_unless($request->status === 'pending', 409);
            $reviewers = json_decode($request->reviewers, true, flags: JSON_THROW_ON_ERROR);
            abort_unless($actor !== $request->requester_id && in_array($actor, $reviewers, true), 403);
            $command = json_decode($request->command, true, flags: JSON_THROW_ON_ERROR);
            $status = match (true) {
                $request->expires_at !== null && now()->gte($request->expires_at) => 'expired',
                (int) $entity->lifecycle_version !== $command['expected_version'] => 'stale',
                $decision === 'reject' => 'rejected',
                default => 'approved',
            };
            $result = [];
            if ($status === 'approved') {
                abort_unless($this->access->allows($request->requester_id, 'business_structure.lifecycle'), 403);
                $result = $this->lifecycle->transition($business, $request->requester_id, $command);
            }
            $query->update(['status' => $status]);
            $this->record('business.closure_approval_finished', $approval, $actor, [
                'business_id' => $business, 'status' => $status, 'requester_id' => $request->requester_id,
            ]);

            return ['status' => $status, ...$result];
        }, attempts: 3);
    }

    private function business(string $business): object
    {
        $entity = DB::table('business_identities')->where('id', $business)
            ->whereIn('kind', BusinessLifecycle::KINDS)->lockForUpdate()->first();
        abort_if($entity === null, 404);

        return $entity;
    }

    private function record(string $action, string $subject, string $actor, array $details): void
    {
        DB::table('portal_events')->insert([
            'id' => Str::uuid7()->toString(), 'action' => $action, 'subject_id' => $subject, 'actor_id' => $actor,
            'recorded_at' => now(), 'details' => json_encode($details, JSON_THROW_ON_ERROR),
        ]);
    }
}
