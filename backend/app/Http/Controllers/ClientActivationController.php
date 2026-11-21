<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Clients\ClientActivation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class ClientActivationController extends Controller
{
    public function __construct(private readonly ClientActivation $activation) {}

    /** Tenant Owner/Admin issues (or regenerates) a Desktop activation code for the Tenant. */
    public function issueCode(Request $request, string $tenant): JsonResponse
    {
        abort_unless(Auth::check(), 401);
        $result = $this->activation->issueDesktopCode((string) Auth::id(), $tenant);

        // The code is returned once and never logged; only its digest is persisted.
        return response()->json($result, 201)->header('Cache-Control', 'no-store');
    }

    /**
     * The Desktop client exchanges a code for one instance activation token, scoped to the
     * Tenant. Activation grants no business access by itself: subsequent operations still
     * require the user to sign in by User Code and be a member of the activated Tenant.
     */
    public function consumeCode(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => 'required|string|max:40',
            'tenant_id' => 'required|uuid',
            'device_label' => 'sometimes|nullable|string|max:100',
        ]);
        $result = $this->activation->consumeDesktopCode($data['code'], $data['tenant_id'],
            $data['device_label'] ?? null);

        return response()->json($result, 201)->header('Cache-Control', 'no-store');
    }

    /** The Mobile client binds one active instance to the authenticated customer user. */
    public function activateMobile(Request $request): JsonResponse
    {
        abort_unless(Auth::check(), 401);
        $data = $request->validate(['device_label' => 'sometimes|nullable|string|max:100']);
        $result = $this->activation->activateMobile((string) Auth::id(), $data['device_label'] ?? null);

        return response()->json($result, 201)->header('Cache-Control', 'no-store');
    }

    /** Authorized device list for a Tenant Owner/Admin. */
    public function devices(Request $request, string $tenant): JsonResponse
    {
        abort_unless(Auth::check(), 401);

        return response()->json(['data' => $this->activation->devices((string) Auth::id(), $tenant)])
            ->header('Cache-Control', 'no-store');
    }

    /** Revoke an instance and invalidate its access. */
    public function revoke(Request $request, string $tenant, string $instance): JsonResponse
    {
        abort_unless(Auth::check(), 401);
        $this->activation->revoke((string) Auth::id(), $tenant, $instance);

        return response()->json(['id' => $instance, 'state' => 'Revoked']);
    }
}
