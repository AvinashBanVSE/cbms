<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Authorization\EntityRoleAccess;
use App\Authorization\PermissionCatalogue;
use App\Authorization\ShadowComparison;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Shadow-mode comparison trigger (plan §6 controlled cutover, part 1 of 2).
 *
 * Runs the read-only shadow comparison between the central evaluator and the legacy enforcement
 * class for one business and returns the parity result. It enforces nothing and changes no access
 * decision; it exists so the team can collect parity evidence before any cutover. Manager-gated and
 * bounded to a real, governed business.
 */
final class ShadowComparisonController
{
    public function __construct(private readonly EntityRoleAccess $access) {}

    public function __invoke(Request $request, ShadowComparison $comparison): JsonResponse
    {
        $user = Auth::id();
        abort_if($user === null, 401);
        $actor = (string) $user;

        $data = $request->validate([
            'business_id' => 'required|uuid',
        ]);
        $business = $data['business_id'];

        $entity = DB::table('business_identities')->where('id', $business)->first();
        abort_if($entity === null, 404);
        abort_unless($this->access->manages($actor, $business), 403);

        $actions = PermissionCatalogue::actions((string) $entity->kind, null, true);

        return response()->json($comparison->compare($actor, $business, $actions))
            ->header('Cache-Control', 'no-store');
    }
}
