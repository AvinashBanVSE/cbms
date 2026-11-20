<?php

declare(strict_types=1);

use App\Authorization\CustomerBusinessAccess;
use App\Authorization\MainPortalAccess;
use App\Authorization\ProviderAccess;
use App\Http\Controllers\AccountRecoveryController;
use App\Http\Controllers\AuditTrailController;
use App\Http\Controllers\BusinessLifecycleController;
use App\Http\Controllers\BusinessMappingController;
use App\Http\Controllers\BusinessStructureController;
use App\Http\Controllers\CommercialAddOnController;
use App\Http\Controllers\CommercialBillingController;
use App\Http\Controllers\CommercialController;
use App\Http\Controllers\CredentialSettingsController;
use App\Http\Controllers\InstallationController;
use App\Http\Controllers\OrganizationAccessController;
use App\Http\Controllers\OrganizationAccountLifecycleController;
use App\Http\Controllers\OrganizationCredentialController;
use App\Http\Controllers\OrganizationDelegationController;
use App\Http\Controllers\OrganizationRoleController;
use App\Http\Controllers\OrganizationUserController;
use App\Http\Controllers\ProviderUserController;
use App\Http\Controllers\RoleApprovalController;
use App\Http\Controllers\ServiceProviderMigrationController;
use App\Http\Controllers\ServiceRegistryController;
use App\Http\Controllers\WorkspaceAccessController;
use App\Http\Middleware\ValidateOrganizationSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1')->group(function (): void {
    Route::get('csrf', fn () => response()->json(['token' => csrf_token()])->header('Cache-Control', 'no-store'));
    Route::get('workspace-access', WorkspaceAccessController::class);
    Route::get('credential-settings', [CredentialSettingsController::class, 'show']);
    Route::post('credential-settings', [CredentialSettingsController::class, 'update']);
    Route::get('provider-organizations', [ProviderUserController::class, 'organizations']);
    Route::post('providers/{provider}/users', [ProviderUserController::class, 'store'])->whereUuid('provider');
    Route::post('providers/{provider}/users/reissue', [ProviderUserController::class, 'reissue'])->whereUuid('provider')
        ->middleware('throttle:5,1,provider-setup-reissue');
    Route::post('providers/{provider}/users/revoke', [ProviderUserController::class, 'revoke'])->whereUuid('provider');
    Route::post('providers/{provider}/users/recover', [AccountRecoveryController::class, 'provider'])->whereUuid('provider')
        ->middleware('throttle:10,1,provider-password-recovery');
    Route::get('audit-trail', AuditTrailController::class)->middleware('throttle:60,1,audit-trail');
    Route::group([], function (): void {
        Route::post('installation/setup', [InstallationController::class, 'setup'])->middleware('throttle:5,1,setup');
        Route::post('installation/owner', [InstallationController::class, 'owner'])->middleware('throttle:5,1,owner');
        Route::post('login', function (Request $request) {
            $credentials = $request->validate(['code' => 'required|regex:/^[A-Z]{2}[0-9]{4}$/', 'email' => 'required|email', 'password' => 'required|string']);
            $version = null;
            $authenticated = DB::transaction(static function () use ($credentials, &$version): bool {
                // Serialize password checking and Laravel's optional rehash with credential changes.
                DB::table('users')->where('code', $credentials['code'])->lockForUpdate()->first(['id']);

                return Auth::attemptWhen($credentials, static function ($user) use (&$version): bool {
                    $account = ProviderAccess::sessionAccount((string) $user->getAuthIdentifier());
                    $version = $account === null ? null : (int) $account->session_version;

                    return $account === null || $account->state === 'Active';
                });
            });
            abort_unless($authenticated, 401);
            $request->session()->regenerate();
            $request->session()->put(ValidateOrganizationSession::VERSION, $version);

            return response()->noContent();
        })->middleware('throttle:5,1,login');
    });
    Route::prefix('tenants/{tenant}/businesses/{business}/roles')->whereUuid(['tenant', 'business'])->group(function (): void {
        Route::get('/', [OrganizationRoleController::class, 'index']);
        Route::post('/', [OrganizationRoleController::class, 'store']);
        Route::post('{role}/assign', [OrganizationRoleController::class, 'assign'])->whereUuid('role');
        Route::post('{role}/revoke', [OrganizationRoleController::class, 'revoke'])->whereUuid('role');
    });
    Route::prefix('tenants/{tenant}/businesses/{business}/role-approvals')->whereUuid(['tenant', 'business'])->group(function (): void {
        Route::get('/', [RoleApprovalController::class, 'index']);
        Route::post('policy', [RoleApprovalController::class, 'policy']);
        Route::post('{approval}/decision', [RoleApprovalController::class, 'decide'])->whereUuid('approval');
    });
    Route::get('tenants/{tenant}/organization-users/{code}/lifecycle', [OrganizationAccountLifecycleController::class, 'show'])
        ->whereUuid('tenant')->where('code', '[A-Z]{2}[0-9]{4}');
    Route::post('tenants/{tenant}/organization-users/{code}/lifecycle', [OrganizationAccountLifecycleController::class, 'transition'])
        ->whereUuid('tenant')->where('code', '[A-Z]{2}[0-9]{4}');
    Route::get('organization-access-delegations', [OrganizationDelegationController::class, 'active']);
    Route::post('tenants/{tenant}/businesses/{business}/access-delegations', [OrganizationDelegationController::class, 'grant'])
        ->whereUuid(['tenant', 'business']);
    Route::post('organization-access-delegations/{grant}/revoke', [OrganizationDelegationController::class, 'revoke'])->whereUuid('grant');
    Route::get('tenants/{tenant}/organization-users/{code}/business-access/{business}', [OrganizationAccessController::class, 'show'])
        ->whereUuid(['tenant', 'business'])->where('code', '[A-Z]{2}[0-9]{4}');
    Route::post('tenants/{tenant}/organization-users/{code}/business-access/{business}', [OrganizationAccessController::class, 'update'])
        ->whereUuid(['tenant', 'business'])->where('code', '[A-Z]{2}[0-9]{4}');
    Route::get('tenants/{tenant}/credential-policy', [OrganizationUserController::class, 'effectivePolicy'])->whereUuid('tenant');
    Route::post('providers/{provider}/credential-default', [OrganizationUserController::class, 'configureProvider'])->whereUuid('provider');
    Route::post('tenants/{tenant}/credential-policy', [OrganizationUserController::class, 'configure'])->whereUuid('tenant');
    Route::post('tenants/{tenant}/organization-users', [OrganizationUserController::class, 'store'])->whereUuid('tenant');
    Route::post('tenants/{tenant}/organization-users/reissue', [OrganizationUserController::class, 'reissue'])->whereUuid('tenant')->middleware('throttle:5,1,organization-reissue');
    Route::post('tenants/{tenant}/organization-users/revoke-onboarding', [OrganizationUserController::class, 'revokeOnboarding'])->whereUuid('tenant');
    Route::post('tenants/{tenant}/organization-users/recover', [AccountRecoveryController::class, 'organization'])->whereUuid('tenant')
        ->middleware('throttle:10,1,organization-password-recovery');
    Route::post('organization-setup/verify', [OrganizationCredentialController::class, 'verify'])->middleware('throttle:organization-verify');
    Route::post('organization-setup/complete', [OrganizationCredentialController::class, 'complete'])->middleware('throttle:5,1,organization-complete');
    Route::get('mapping-contexts', fn (CustomerBusinessAccess $access) => response()->json($access->mappingContexts())->header('Cache-Control', 'no-store'));
    Route::post('shops/{shop}/mappings', [BusinessMappingController::class, 'initiate'])
        ->whereUuid('shop')->middleware('throttle:10,1,mapping-initiate');
    Route::post('distributors/{distributor}/mappings/verify', [BusinessMappingController::class, 'verifyShop'])
        ->whereUuid('distributor')->middleware('throttle:10,1,mapping-verify');
    Route::post('shops/{shop}/mappings/confirm', [BusinessMappingController::class, 'confirm'])
        ->whereUuid('shop')->middleware('throttle:10,1,mapping-confirm');
    Route::post('distributors/{distributor}/mappings/{mapping}/verify', [BusinessMappingController::class, 'verifyShop'])
        ->whereUuid(['distributor', 'mapping'])->middleware('throttle:10,1,mapping-verify');
    Route::post('shops/{shop}/mappings/{mapping}/confirm', [BusinessMappingController::class, 'confirm'])
        ->whereUuid(['shop', 'mapping'])->middleware('throttle:10,1,mapping-confirm');
    Route::post('businesses/{business}/mappings/{mapping}/end', [BusinessMappingController::class, 'end'])
        ->whereUuid(['business', 'mapping']);
    Route::get('businesses/{business}/mappings/{mapping}/history', [BusinessMappingController::class, 'history'])
        ->whereUuid(['business', 'mapping']);
    Route::get('businesses/{business}/lifecycle', [BusinessLifecycleController::class, 'show'])->whereUuid('business');
    Route::post('businesses/{business}/lifecycle', [BusinessLifecycleController::class, 'transition'])->whereUuid('business');
    Route::prefix('commercial')->group(function (): void {
        Route::get('invoices', [CommercialBillingController::class, 'index']);
        Route::post('invoices', [CommercialBillingController::class, 'invoice']);
        Route::get('invoices/{invoice}/payments', [CommercialBillingController::class, 'payments'])->whereUuid('invoice');
        Route::post('invoices/{invoice}/payments', [CommercialBillingController::class, 'payment'])->whereUuid('invoice');
        Route::post('invoices/{invoice}/payments/{payment}/reverse', [CommercialBillingController::class, 'reverse'])->whereUuid(['invoice', 'payment']);
        Route::get('/', [CommercialController::class, 'index']);
        Route::post('accounts', [CommercialController::class, 'account']);
        Route::post('entitlements', [CommercialController::class, 'entitlement']);
        Route::post('plans', [CommercialController::class, 'plan']);
        Route::post('plans/{plan}/versions', [CommercialController::class, 'publish'])->whereUuid('plan');
        Route::post('add_ons', [CommercialAddOnController::class, 'store']);
        Route::post('add_ons/{addOn}/versions', [CommercialAddOnController::class, 'publish'])->whereUuid('addOn');
        Route::post('subscriptions/{subscription}/add_ons', [CommercialAddOnController::class, 'attach'])->whereUuid('subscription');
        Route::post('subscriptions', [CommercialController::class, 'subscription']);
        Route::post('subscriptions/{subscription}/transition', [CommercialController::class, 'transition'])->whereUuid('subscription');
        Route::post('subscriptions/{subscription}/renew', [CommercialController::class, 'renew'])->whereUuid('subscription');
        Route::get('businesses/{business}/effective-entitlements', [CommercialController::class, 'effectiveEntitlements'])->whereUuid('business');
    });
    Route::post('provider-migrations/preview', [ServiceProviderMigrationController::class, 'preview']);
    Route::post('provider-migrations/execute', [ServiceProviderMigrationController::class, 'execute']);
    Route::get('businesses', [BusinessStructureController::class, 'index']);
    Route::post('businesses', [BusinessStructureController::class, 'store']);
    Route::get('services', [ServiceRegistryController::class, 'index']);
    Route::post('services', [ServiceRegistryController::class, 'createService']);
    Route::get('services/{service}/capabilities', [ServiceRegistryController::class, 'capabilities'])->whereUuid('service');
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
