<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class UserLoginIdentifierController
{
    private const FIELDS = [
        'username', 'mobile_number', 'email_login_enabled',
        'username_login_enabled', 'mobile_login_enabled',
    ];

    public function show(): JsonResponse
    {
        $id = Auth::id();
        abort_if($id === null, 401);

        $current = DB::table('users')->where('id', $id)->first(self::FIELDS);
        abort_if($current === null, 401);

        return response()->json($current)->header('Cache-Control', 'no-store');
    }

    public function update(Request $request): JsonResponse
    {
        $id = Auth::id();
        abort_if($id === null, 401);
        $input = $request->validate([
            'password' => 'required|string|max:72',
            'username' => ['sometimes', 'nullable', 'string', 'regex:/^[a-z][a-z0-9._-]{2,31}$/iD'],
            'mobile_number' => ['sometimes', 'nullable', 'string', 'regex:/^[6-9][0-9]{9}$/D'],
            'email_login_enabled' => 'sometimes|boolean',
            'username_login_enabled' => 'sometimes|boolean',
            'mobile_login_enabled' => 'sometimes|boolean',
        ]);

        $updated = DB::transaction(static function () use ($id, $input): object {
            $user = DB::table('users')->where('id', $id)->lockForUpdate()->first();
            abort_if($user === null, 401);
            abort_unless(Hash::check($input['password'], $user->password), 403);

            $values = [
                'username' => $user->username,
                'mobile_number' => $user->mobile_number,
                'email_login_enabled' => (bool) $user->email_login_enabled,
                'username_login_enabled' => (bool) $user->username_login_enabled,
                'mobile_login_enabled' => (bool) $user->mobile_login_enabled,
            ];
            foreach (self::FIELDS as $field) {
                if (array_key_exists($field, $input)) {
                    $values[$field] = $input[$field];
                }
            }
            if (is_string($values['username'])) {
                $values['username'] = Str::lower($values['username']);
            }
            if (($values['username_login_enabled'] && $values['username'] === null)
                || ($values['mobile_login_enabled'] && $values['mobile_number'] === null)
                || ! ($values['email_login_enabled'] || $values['username_login_enabled'] || $values['mobile_login_enabled'])) {
                throw ValidationException::withMessages([
                    'identifier' => 'Keep at least one login method enabled and set its identifier first.',
                ]);
            }

            DB::table('users')->where('id', $id)->update($values);
            DB::table('portal_events')->insert([
                'id' => Str::uuid7()->toString(),
                'actor_id' => $id,
                'subject_id' => $id,
                'action' => 'account.login_identifiers_changed',
                'recorded_at' => now(),
            ]);

            return (object) $values;
        }, attempts: 3);

        return response()->json($updated)->header('Cache-Control', 'no-store');
    }
}
