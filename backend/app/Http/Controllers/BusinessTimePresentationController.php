<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Authorization\MainPortalAccess;
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
        private readonly BusinessTimePresentation $settings,
    ) {}

    public function show(?string $business = null): JsonResponse
    {
        $this->access->requirePermission('global_business_settings.view');
        $portal = DB::table('portal_installation')->where('id', 1)->value('portal_id');

        return $this->response($this->settings->resolve($portal, $business ?? $portal));
    }

    public function update(Request $request, ?string $business = null): JsonResponse
    {
        $this->access->requirePermission('global_business_settings.manage');
        $input = $request->validate([
            'clock' => ['present', 'nullable', Rule::in(BusinessTimePresentation::CLOCKS)],
            'override_allowed' => 'required|boolean',
            'locked' => 'required|boolean',
            'expected_version' => 'required|integer|min:0',
        ]);

        $result = DB::transaction(function () use ($business, $input): array {
            $portal = DB::table('portal_installation')->where('id', 1)->lockForUpdate()->value('portal_id');
            $this->access->requirePermission('global_business_settings.manage');
            $target = $business ?? $portal;
            $current = $this->settings->resolve($portal, $target);
            abort_if($current['local']['version'] !== (int) $input['expected_version'], 409, 'Reload the current settings before saving.');
            $override = (bool) $input['override_allowed'];
            $locked = (bool) $input['locked'];
            if (($current['scope'] === 'platform' && $override) || ($current['scope'] === 'business' && $locked)) {
                throw ValidationException::withMessages(['context' => 'That option does not apply to this context.']);
            }
            if ($current['scope'] !== 'platform' && $input['clock'] !== null && (! $override || $current['upstream_lock'] !== null)) {
                throw ValidationException::withMessages(['clock' => 'This context must inherit the governing value.']);
            }
            $next = ['clock' => $input['clock'], 'override_allowed' => $override, 'locked' => $locked];
            if ($next === array_intersect_key($current['local'], $next)) {
                return $current;
            }
            $version = $current['local']['version'] + 1;
            DB::table('business_time_presentation')->upsert(
                ['business_id' => $target, ...$next, 'version' => $version],
                ['business_id'],
                ['clock', 'override_allowed', 'locked', 'version'],
            );
            DB::table('portal_events')->insert([
                'id' => Str::uuid7()->toString(), 'action' => 'global_business_settings.time_presentation_changed',
                'actor_id' => Auth::id(), 'subject_id' => $target, 'recorded_at' => now(),
                'details' => json_encode(['before' => $current['local'], 'after' => [...$next, 'version' => $version]], JSON_THROW_ON_ERROR),
            ]);

            return $this->settings->resolve($portal, $target);
        }, attempts: 3);

        return $this->response($result);
    }

    private function response(array $result): JsonResponse
    {
        $result['can_manage'] = $this->access->allows((string) Auth::id(), 'global_business_settings.manage');

        return response()->json($result)->header('Cache-Control', 'no-store');
    }
}
