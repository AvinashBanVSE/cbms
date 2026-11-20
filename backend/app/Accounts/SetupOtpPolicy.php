<?php

declare(strict_types=1);

namespace App\Accounts;

use Illuminate\Support\Facades\DB;

final class SetupOtpPolicy
{
    public const MIN_MINUTES = 1;

    public const MAX_MINUTES = 15;

    public static function minutes(): int
    {
        return (int) (DB::table('portal_setup_otp_policy')->where('id', 1)->value('validity_minutes') ?? 5);
    }
}
