<?php

declare(strict_types=1);

namespace App\Accounts;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

final class SetupDelivery
{
    public static function requireMailer(): void
    {
        $transport = config('mail.mailers.'.config('mail.default').'.transport');
        abort_if(! is_string($transport) || in_array($transport, ['log', 'array', 'failover', 'roundrobin'], true), 503);
    }

    public static function send(string $scope, string $code, ?string $temporary, ?string $otp, bool $provider = false): bool
    {
        $account = self::recipient($scope, $code, $provider);
        if ($account === null || ($temporary === null && $otp === null)
            || ! self::matches($temporary, $account->temporary_hash, $account->temporary_expires_at)
            || ! self::matches($otp, $account->otp_hash, $account->otp_expires_at)) {
            return false;
        }
        $body = ["Your CBM User Code is {$code}. Open the CBM Portal and select Set up account."];
        if ($temporary !== null) {
            $body[] = "Temporary password: {$temporary}";
        }
        if ($otp !== null) {
            $body[] = "One-time code: {$otp}";
        }
        try {
            return Mail::raw(implode("\n", $body), fn ($message) => $message->to($account->email)->subject('CBM account setup')) !== null;
        } catch (TransportExceptionInterface) {
            return false;
        }
    }

    private static function recipient(string $scope, string $code, bool $provider): ?object
    {
        $accounts = $provider ? 'provider_accounts' : 'customer_accounts';
        $setups = $provider ? 'provider_user_setups' : 'organization_user_setups';
        $column = $provider ? 'provider_id' : 'tenant_id';
        $query = DB::table($accounts.' as account')
            ->join($setups.' as setup', 'setup.user_id', '=', 'account.user_id')
            ->join('users', 'users.id', '=', 'account.user_id')
            ->where('account.'.$column, $scope)->where('users.code', $code)
            ->where(fn (Builder $eligible) => $eligible
                ->where(['account.state' => 'Pending Verification', 'setup.purpose' => 'setup'])
                ->orWhere(['account.state' => 'Active', 'setup.purpose' => 'recovery']));
        if ($provider) {
            $query->join('business_identities as provider', 'provider.id', '=', 'account.provider_id')
                ->where(['provider.kind' => 'Sub-Portal', 'provider.operational_state' => 'Active']);
        }

        return $query->first(['users.email', 'setup.temporary_hash', 'setup.temporary_expires_at', 'setup.otp_hash', 'setup.otp_expires_at']);
    }

    private static function matches(?string $proof, ?string $hash, ?string $expiresAt): bool
    {
        if ($proof === null) {
            return $hash === null;
        }

        return $hash !== null && $expiresAt !== null && now()->lt($expiresAt) && Hash::check($proof, $hash);
    }
}
