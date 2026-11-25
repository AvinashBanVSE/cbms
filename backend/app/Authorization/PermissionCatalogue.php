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
     * A change-detection token that moves on EVERY authorization-relevant catalogue change. Any
     * arithmetic over the rows (max(version), SUM(version)+SUM(active)+COUNT(*)) is either not
     * portable or can collide: deactivating a permission bumps its version while dropping its
     * active flag, so the two can cancel and leave the token unchanged across a real catalogue
     * change. A content hash over the authoritative state of both catalogue tables instead
     * moves whenever any relevant row (definition identity, active/assignable flag, version, or
     * per-entity applicability) changes, and uses no boolean arithmetic — so it is identical on
     * SQLite and PostgreSQL. The hash is stable across runs; only its change signals invalidation.
     */
    public static function revision(): string
    {
        $definitions = DB::table('permission_definitions')
            ->orderBy('code')
            ->get(['code', 'name', 'resource', 'action', 'domain', 'active', 'assignable', 'version'])
            ->map(fn (object $row): string => implode("\x1f", [
                (string) $row->code, (string) $row->name, (string) $row->resource,
                (string) $row->action, (string) $row->domain,
                $row->active ? '1' : '0', $row->assignable ? '1' : '0', (string) $row->version,
            ]))->implode("\x1e");

        $applicability = DB::table('permission_applicability')
            ->orderBy('permission_id')->orderBy('entity_kind')
            ->get(['permission_id', 'entity_kind', 'workspace'])
            ->map(fn (object $row): string => implode("\x1f", [
                (string) $row->permission_id, (string) $row->entity_kind, (string) $row->workspace,
            ]))->implode("\x1e");

        return hash('sha256', $definitions."\x1d".$applicability);
    }
}
