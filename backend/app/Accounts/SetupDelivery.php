<?php

declare(strict_types=1);

namespace App\Accounts;

use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

final class SetupDelivery
{
    public static function requireMailer(): void
    {
        $transport = config('mail.mailers.'.config('mail.default').'.transport');
        abort_if(! is_string($transport) || in_array($transport, ['log', 'array', 'failover', 'roundrobin'], true), 503);
    }

    public static function send(string $email, string $code, ?string $temporary, ?string $otp): bool
    {
        $body = ["Your CBM User Code is {$code}. Open the CBM Portal and select Set up account."];
        if ($temporary !== null) {
            $body[] = "Temporary password: {$temporary}";
        }
        if ($otp !== null) {
            $body[] = "One-time code: {$otp}";
        }
        try {
            return Mail::raw(implode("\n", $body), fn ($message) => $message->to($email)->subject('CBM account setup')) !== null;
        } catch (TransportExceptionInterface) {
            return false;
        }
    }
}
