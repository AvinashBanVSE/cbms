<?php

declare(strict_types=1);

namespace App\Authorization;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class PermissionCatalogue
{
    public static function applicable(string $kind): Builder
    {
        return DB::table('permission_definitions as definition')
            ->join('permission_applicability as applicability', 'applicability.permission_id', '=', 'definition.id')
            ->where('definition.active', true)->where('applicability.entity_kind', $kind);
    }

    /** @return array<string, string|null> */
    public static function mainPortal(): array
    {
        return self::applicable('Main Portal')->pluck('applicability.workspace', 'definition.code')->all();
    }

    /** @return list<string> */
    public static function actions(string $kind, ?string $domain = null, bool $assignable = false): array
    {
        return self::applicable($kind)
            ->when($domain !== null, fn (Builder $query) => $query->where('definition.domain', $domain))
            ->when($assignable, fn (Builder $query) => $query->where('definition.assignable', true))
            ->orderBy('definition.code')->pluck('definition.code')->all();
    }

    /** @return array<string, list<string>> */
    public static function mapping(): array
    {
        return ['Shop' => self::actions('Shop', 'mapping'), 'Distributor' => self::actions('Distributor', 'mapping')];
    }

    /**
     * A revision token that changes on EVERY authorization-relevant catalogue change. A per-
     * permission max(version) is not sufficient: editing a permission that is not the maximum
     * leaves the max unchanged, so a cached decision keyed on it stays valid across a real
     * catalogue change. Summing the per-definition version plus the active/definition counts
     * means any edit, activation/deactivation, or added/removed definition moves the token.
     * permission_definitions is the single source of truth — no separate counter to drift.
     */
    public static function revision(): int
    {
        return (int) (DB::table('permission_definitions')
            ->selectRaw('COALESCE(SUM(version), 0) + COALESCE(SUM(active), 0) + COUNT(*) AS revision')
            ->value('revision') ?? 0);
    }
}
