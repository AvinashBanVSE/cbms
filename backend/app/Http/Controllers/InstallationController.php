<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Accounts\UserCode;
use App\Authorization\PermissionCatalogue;
use App\Validation\PermanentPassword;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

final class InstallationController
{
    public function setup(Request $request): JsonResponse
    {
        $this->verifyCode($request);
        $data = $request->validate(['legal_name' => 'required|string|max:200', 'professional_name' => 'required|string|max:200']);

        return DB::transaction(function () use ($data): JsonResponse {
            $id = (string) Str::uuid7();
            abort_unless(DB::table('portal_installation')->where('id', 1)->where('stage', 'setup_required')
                ->update(['stage' => 'owner_required']) === 1, 409);
            DB::table('business_identities')->insert([...$data, 'id' => $id, 'kind' => 'Main Portal']);
            DB::table('portal_installation')->where('id', 1)->update(['portal_id' => $id]);
            $this->record('installation.configured');

            return response()->json(['stage' => 'owner_required'], 201);
        });
    }

    public function owner(Request $request): JsonResponse
    {
        $this->verifyCode($request);
        abort_unless(DB::table('portal_installation')->where('id', 1)->value('stage') === 'owner_required', 409);
        $data = $request->validate([
            'name' => 'required|string|max:200',
            'email' => 'required|email|max:254',
            'password' => PermanentPassword::rules(),
        ]);
        $password = Hash::make($data['password']);

        return DB::transaction(function () use ($data, $password): JsonResponse {
            abort_unless(DB::table('portal_installation')->where('id', 1)->where('stage', 'owner_required')
                ->update(['stage' => 'ready']) === 1, 409);
            $portal = DB::table('portal_installation')->where('id', 1)->value('portal_id');
            $owner = (string) Str::uuid7();
            $user = (string) Str::uuid7();
            $code = UserCode::allocate();
            DB::table('ownership_holders')->insert(['id' => $owner, 'name' => $data['name']]);
            DB::table('ownership_links')->insert(['id' => (string) Str::uuid7(), 'owner_id' => $owner, 'business_id' => $portal]);
            DB::table('users')->insert(['id' => $user, 'email' => $data['email'], 'code' => $code, 'password' => $password]);
            foreach (array_keys(PermissionCatalogue::mainPortal()) as $permission) {
                DB::table('portal_grants')->insert(['user_id' => $user, 'business_id' => $portal, 'permission' => $permission]);
            }
            $this->record('installation.owner_created', $user);

            return response()->json(['stage' => 'ready', 'user_code' => $code], 201)->header('Cache-Control', 'no-store');
        });
    }

    private function verifyCode(Request $request): void
    {
        $digest = (string) config('portal.setup_digest');
        abort_unless($request->hasHeader('X-Setup-Code') && strlen((string) $request->header('X-Setup-Code')) >= 32 && strlen($digest) === 64 && hash_equals($digest, hash('sha256', (string) $request->header('X-Setup-Code'))), 403);
    }

    private function record(string $action, ?string $actor = null): void
    {
        DB::table('portal_events')->insert(['id' => (string) Str::uuid7(), 'action' => $action, 'actor_id' => $actor, 'recorded_at' => now()]);
    }
}
