<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Authorization\MainPortalAccess;
use App\Configuration\GlobalBusinessSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class GlobalBusinessSettingsController
{
    public function __invoke(MainPortalAccess $access): JsonResponse
    {
        $access->requirePermission('global_business_settings.view');

        return response()->json([
            'stable_core' => GlobalBusinessSettings::STABLE_CORE,
            'platform_defaults' => GlobalBusinessSettings::PLATFORM_DEFAULTS,
            'reference_data' => [
                'units' => array_map(array_keys(...), GlobalBusinessSettings::UNIT_FACTORS),
                'address_fields' => GlobalBusinessSettings::ADDRESS_FIELDS,
            ],
        ])->header('Cache-Control', 'no-store');
    }

    public function convert(Request $request, MainPortalAccess $access): JsonResponse
    {
        $access->requirePermission('global_business_settings.view');
        $input = $request->validate([
            'quantity' => 'required|numeric|min:0|max:1000000000000',
            'from' => 'required|string|max:16',
            'to' => 'required|string|max:16',
        ]);
        foreach (GlobalBusinessSettings::UNIT_FACTORS as $units) {
            if (isset($units[$input['from']], $units[$input['to']])) {
                return response()->json([
                    'quantity' => (float) $input['quantity'] * $units[$input['from']] / $units[$input['to']],
                    'unit' => $input['to'],
                ])->header('Cache-Control', 'no-store');
            }
        }

        throw ValidationException::withMessages(['to' => 'Choose supported units in the same measurement dimension.']);
    }
}
