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

        abort_if(DB::table('customer_accounts')->where('user_id', Auth::id())->exists(), 403);
        $portal = DB::table('portal_installation')->where('id', 1)->where('stage', 'ready')->value('portal_id');
        abort_unless($portal !== null && DB::table('portal_grants')->where('user_id', Auth::id())
            ->where('business_id', $portal)->where('permission', $permission)->exists(), 403);
    }
}
