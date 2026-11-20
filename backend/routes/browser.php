<?php

declare(strict_types=1);

use App\Authorization\CustomerBusinessAccess;
use App\Authorization\MainPortalAccess;
use App\Http\Controllers\BusinessMappingController;
use App\Http\Controllers\BusinessStructureController;
use App\Http\Controllers\InstallationController;
use App\Http\Controllers\OrganizationCredentialController;
use App\Http\Controllers\OrganizationUserController;
use App\Http\Controllers\ServiceRegistryController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')->group(function (): void {
    Route::get('csrf', fn () => response()->json(['token' => csrf_token()])->header('Cache-Control', 'no-store'));
    Route::group([], function (): void {
        Route::post('installation/setup', [InstallationController::class, 'setup'])->middleware('throttle:5,1,setup');
        Route::post('installation/owner', [InstallationController::class, 'owner'])->middleware('throttle:5,1,owner');
        Route::post('login', function (Request $request) {
            $credentials = $request->validate(['code' => 'required|regex:/^[A-Z]{2}[0-9]{4}$/', 'email' => 'required|email', 'password' => 'required|string']);
            abort_unless(Auth::attemptWhen($credentials, static function ($user): bool {
                $state = DB::table('customer_accounts')->where('user_id', $user->getAuthIdentifier())->value('state');

                return $state === null || $state === 'Active';
            }), 401);
            $request->session()->regenerate();

            return response()->noContent();
        })->middleware('throttle:5,1,login');
    });
    Route::post('tenants/{tenant}/credential-policy', [OrganizationUserController::class, 'configure'])->whereUuid('tenant');
    Route::post('tenants/{tenant}/organization-users', [OrganizationUserController::class, 'store'])->whereUuid('tenant');
    Route::post('tenants/{tenant}/organization-users/reissue', [OrganizationUserController::class, 'reissue'])->whereUuid('tenant')->middleware('throttle:5,1,organization-reissue');
    Route::post('organization-setup/verify', [OrganizationCredentialController::class, 'verify'])->middleware('throttle:organization-verify');
    Route::post('organization-setup/complete', [OrganizationCredentialController::class, 'complete'])->middleware('throttle:5,1,organization-complete');
    Route::get('mapping-contexts', fn (CustomerBusinessAccess $access) => response()->json($access->mappingContexts())->header('Cache-Control', 'no-store'));
    Route::post('shops/{shop}/mappings', [BusinessMappingController::class, 'initiate'])
        ->whereUuid('shop')->middleware('throttle:10,1,mapping-initiate');
    Route::post('distributors/{distributor}/mappings/{mapping}/verify', [BusinessMappingController::class, 'verifyShop'])
        ->whereUuid(['distributor', 'mapping'])->middleware('throttle:10,1,mapping-verify');
    Route::post('shops/{shop}/mappings/{mapping}/confirm', [BusinessMappingController::class, 'confirm'])
        ->whereUuid(['shop', 'mapping'])->middleware('throttle:10,1,mapping-confirm');
    Route::post('businesses/{business}/mappings/{mapping}/end', [BusinessMappingController::class, 'end'])
        ->whereUuid(['business', 'mapping']);
    Route::get('businesses', [BusinessStructureController::class, 'index']);
    Route::post('businesses', [BusinessStructureController::class, 'store']);
    Route::get('services', [ServiceRegistryController::class, 'index']);
    Route::post('services', [ServiceRegistryController::class, 'createService']);
    Route::post('services/{service}/capabilities', [ServiceRegistryController::class, 'createCapability'])->whereUuid('service');
    Route::get('services/categories', function (MainPortalAccess $access) {
        $access->requirePermission('service_catalogue.view');

        return response()->json(config('services_catalogue'))->header('Cache-Control', 'no-store');
    });
    Route::post('logout', function (Request $request) {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    });
});
