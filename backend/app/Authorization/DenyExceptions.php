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
     * Do any active, unexpired exceptions waive the deny on this action for this user/business?
     * Callers pass the specific target record when enforcing per-record; a null target only
     * matches selector-scope exceptions (never a record-scoped one).
     */
    public static function waives(string $user, string $business, string $action, ?string $target = null): bool
    {
        $match = DB::table('entity_deny_exceptions')
            ->where(['user_id' => $user, 'business_id' => $business, 'action' => $action, 'active' => true])
            ->where(fn ($query): object => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()));

        if ($target === null) {
            $match->where('scope', self::SELECTOR);
        } else {
            $match->where(function ($query) use ($target): void {
                $query->where(fn ($record) => $record->where('scope', self::RECORD)->where('target_id', $target))
                    ->orWhere('scope', self::SELECTOR);
            });
        }

        return $match->exists();
    }

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
            abort_unless(in_array($scope, [self::RECORD, self::SELECTOR], true), 422, 'Choose a supported exception scope.');
            abort_if($scope === self::RECORD && empty($data['target_id']), 422, 'A record exception needs a target record.');
            abort_if($scope === self::SELECTOR && empty($data['selector']), 422, 'A selector exception needs a governed selector.');

            $targetId = $data['target_id'] ?? null;
            $expiresAt = $data['expires_at'] ?? null;
            $selector = $data['selector'] ?? null;
            $expected = $data['expected_version'] ?? null;
            $existing = DB::table('entity_deny_exceptions')
                ->where(['deny_id' => $deny->id, 'business_id' => $business])
                ->where(fn ($query) => $targetId !== null
                    ? $query->where('target_id', $targetId)
                    : $query->where('selector', $selector ?? ''))
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
            DB::table('portal_events')->insert([
                'id' => Str::uuid7()->toString(), 'action' => 'authorization.deny_exception_removed',
                'actor_id' => $actor, 'subject_id' => $exception->user_id, 'recorded_at' => now(),
                'details' => json_encode(['business_id' => $business, 'exception_id' => $id]),
            ]);
        }, attempts: 3);
    }
}
