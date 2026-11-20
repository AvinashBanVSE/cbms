<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Accounts\Authenticator;
use App\Accounts\OrganizationMfaPolicy;
use App\Authorization\ProviderAccess;
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
            $account = ProviderAccess::sessionAccount((string) $user);
            $authenticator = DB::table('user_authenticators')->where('user_id', $user)->first(['version', 'secret']);
            if (($account !== null && ($account->state !== 'Active' || (int) $account->session_version !== $session->get(self::VERSION)))
                || ($authenticator !== null && (int) $authenticator->version !== $session->get(Authenticator::SESSION_VERSION))
                || ($authenticator?->secret === null && OrganizationMfaPolicy::requires((string) $user))) {
                $guard->logout();
                $session->invalidate();
                $session->regenerateToken();
                abort(401, 'This session has ended. Sign in again.');
            }
        }

        return $next($request);
    }
}
