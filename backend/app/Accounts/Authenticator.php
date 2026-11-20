<?php

declare(strict_types=1);

namespace App\Accounts;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

final class Authenticator
{
    public const SESSION_VERSION = 'authenticator_version';

    public static function displaySecret(string $secret): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        foreach (str_split($secret) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        $output = '';
        foreach (str_split($bits, 5) as $chunk) {
            $output .= $alphabet[bindec(str_pad($chunk, 5, '0'))];
        }

        return $output;
    }

    public static function step(string $secret, string $code, ?int $lastUsed): ?int
    {
        $current = intdiv(now()->timestamp, 30);
        foreach ([$current, $current - 1, $current + 1] as $step) {
            if ($lastUsed !== null && $step <= $lastUsed) {
                continue;
            }
            $digest = hash_hmac('sha1', pack('J', $step), $secret, true);
            $offset = ord($digest[19]) & 15;
            $number = unpack('N', substr($digest, $offset, 4))[1] & 0x7FFFFFFF;
            if (hash_equals(sprintf('%06d', $number % 1000000), $code)) {
                return $step;
            }
        }

        return null;
    }

    /**
     * Call while holding the user row lock in the surrounding transaction.
     */
    public static function consume(object $credential, string $code): bool
    {
        $step = self::step(Crypt::decryptString($credential->secret), $code, $credential->last_used_step === null ? null : (int) $credential->last_used_step);
        if ($step === null) {
            return false;
        }
        DB::table('user_authenticators')->where('user_id', $credential->user_id)->update(['last_used_step' => $step]);

        return true;
    }

    public static function requiresEnrollment(string $user, ?object $credential): bool
    {
        return $credential?->secret === null
            && (($credential?->reenrollment_required ?? false) || OrganizationMfaPolicy::requires($user));
    }

    public static function revokeForTermination(string $user): void
    {
        DB::table('user_authenticators')->where('user_id', $user)
            ->where(fn ($query) => $query->whereNotNull('secret')->orWhere('reenrollment_required', true))
            ->update(['secret' => null, 'last_used_step' => null, 'reenrollment_required' => false, 'version' => DB::raw('version + 1')]);
    }
}
