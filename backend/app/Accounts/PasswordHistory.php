<?php

declare(strict_types=1);

namespace App\Accounts;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

final class PasswordHistory
{
    public const MIN_DEPTH = 5;

    public const MAX_DEPTH = 10;

    public static function depth(): int
    {
        $stored = DB::table('portal_credential_settings')->where('id', 1)->value('password_history_count');

        return min(self::MAX_DEPTH, max(self::MIN_DEPTH, (int) ($stored ?? config('portal.password_history_count'))));
    }

    /**
     * Call within the credential-change transaction while holding the account lock.
     */
    public static function retire(string $user, string $previousHash, string $replacement): void
    {
        $history = DB::table('user_password_history')->where('user_id', $user);
        $older = $history->clone()->orderByDesc('sequence')->limit(self::depth() - 1)->pluck('password_hash');
        foreach ($older->prepend($previousHash) as $hash) {
            if (Hash::check($replacement, $hash)) {
                throw ValidationException::withMessages(['password' => 'Choose a password you have not used recently.']);
            }
        }
        $sequence = 1 + (int) $history->max('sequence');
        DB::table('user_password_history')->insert(['user_id' => $user, 'sequence' => $sequence, 'password_hash' => $previousHash]);
        $history->where('sequence', '<=', $sequence - self::MAX_DEPTH)->delete();
    }
}
