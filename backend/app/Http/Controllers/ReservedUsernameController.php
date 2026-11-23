<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Authorization\MainPortalAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ReservedUsernameController
{
    public function index(MainPortalAccess $access): JsonResponse
    {
        $access->requirePermission('organization_users.manage');

        return response()->json([
            'data' => DB::table('reserved_usernames')->orderBy('username')->pluck('username'),
        ])->header('Cache-Control', 'no-store');
    }

    public function store(Request $request, MainPortalAccess $access): JsonResponse
    {
        $access->requirePermission('organization_users.manage');
        $input = $request->validate([
            'username' => ['required', 'string', 'regex:/^[a-z][a-z0-9._-]{2,31}$/iD'],
        ]);
        $username = Str::lower($input['username']);

        DB::transaction(static function () use ($username, $access): void {
            DB::table('portal_installation')->where('id', 1)->lockForUpdate()->first();
            $access->requirePermission('organization_users.manage');
            if (DB::table('users')->whereRaw('LOWER(username) = ?', [$username])->exists()
                || DB::table('reserved_usernames')->where('username', $username)->exists()) {
                throw ValidationException::withMessages(['username' => 'This username cannot be reserved.']);
            }
            DB::table('reserved_usernames')->insert([
                'username' => $username,
                'reserved_by' => Auth::id(),
                'reserved_at' => now(),
            ]);
            self::record('username.reserved');
        }, attempts: 3);

        return response()->json(['username' => $username], 201)->header('Cache-Control', 'no-store');
    }

    public function destroy(string $username, MainPortalAccess $access): Response
    {
        $access->requirePermission('organization_users.manage');
        DB::transaction(static function () use ($username, $access): void {
            DB::table('portal_installation')->where('id', 1)->lockForUpdate()->first();
            $access->requirePermission('organization_users.manage');
            abort_unless(DB::table('reserved_usernames')->where('username', $username)->delete() === 1, 404);
            self::record('username.unreserved');
        }, attempts: 3);

        return response()->noContent();
    }

    private static function record(string $action): void
    {
        DB::table('portal_events')->insert([
            'id' => Str::uuid7()->toString(),
            'actor_id' => Auth::id(),
            'action' => $action,
            'recorded_at' => now(),
        ]);
    }
}
