<?php

declare(strict_types=1);

namespace App\Authorization;

use Illuminate\Validation\ValidationException;

final class MappingPermissions
{
    private const ACTIONS = [
        'Shop' => ['mapping.initiate', 'mapping.confirm', 'mapping.end'],
        'Distributor' => ['mapping.verify', 'mapping.end'],
    ];

    public static function validate(?string $kind, array $actions): void
    {
        if (! isset(self::ACTIONS[$kind]) || array_diff($actions, self::ACTIONS[$kind])) {
            throw ValidationException::withMessages([
                'actions' => 'Select permitted actions for a Shop or Distributor in this Organization.',
            ]);
        }
    }
}
