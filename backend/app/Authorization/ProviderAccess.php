<?php

declare(strict_types=1);

namespace App\Authorization;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class ProviderAccess
{
    public const DIRECTORY = 'provider.organizations.view';

    public function directoryFor(string $user): ?object
    {
        return $this->directoryScope($user)->first(['provider.id', 'provider.professional_name']);
    }

    public function directoryScope(string $user): Builder
    {
        return DB::table('provider_accounts as account')
            ->join('business_identities as provider', 'provider.id', '=', 'account.provider_id')
            ->join('provider_account_permissions as grant', 'grant.user_id', '=', 'account.user_id')
            ->where('account.user_id', $user)->where('account.state', 'Active')
            ->where('provider.kind', 'Sub-Portal')->where('provider.operational_state', 'Active')
            ->where('grant.permission', self::DIRECTORY)
            ->whereNotExists(fn ($accounts) => $accounts->selectRaw('1')->from('customer_accounts')->where('user_id', $user));
    }

    public static function sessionAccount(string $user): ?object
    {
        $account = DB::table('customer_accounts')->where('user_id', $user)->first(['state', 'session_version']);

        return $account ?? DB::table('provider_accounts as account')
            ->join('business_identities as provider', 'provider.id', '=', 'account.provider_id')
            ->where('account.user_id', $user)->select('account.session_version')
            ->selectRaw("CASE WHEN provider.kind = 'Sub-Portal' AND provider.operational_state = 'Active' THEN account.state ELSE 'Suspended' END AS state")
            ->first();
    }
}
