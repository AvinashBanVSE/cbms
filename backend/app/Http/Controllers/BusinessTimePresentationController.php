<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Authorization\MainPortalAccess;
use App\Authorization\OrganizationTimePresentationAccess;
use App\Configuration\BusinessTimePresentation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class BusinessTimePresentationController
{
    public function __construct(
        private readonly MainPortalAccess $access,
        private readonly OrganizationTimePresentationAccess $organizations,
        private readonly BusinessTimePresentation $settings,
    ) {}

    public function show(?string $business = null, string $setting = 'clock'): JsonResponse
    {
        $this->access->requirePermission('global_business_settings.view');
        $portal = DB::table('portal_installation')->where('id', 1)->value('portal_id');

        return $this->response($this->settings->resolve($portal, $business ?? $portal, $setting));
    }

    public function update(Request $request, ?string $business = null, string $setting = 'clock'): JsonResponse
    {
        $this->access->requirePermission('global_business_settings.manage');
        $input = $request->validate([
            ...$this->valueRules($setting),
            'override_allowed' => 'required|boolean',
            'locked' => 'required|boolean',
            'expected_version' => 'required|integer|min:0',
        ]);

        if ($setting === 'working_days' && $input[$setting] !== null) {
            sort($input[$setting], SORT_STRING);
        }
        $result = DB::transaction(function () use ($business, $input, $setting): array {
            $portal = DB::table('portal_installation')->where('id', 1)->lockForUpdate()->value('portal_id');
            $this->access->requirePermission('global_business_settings.manage');
            $target = $business ?? $portal;
            $current = $this->settings->resolve($portal, $target, $setting);
            abort_if($current['local']['version'] !== (int) $input['expected_version'], 409, 'Reload the current settings before saving.');
            $override = (bool) $input['override_allowed'];
            $locked = (bool) $input['locked'];
            if (($current['scope'] === 'platform' && $override) || ($current['scope'] === 'business' && $locked)) {
                throw ValidationException::withMessages(['context' => 'That option does not apply to this context.']);
            }
            if ($current['scope'] !== 'platform' && $input[$setting] !== null && (! $override || $current['upstream_lock'] !== null)) {
                throw ValidationException::withMessages([$setting => 'This context must inherit the governing value.']);
            }
            $next = [$setting => $input[$setting], 'override_allowed' => $override, 'locked' => $locked];

            return $this->persist($portal, $current, $next, $setting);
        }, attempts: 3);

        return $this->response($result);
    }

    public function showOrganization(string $setting = 'clock'): JsonResponse
    {
        $business = $this->organizations->requireOrganization(setting: $setting);
        $portal = DB::table('portal_installation')->where('id', 1)->value('portal_id');

        return $this->organizationResponse($this->settings->resolve($portal, $business, $setting));
    }

    public function updateOrganization(Request $request, string $setting = 'clock'): JsonResponse
    {
        $this->organizations->requireOrganization(setting: $setting);
        $input = $request->validate([
            ...$this->valueRules($setting),
            'expected_version' => 'required|integer|min:0',
            'override_allowed' => 'prohibited',
            'locked' => 'prohibited',
        ]);
        if ($setting === 'working_days' && $input[$setting] !== null) {
            sort($input[$setting], SORT_STRING);
        }
        $result = DB::transaction(function () use ($input, $setting): array {
            $portal = DB::table('portal_installation')->where('id', 1)->lockForUpdate()->value('portal_id');
            $business = $this->organizations->requireOrganization(lock: true, setting: $setting);
            $current = $this->settings->resolve($portal, $business, $setting);
            abort_unless($current['local']['override_allowed'] && $current['upstream_lock'] === null, 403, 'This Organization must inherit the governing value.');
            abort_if($current['local']['version'] !== (int) $input['expected_version'], 409, 'Reload the current settings before saving.');

            return $this->persist($portal, $current, [
                $setting => $input[$setting], 'override_allowed' => true, 'locked' => $current['local']['locked'],
            ], $setting);
        }, attempts: 3);

        return $this->organizationResponse($result);
    }

    public function setOrganizationAccess(Request $request, string $business, string $setting = 'clock'): JsonResponse
    {
        $this->access->requirePermission('global_business_settings.manage');
        $input = $request->validate(['code' => 'required|regex:/^[A-Z]{2}[0-9]{4}$/D', 'enabled' => 'required|boolean']);
        $enabled = (bool) $input['enabled'];
        DB::transaction(function () use ($business, $input, $enabled, $setting): void {
            DB::table('portal_installation')->where('id', 1)->lockForUpdate()->first(['id']);
            $this->access->requirePermission('global_business_settings.manage');
            $account = DB::table('customer_accounts as account')
                ->join('customer_tenants as tenant', 'tenant.id', '=', 'account.tenant_id')
                ->join('users', 'users.id', '=', 'account.user_id')
                ->join('business_identities as organization', 'organization.id', '=', 'tenant.organization_id')
                ->where(['organization.id' => $business, 'organization.kind' => 'Organization', 'users.code' => $input['code']])
                ->lockForUpdate()->first(['account.user_id']);
            abort_if($account === null, 404);
            $binding = ['user_id' => $account->user_id, 'business_id' => $business, 'action' => OrganizationTimePresentationAccess::PERMISSIONS[$setting]];
            $grants = DB::table('customer_permissions');
            $changed = $enabled ? $grants->insertOrIgnore($binding) : $grants->where($binding)->delete();
            if ($changed > 0) {
                DB::table('portal_events')->insert([
                    'id' => Str::uuid7()->toString(), 'action' => 'global_business_settings.organization_access_changed',
                    'actor_id' => Auth::id(), 'subject_id' => $business, 'recorded_at' => now(),
                    'details' => json_encode(['user_id' => $account->user_id, 'setting' => $setting, 'enabled' => $enabled], JSON_THROW_ON_ERROR),
                ]);
            }
        }, attempts: 3);

        return response()->json(['enabled' => $enabled]);
    }

    public function showPreference(string $setting, ?string $business = null): JsonResponse
    {
        return $this->show($business, $setting);
    }

    public function updatePreference(Request $request, string $setting, ?string $business = null): JsonResponse
    {
        return $this->update($request, $business, $setting);
    }

    public function setPreferenceAccess(Request $request, string $setting, string $business): JsonResponse
    {
        return $this->setOrganizationAccess($request, $business, $setting);
    }

    private function valueRules(string $setting): array
    {
        $allowed = Rule::in(BusinessTimePresentation::OPTIONS[$setting]);

        return $setting === 'working_days'
            ? ['working_days' => 'present|nullable|array|list|max:7', 'working_days.*' => ['required', 'string', 'distinct:strict', $allowed]]
            : [$setting => ['present', 'nullable', $allowed]];
    }

    private function persist(string $portal, array $current, array $next, string $setting): array
    {
        if ($next === array_intersect_key($current['local'], $next)) {
            return $current;
        }
        $target = $current['business_id'];
        $version = $current['local']['version'] + 1;
        $this->settings->persist($target, $setting, [...$next, 'version' => $version]);
        DB::table('portal_events')->insert([
            'id' => Str::uuid7()->toString(), 'action' => 'global_business_settings.time_presentation_changed',
            'actor_id' => Auth::id(), 'subject_id' => $target, 'recorded_at' => now(),
            'details' => json_encode(['setting' => $setting, 'before' => $current['local'], 'after' => [...$next, 'version' => $version]], JSON_THROW_ON_ERROR),
        ]);

        return $this->settings->resolve($portal, $target, $setting);
    }

    private function organizationResponse(array $result): JsonResponse
    {
        $result['can_manage'] = $result['local']['override_allowed'] && $result['upstream_lock'] === null;

        return response()->json($result)->header('Cache-Control', 'no-store');
    }

    private function response(array $result): JsonResponse
    {
        $result['can_manage'] = $this->access->allows((string) Auth::id(), 'global_business_settings.manage');

        return response()->json($result)->header('Cache-Control', 'no-store');
    }
}
