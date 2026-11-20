<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Authorization\MainPortalAccess;
use App\Configuration\GlobalBusinessSettings;
use Illuminate\Http\JsonResponse;

final class GlobalBusinessSettingsController
{
    public function __invoke(MainPortalAccess $access): JsonResponse
    {
        $access->requirePermission('global_business_settings.view');

        return response()->json([
            'stable_core' => GlobalBusinessSettings::STABLE_CORE,
            'platform_defaults' => GlobalBusinessSettings::PLATFORM_DEFAULTS,
            'reference_data' => [
                'units' => GlobalBusinessSettings::REFERENCE_UNITS,
                'address_fields' => GlobalBusinessSettings::ADDRESS_FIELDS,
            ],
        ])->header('Cache-Control', 'no-store');
    }
}
