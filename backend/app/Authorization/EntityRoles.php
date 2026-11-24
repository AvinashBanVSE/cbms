<?php

declare(strict_types=1);

namespace App\Authorization;

use App\Approval\RoleAssignmentWorkflow;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class EntityRoles
{
    public function __construct(private readonly EntityRoleAccess $access, private readonly RoleAssignmentWorkflow $audit) {}

    public function requireManager(string $actor, string $business): object
    {
        $entity = DB::table('business_identities')->where('id', $business)->first();
        abort_if($entity === null, 404);
        abort_unless($this->access->manages($actor, $business), 403);

        return $entity;
    }

    public function role(string $business, string $role): object
    {
        $row = DB::table('organization_business_roles')->where(['id' => $role, 'business_id' => $business, 'governed' => true])->first();
        abort_if($row === null, 404);

        return $row;
    }

    public function contents(string $role): array
    {
        return [
            'actions' => DB::table('organization_role_permissions')->where('role_id', $role)->orderBy('action')->pluck('action')->all(),
            'grant_actions' => DB::table('entity_role_grant_limits')->where('role_id', $role)->orderBy('action')->pluck('action')->all(),
        ];
    }

    public function save(string $actor, string $business, array $data, ?string $role = null): array
    {
        return DB::transaction(function () use ($actor, $business, $data, $role): array {
            $this->lock($business);
            $entity = $this->requireManager($actor, $business);
            $this->validateContents($actor, $business, $entity->kind, $data);
            if ($role !== null) {
                $current = $this->role($business, $role);
                abort_unless(! $current->archived && (int) $current->version === (int) $data['expected_version'], 409, 'Reload the role before editing.');
                abort_if(DB::table('organization_role_assignments')->where('role_id', $role)->exists(), 409, 'Revoke existing assignments before changing this role.');
                $version = (int) $current->version + 1;
                DB::table('organization_business_roles')->where('id', $role)->update(['name' => $data['name'], 'version' => $version]);
                DB::table('organization_role_permissions')->where('role_id', $role)->delete();
                DB::table('entity_role_grant_limits')->where('role_id', $role)->delete();
            } else {
                $role = (string) Str::uuid7();
                $version = 1;
                DB::table('organization_business_roles')->insert(['id' => $role, 'business_id' => $business, 'name' => $data['name'], 'governed' => true]);
            }
            foreach (['actions' => 'organization_role_permissions', 'grant_actions' => 'entity_role_grant_limits'] as $field => $table) {
                foreach ($data[$field] as $action) {
                    DB::table($table)->insert(['role_id' => $role, 'action' => $action]);
                }
            }
            $this->changed($business, $actor, $role, 'saved', ['version' => $version, ...$data]);

            return ['id' => $role, 'version' => $version];
        }, attempts: 3);
    }

    public function copy(string $actor, string $business, string $sourceBusiness, string $sourceRole, string $name, int $version): array
    {
        return DB::transaction(function () use ($actor, $business, $sourceBusiness, $sourceRole, $name, $version): array {
            $locks = array_unique([$business, $sourceBusiness]);
            sort($locks);
            foreach ($locks as $id) {
                $this->lock($id);
            }
            $this->requireManager($actor, $sourceBusiness);
            $source = $this->role($sourceBusiness, $sourceRole);
            abort_unless(! $source->archived && (int) $source->version === $version, 409);
            $result = $this->save($actor, $business, ['name' => $name, 'actions' => $this->contents($sourceRole)['actions'], 'grant_actions' => []]);
            DB::table('organization_business_roles')->where('id', $result['id'])->update(['template_id' => $sourceRole, 'template_version' => $version]);
            $this->audit->audit('entity_role.copied', $result['id'], $actor, ['business_id' => $business, 'source_role' => $sourceRole, 'source_version' => $version]);

            return $result;
        }, attempts: 3);
    }

    public function assign(string $actor, string $business, string $role, string $user, int $version, array $reviewers): array
    {
        return DB::transaction(function () use ($actor, $business, $role, $user, $version, $reviewers): array {
            $this->lock($business);
            $entity = $this->requireManager($actor, $business);
            $row = $this->role($business, $role);
            abort_unless(! $row->archived && (int) $row->version === $version, 409);
            $contents = $this->contents($role);
            $this->validateContents($actor, $business, $entity->kind, $contents);
            abort_unless($this->access->eligible($user, $business), 422, 'The user requires a valid association and explicit scope for this entity.');
            $binding = ['role_id' => $role, 'user_id' => $user];
            if (DB::table('organization_role_assignments')->where($binding)->exists()) {
                return ['assigned' => true];
            }
            // A retry must not create a second pending request; obsolete requests can be replaced.
            $fingerprint = $this->fingerprint($business, $role, $actor, $user);
            $pending = DB::table('entity_role_changes')->where($binding)->where('status', 'pending')->first();
            if ($pending !== null) {
                if ($pending->fingerprint === $fingerprint && ($pending->expires_at === null || now()->lt($pending->expires_at))) {
                    abort_unless($pending->requester_id === $actor, 409);

                    return ['status' => 'pending', 'id' => $pending->id];
                }
                DB::table('entity_role_changes')->where('id', $pending->id)->update(['status' => 'invalidated']);
                $this->audit->audit('entity_role.approval_invalidated', $pending->id, $actor, ['business_id' => $business]);
            }
            $policy = DB::table('role_approval_policies')->where(['business_id' => $business, 'operation' => 'assign'])->first();
            $stages = $policy === null ? [] : json_decode($policy->stages, true, flags: JSON_THROW_ON_ERROR);
            $mandatory = $contents['grant_actions'] !== []
                || array_intersect($contents['actions'], ['entity_roles.manage', 'entity_roles.approve']) !== []
                || DB::table('permission_definitions')->whereIn('code', $contents['actions'])->where('requires_approval', true)->exists();
            if ($mandatory && $stages === []) {
                abort_if($reviewers === [], 422, 'Choose an independent authorized reviewer.');
                $stages = [['reviewers' => $reviewers, 'required' => 1]];
            }
            $actions = array_unique([...$contents['actions'], ...$contents['grant_actions']]);
            foreach ($stages as $stage) {
                $eligible = array_filter(array_unique($stage['reviewers']), fn (string $id): bool => $id !== $actor && $id !== $user && $this->access->canApprove($id, $business, $actions));
                abort_if($stage['required'] < 1 || count($eligible) < $stage['required'], 422, 'The approval policy needs enough eligible independent reviewers.');
            }
            if ($stages === []) {
                $this->apply($business, $role, $user, $actor);

                return ['assigned' => true];
            }
            $id = (string) Str::uuid7();
            DB::table('entity_role_changes')->insert([
                'id' => $id, ...$binding, 'requester_id' => $actor, 'role_version' => $version,
                'fingerprint' => $fingerprint, 'stages' => json_encode($stages, JSON_THROW_ON_ERROR), 'status' => 'pending',
                'expires_at' => $policy?->expiry_minutes === null ? null : now()->addMinutes($policy->expiry_minutes),
            ]);
            foreach ($stages as $index => $stage) {
                foreach (array_unique($stage['reviewers']) as $reviewer) {
                    DB::table('entity_role_reviewers')->insert(['change_id' => $id, 'stage' => $index, 'user_id' => $reviewer]);
                }
            }
            $this->audit->audit('entity_role.approval_requested', $id, $actor, ['business_id' => $business, ...$binding, 'role_version' => $version]);

            return ['status' => 'pending', 'id' => $id];
        }, attempts: 3);
    }

    public function decide(string $actor, string $business, string $id, string $decision): array
    {
        return DB::transaction(function () use ($actor, $business, $id, $decision): array {
            $this->lock($business);
            $request = DB::table('entity_role_changes')->where('id', $id)->lockForUpdate()->first();
            abort_if($request === null, 404);
            $role = $this->role($business, $request->role_id);
            abort_unless($request->status === 'pending', 409);
            $contents = $this->contents($role->id);
            $actions = array_unique([...$contents['actions'], ...$contents['grant_actions']]);
            $stages = json_decode($request->stages, true, flags: JSON_THROW_ON_ERROR);
            $stage = $stages[$request->stage];
            abort_unless($actor !== $request->requester_id && $actor !== $request->user_id
                && in_array($actor, $stage['reviewers'], true) && $this->access->canApprove($actor, $business, $actions), 403);
            $status = null;
            if ($request->expires_at !== null && now()->gte($request->expires_at)) {
                $status = 'expired';
            } elseif ($role->archived || $role->version !== $request->role_version
                || ! hash_equals($request->fingerprint, $this->fingerprint($business, $role->id, $request->requester_id, $request->user_id))
                || ! $this->access->manages($request->requester_id, $business)
                || ! $this->access->canGrant($request->requester_id, $business, $actions)
                || ! $this->access->eligible($request->user_id, $business)) {
                $status = 'invalidated';
            } elseif ($decision === 'reject') {
                $status = 'rejected';
            }
            if ($status !== null) {
                DB::table('entity_role_changes')->where('id', $id)->update(['status' => $status]);
                $this->audit->audit('entity_role.approval_finished', $id, $actor, ['status' => $status]);

                return ['status' => $status];
            }
            $this->validateContents($request->requester_id, $business, DB::table('business_identities')->where('id', $business)->value('kind'), $contents);
            $vote = ['change_id' => $id, 'stage' => $request->stage, 'user_id' => $actor];
            abort_if(DB::table('entity_role_votes')->where($vote)->exists(), 409);
            DB::table('entity_role_votes')->insert($vote);
            $this->audit->audit('entity_role.approval_decided', $id, $actor, ['stage' => $request->stage, 'decision' => $decision]);
            foreach (array_slice($stages, 0, $request->stage + 1) as $index => $requiredStage) {
                $count = DB::table('entity_role_votes')->where(['change_id' => $id, 'stage' => $index])->pluck('user_id')
                    ->filter(fn (string $user): bool => $user !== $request->requester_id && $user !== $request->user_id && $this->access->canApprove($user, $business, $actions))->count();
                if ($count < $requiredStage['required']) {
                    if ($index < $request->stage) {
                        DB::table('entity_role_changes')->where('id', $id)->update(['status' => 'invalidated']);
                        $this->audit->audit('entity_role.approval_invalidated', $id, $actor, ['reason' => 'earlier_reviewer_authority_changed']);

                        return ['status' => 'invalidated'];
                    }

                    return ['status' => 'pending', 'stage' => $request->stage];
                }
            }
            if (isset($stages[$request->stage + 1])) {
                DB::table('entity_role_changes')->where('id', $id)->increment('stage');

                return ['status' => 'pending', 'stage' => $request->stage + 1];
            }
            $this->apply($business, $role->id, $request->user_id, $request->requester_id);
            DB::table('entity_role_changes')->where('id', $id)->update(['status' => 'approved']);
            $this->audit->audit('entity_role.approval_finished', $id, $actor, ['status' => 'approved']);

            return ['status' => 'approved'];
        }, attempts: 3);
    }

    public function revoke(string $actor, string $business, string $role, string $user, string $reason): void
    {
        DB::transaction(function () use ($actor, $business, $role, $user, $reason): void {
            $this->lock($business);
            $this->requireManager($actor, $business);
            $this->role($business, $role);
            // Explicit emergency restriction; restoring access must use the normal grant workflow.
            DB::table('organization_role_assignments')->where(['role_id' => $role, 'user_id' => $user])->delete();
            $this->changed($business, $actor, $role, 'revoked', ['user_id' => $user, 'reason' => $reason]);
        }, attempts: 3);
    }

    private function validateContents(string $actor, string $business, string $kind, array $data): void
    {
        $all = array_unique([...$data['actions'], ...$data['grant_actions']]);
        abort_if(array_diff($all, PermissionCatalogue::actions($kind, null, true)) !== [], 422, 'Select active assignable permissions applicable to this entity.');
        abort_unless($this->access->canGrant($actor, $business, $all), 403, 'The role exceeds your grant authority.');
        // Granting grant authority itself is reserved to the established governing authority.
        abort_if($data['grant_actions'] !== [] && ! $this->access->portal($actor), 403);
    }

    private function fingerprint(string $business, string $role, string $actor, string $user): string
    {
        $state = [
            DB::table('entity_authorization_versions')->where('business_id', $business)->value('version'),
            $this->contents($role),
            DB::table('permission_definitions')->orderBy('code')->get()->all(),
            DB::table('permission_applicability')->orderBy('permission_id')->orderBy('entity_kind')->get()->all(),
            DB::table('role_approval_policies')->where('business_id', $business)->orderBy('operation')->get()->all(),
        ];
        foreach ([$actor, $user] as $id) {
            foreach (['portal_grants', 'provider_accounts', 'provider_account_permissions', 'customer_accounts', 'customer_business_associations', 'customer_business_scopes', 'entity_user_scopes', 'organization_role_assignments'] as $table) {
                $state[] = DB::table($table)->where('user_id', $id)->get()
                    ->map(fn (object $row): string => json_encode($row, JSON_THROW_ON_ERROR))->sort()->values()->all();
            }
        }

        return hash('sha256', json_encode($state, JSON_THROW_ON_ERROR));
    }

    private function apply(string $business, string $role, string $user, string $actor): void
    {
        DB::table('organization_role_assignments')->insertOrIgnore(['role_id' => $role, 'user_id' => $user]);
        $this->changed($business, $actor, $role, 'assigned', ['user_id' => $user]);
    }

    private function lock(string $business): void
    {
        abort_if(DB::table('business_identities')->where('id', $business)->lockForUpdate()->first() === null, 404);
    }

    private function changed(string $business, string $actor, string $role, string $event, array $details): void
    {
        DB::table('entity_authorization_versions')->insertOrIgnore(['business_id' => $business, 'version' => 0]);
        DB::table('entity_authorization_versions')->where('business_id', $business)->increment('version');
        $this->audit->audit('entity_role.'.$event, $role, $actor, ['business_id' => $business, ...$details]);
    }
}
