<?php

declare(strict_types=1);

namespace App\Authorization;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * D1 governed deny exceptions.
 *
 * An exception is a narrower context in which an otherwise-matching explicit deny is waived (for
 * a specific target record or a governed selector). It can only ever unblock within that narrower
 * context — it never grants an operation the user does not already positively hold, never widens
 * scope, and never lifts a deny for another user. Creating or widening an exception increases
 * access, so it requires restriction-management authority and is audited; expiry is approved when
 * scheduled.
 */
final class DenyExceptions
{
    public const RECORD = 'record';

    public const SELECTOR = 'selector';

    /**
     * The selector scope is NOT currently creatable. It has no approved resource-ownership model
     * that would let a selector name resolve to a governed set of records, and enforcing it as a
     * whole-action waiver (its previous behavior) was an unapproved access expansion (finding 5.5).
     * Rather than accept a selector that cannot be resolved, creation fails closed: only the
     * resource-aware "record" scope is granted. Supporting selector scope properly requires an
     * approved selector-resolution model and is tracked as outstanding work, not improvised here.
     */
    public const SUPPORTED_SCOPES = [self::RECORD];

    /**
     * Governed create/update of an exception. Requires restriction-management authority for the
     * business and blocks self-granted exceptions (mirrors the deny jurisdiction rules). Version
     * guard prevents concurrent lost updates.
     */
    public function save(string $actor, string $business, string $user, array $data): array
    {
        return DB::transaction(function () use ($actor, $business, $user, $data): array {
            $access = new EntityRoleAccess;
            abort_unless($access->manages($actor, $business), 403, 'You do not manage restrictions for this entity.');
            abort_if($actor === $user, 403, 'You cannot grant yourself a restriction exception.');

            $deny = DB::table('entity_permission_denies')
                ->where(['business_id' => $business, 'user_id' => $user, 'action' => $data['action']])
                ->lockForUpdate()->first();
            abort_if($deny === null, 422, 'An exception can only be scoped to an existing restriction.');

            $scope = $data['scope'];
            abort_unless(in_array($scope, self::SUPPORTED_SCOPES, true), 422,
                'Only record-scoped exceptions are supported; selector scope requires a resolution model that is not yet approved.');
            abort_if($scope === self::RECORD && empty($data['target_id']), 422, 'A record exception needs a target record.');

            $targetId = $data['target_id'] ?? null;
            $expiresAt = $data['expires_at'] ?? null;
            $selector = null;
            $expected = $data['expected_version'] ?? null;
            $existing = DB::table('entity_deny_exceptions')
                ->where(['deny_id' => $deny->id, 'business_id' => $business, 'target_id' => $targetId])
                ->lockForUpdate()->first();

            if ($existing !== null && (int) $existing->version !== (int) $expected) {
                abort(409, 'The exception changed. Reload and retry.');
            }

            $id = $existing->id ?? (string) Str::uuid7();
            $version = ($existing !== null) ? (int) $existing->version + 1 : 1;

            DB::table('entity_deny_exceptions')->updateOrInsert(
                ['id' => $id],
                [
                    'deny_id' => $deny->id, 'business_id' => $business, 'user_id' => $user, 'action' => $data['action'],
                    'scope' => $scope, 'target_id' => $targetId, 'selector' => $selector,
                    'reason' => $data['reason'], 'granted_by' => $actor,
                    'expires_at' => $expiresAt, 'active' => true, 'version' => $version,
                    'created_at' => now(), 'updated_at' => now(),
                ],
            );

            DB::table('portal_events')->insert([
                'id' => Str::uuid7()->toString(), 'action' => 'authorization.deny_exception',
                'actor_id' => $actor, 'subject_id' => $user, 'recorded_at' => now(),
                'details' => json_encode([
                    'business_id' => $business, 'deny_id' => $deny->id, 'action' => $data['action'],
                    'scope' => $scope, 'target_id' => $targetId, 'selector' => $selector,
                    'expires_at' => $expiresAt, 'version' => $version,
                ]),
            ]);

            AuthorizationVersion::bump($business);

            return ['id' => $id, 'version' => $version];
        }, attempts: 3);
    }

    public function remove(string $actor, string $business, string $id): void
    {
        DB::transaction(function () use ($actor, $business, $id): void {
            abort_unless((new EntityRoleAccess)->manages($actor, $business), 403);
            $exception = DB::table('entity_deny_exceptions')->where(['id' => $id, 'business_id' => $business])->first();
            abort_if($exception === null, 404);

            DB::table('entity_deny_exceptions')->where('id', $id)->update(['active' => false, 'updated_at' => now()]);
            AuthorizationVersion::bump($business);
            DB::table('portal_events')->insert([
                'id' => Str::uuid7()->toString(), 'action' => 'authorization.deny_exception_removed',
                'actor_id' => $actor, 'subject_id' => $exception->user_id, 'recorded_at' => now(),
                'details' => json_encode(['business_id' => $business, 'exception_id' => $id]),
            ]);
        }, attempts: 3);
    }
}
