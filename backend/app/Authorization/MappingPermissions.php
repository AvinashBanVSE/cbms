<?php

declare(strict_types=1);

namespace App\Authorization;

use Illuminate\Validation\ValidationException;

final class MappingPermissions
{
    public const MAX_ACTIONS = 4;

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
