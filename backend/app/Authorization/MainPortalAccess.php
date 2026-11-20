<?php

declare(strict_types=1);

namespace App\Authorization;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

final class MainPortalAccess
{
    public function requirePermission(string $permission): void
    {
        abort_unless(Auth::check(), 401);

        abort_unless($this->allows((string) Auth::id(), $permission), 403);
    }

    public function allows(string $user, string $permission): bool
    {
        if (DB::table('customer_accounts')->where('user_id', $user)->exists()) {
            return false;
        }
        $portal = DB::table('portal_installation')->where('id', 1)->where('stage', 'ready')->value('portal_id');

        return $portal !== null && DB::table('portal_grants')->where('user_id', $user)
            ->where('business_id', $portal)->where('permission', $permission)->exists();
    }
}
