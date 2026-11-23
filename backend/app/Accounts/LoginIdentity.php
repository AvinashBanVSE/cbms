<?php

declare(strict_types=1);

namespace App\Accounts;

use App\Models\User;
use Illuminate\Support\Str;

final class LoginIdentity
{
    /**
     * A globally unique User Code always selects the account. The second
     * identifier is an enabled alias on that same account, never a lookup
     * across tenants or organizations.
     */
    public static function matches(User $user, string $identifier): bool
    {
        $identifier = trim($identifier);
        if (str_contains($identifier, '@')) {
            return (bool) $user->getAttribute('email_login_enabled')
                && hash_equals(Str::lower((string) $user->email), Str::lower($identifier));
        }

        if (preg_match('/^[6-9][0-9]{9}$/D', $identifier) === 1) {
            return (bool) $user->getAttribute('mobile_login_enabled')
                && is_string($user->getAttribute('mobile_number'))
                && hash_equals((string) $user->getAttribute('mobile_number'), $identifier);
        }

        return (bool) $user->getAttribute('username_login_enabled')
            && is_string($user->getAttribute('username'))
            && hash_equals(Str::lower((string) $user->getAttribute('username')), Str::lower($identifier));
    }
}
