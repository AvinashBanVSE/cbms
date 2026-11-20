<?php

declare(strict_types=1);

namespace App\Accounts;

use Illuminate\Support\Facades\DB;

final class UserCode
{
    public static function allocate(): string
    {
        // Call inside the provisioning transaction to serialize all account types.
        DB::table('portal_installation')->where('id', 1)->lockForUpdate()->first();
        for ($attempt = 0; $attempt < 50; $attempt++) {
            $code = chr(random_int(65, 90)).chr(random_int(65, 90)).sprintf('%04d', random_int(0, 9999));
            if (! DB::table('users')->where('code', $code)->exists()) {
                return $code;
            }
        }
        abort(503);
    }
}
