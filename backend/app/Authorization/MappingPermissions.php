<?php

declare(strict_types=1);

namespace App\Authorization;

use Illuminate\Validation\ValidationException;

final class MappingPermissions
{
    /**
     * A User / Role / Delegation / Access assignment may carry at most the full symmetric
     * mapping verb set (7 actions per kind), so the cap matches the catalogue width.
     */
    public const MAX_ACTIONS = 7;

    public static function validate(?string $kind, array $actions): void
    {
        $allowed = $kind === null ? null : (PermissionCatalogue::MAPPING_BY_BUSINESS[$kind] ?? null);
        if ($allowed === null || array_diff($actions, $allowed)) {
            throw ValidationException::withMessages([
                'actions' => 'Select permitted actions for a Shop or Distributor in this Organization.',
            ]);
        }
    }
}
