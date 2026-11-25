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
     * change. A content hash over the authoritative state of both catalogue tables instead moves
     * whenever any relevant row (definition identity, active/assignable flag, version, or
     * per-entity applicability) changes. Booleans are compared by truthiness, not arithmetic, so
     * the token is identical on SQLite and PostgreSQL. Every field is length-prefixed and a null
     * is encoded distinctly from the empty string (they mean different things for a nullable
     * workspace), so no two distinct states can serialize the same. The hash is stable across
     * runs; only its CHANGE signals invalidation.
     */
    public static function revision(): string
    {
        // Encode a field list unambiguously. A null is tagged "n;" and a string "s<len>;<value>",
        // so a null never equals the empty string, no string can encode as a null, and the length
        // prefix makes each field self-delimiting. The encoding is therefore injective: two
        // different catalogue states cannot hash alike.
        $field = static fn (?string ...$values): string => implode('', array_map(
            static fn (?string $value): string => $value === null ? 'n;' : 's'.strlen($value).';'.$value,
            $values,
        ));

        $definitions = DB::table('permission_definitions')
            ->orderBy('code')
            ->get(['code', 'name', 'resource', 'action', 'domain', 'active', 'assignable', 'version'])
            ->map(fn (object $row): string => $field(
                (string) $row->code, (string) $row->name, (string) $row->resource,
                (string) $row->action, (string) $row->domain,
                $row->active ? '1' : '0', $row->assignable ? '1' : '0', (string) $row->version,
            ))->implode("\x1e");

        $applicability = DB::table('permission_applicability')
            ->orderBy('permission_id')->orderBy('entity_kind')
            ->get(['permission_id', 'entity_kind', 'workspace'])
            ->map(fn (object $row): string => $field(
                (string) $row->permission_id, (string) $row->entity_kind, $row->workspace,
            ))->implode("\x1e");

        return hash('sha256', $definitions."\x1d".$applicability);
    }
}
