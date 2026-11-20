<?php

declare(strict_types=1);

namespace App\Accounts;

use Illuminate\Support\Facades\DB;

final class OrganizationMfaPolicy
{
    public const ENROLLMENT_SESSION = 'required_authenticator_enrollment';

    public static function requires(string $user): bool
    {
        return DB::table('customer_accounts as account')
            ->join('organization_mfa_policies as policy', 'policy.tenant_id', '=', 'account.tenant_id')
            ->where('account.user_id', $user)->exists();
    }
}
