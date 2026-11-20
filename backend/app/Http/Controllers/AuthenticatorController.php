<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Accounts\Authenticator;
use App\Accounts\OrganizationMfaPolicy;
use App\Authorization\ProviderAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

final class AuthenticatorController
{
    private const PENDING = 'pending_authenticator';

    public function show(): JsonResponse
    {
        $user = $this->user();

        return $this->status(DB::table('user_authenticators')->where('user_id', $user)->whereNotNull('secret')->exists());
    }

    public function prepare(Request $request): JsonResponse
    {
        $user = $this->enrollingUser($request);
        $data = $request->validate(['password' => 'required|string|max:72']);
        $this->requirePassword($user, $data['password']);
        abort_if(DB::table('user_authenticators')->where('user_id', $user)->whereNotNull('secret')->exists(), 409);
        $secret = random_bytes(20);
        $request->session()->put(self::PENDING, [
            'user' => $user, 'secret' => Crypt::encryptString($secret), 'expires_at' => now()->addMinutes(5)->timestamp,
        ]);

        return response()->json(['setup_key' => Authenticator::displaySecret($secret)])->header('Cache-Control', 'no-store');
    }

    public function confirm(Request $request): JsonResponse
    {
        $user = $this->enrollingUser($request);
        $data = $request->validate(['password' => 'required|string|max:72', 'code' => 'required|regex:/^[0-9]{6}$/D']);
        $pending = $request->session()->get(self::PENDING);
        abort_unless(is_array($pending) && $pending['user'] === $user && $pending['expires_at'] > now()->timestamp, 409);
        $version = DB::transaction(function () use ($user, $data, $pending, $request): int {
            $this->requirePassword($user, $data['password'], lock: true);
            $this->enrollingUser($request);
            $existing = DB::table('user_authenticators')->where('user_id', $user)->first();
            abort_if($existing?->secret !== null, 409);
            $step = Authenticator::step(Crypt::decryptString($pending['secret']), $data['code'], null);
            abort_if($step === null, 422, 'Enter a current authenticator code.');
            $version = 1 + (int) ($existing?->version ?? 0);
            DB::table('user_authenticators')->upsert([
                'user_id' => $user, 'secret' => $pending['secret'], 'last_used_step' => $step, 'version' => $version,
            ], ['user_id']);
            $this->record($user, 'authenticator.enabled');

            return $version;
        }, attempts: 3);
        $request->session()->forget([self::PENDING, OrganizationMfaPolicy::ENROLLMENT_SESSION]);
        $request->session()->regenerate();
        $request->session()->put(Authenticator::SESSION_VERSION, $version);

        return $this->status(true);
    }

    public function disable(Request $request): JsonResponse
    {
        $user = $this->user();
        $data = $request->validate(['password' => 'required|string|max:72', 'code' => 'required|regex:/^[0-9]{6}$/D']);
        $version = DB::transaction(function () use ($user, $data): int {
            $this->requirePassword($user, $data['password'], lock: true);
            abort_if(OrganizationMfaPolicy::requires($user), 409, 'Your Organization requires an authenticator.');
            $credential = DB::table('user_authenticators')->where('user_id', $user)->whereNotNull('secret')->first();
            abort_if($credential === null, 409);
            abort_unless(Authenticator::consume($credential, $data['code']), 422, 'Enter a current authenticator code.');
            $version = 1 + (int) $credential->version;
            DB::table('user_authenticators')->where('user_id', $user)->update(['secret' => null, 'last_used_step' => null, 'version' => $version]);
            $this->record($user, 'authenticator.disabled');

            return $version;
        }, attempts: 3);
        $request->session()->forget(self::PENDING);
        $request->session()->regenerate();
        $request->session()->put(Authenticator::SESSION_VERSION, $version);

        return $this->status(false);
    }

    private function requirePassword(string $user, string $password, bool $lock = false): void
    {
        $hash = DB::table('users')->where('id', $user)->when($lock, fn ($query) => $query->lockForUpdate())->value('password');
        abort_unless(is_string($hash) && strlen($password) <= 72 && ! str_contains($password, "\0") && Hash::check($password, $hash), 403);
    }

    private function user(): string
    {
        $user = Auth::id();
        abort_if($user === null, 401);

        return (string) $user;
    }

    private function enrollingUser(Request $request): string
    {
        if (Auth::check()) {
            return $this->user();
        }
        $grant = $request->session()->get(OrganizationMfaPolicy::ENROLLMENT_SESSION);
        abort_unless(is_array($grant) && $grant['expires_at'] > now()->timestamp, 401);
        $account = ProviderAccess::sessionAccount($grant['user']);
        abort_unless($account?->state === 'Active' && (int) $account->session_version === $grant['version']
            && OrganizationMfaPolicy::requires($grant['user']), 401);

        return $grant['user'];
    }

    private function status(bool $enabled): JsonResponse
    {
        return response()->json(['enabled' => $enabled])->header('Cache-Control', 'no-store');
    }

    private function record(string $user, string $action): void
    {
        DB::table('portal_events')->insert([
            'id' => Str::uuid7()->toString(), 'action' => $action, 'actor_id' => $user, 'subject_id' => $user, 'recorded_at' => now(),
        ]);
    }
}
