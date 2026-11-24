<?php

declare(strict_types=1);

namespace App\Authorization;

use Illuminate\Validation\ValidationException;

final class MappingPermissions
{
    public static function maxActions(): int
    {
        return max(count(PermissionCatalogue::actions('Shop', 'mapping', true)),
            count(PermissionCatalogue::actions('Distributor', 'mapping', true)));
    }

    public static function validate(?string $kind, array $actions): void
    {
        $allowed = $kind === null ? null : (in_array($kind, ['Shop', 'Distributor'], true) ? PermissionCatalogue::actions($kind, 'mapping', true) : null);
        if ($allowed === null || array_diff($actions, $allowed)) {
            throw ValidationException::withMessages([
                'actions' => 'Select permitted actions for a Shop or Distributor in this Organization.',
            ]);
        }
    }
}
