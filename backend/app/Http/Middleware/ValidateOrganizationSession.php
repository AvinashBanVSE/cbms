<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\SessionGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

final class ValidateOrganizationSession
{
    public const VERSION = 'organization_session_version';

    public function handle(Request $request, Closure $next): Response
    {
        /** @var SessionGuard $guard */
        $guard = Auth::guard('web');
        $session = $request->session();
        $user = $session->get($guard->getName());
        if ($user !== null) {
            $account = DB::table('customer_accounts')->where('user_id', $user)->first(['state', 'session_version']);
            if ($account !== null && ($account->state !== 'Active' || (int) $account->session_version !== $session->get(self::VERSION))) {
                $guard->logout();
                $session->invalidate();
                $session->regenerateToken();
                abort(401, 'This session has ended. Sign in again.');
            }
        }

        return $next($request);
    }
}
