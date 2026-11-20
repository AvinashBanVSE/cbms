<?php

declare(strict_types=1);

namespace App\Authorization;

use Illuminate\Validation\ValidationException;

final class MappingPermissions
{
    public const MAX_ACTIONS = 4;

    private const ACTIONS = [
        'Shop' => ['mapping.initiate', 'mapping.confirm', 'mapping.end', 'mapping.history.view'],
        'Distributor' => ['mapping.verify', 'mapping.end', 'mapping.history.view'],
    ];

    public static function actions(): array
    {
        return array_values(array_unique(array_merge(...array_values(self::ACTIONS))));
    }

    public static function validate(?string $kind, array $actions): void
    {
        if (! isset(self::ACTIONS[$kind]) || array_diff($actions, self::ACTIONS[$kind])) {
            throw ValidationException::withMessages([
                'actions' => 'Select permitted actions for a Shop or Distributor in this Organization.',
            ]);
        }
    }
}
