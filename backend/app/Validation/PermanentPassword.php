<?php

declare(strict_types=1);

namespace App\Validation;

use Closure;
use Illuminate\Validation\Rules\Password;

final class PermanentPassword
{
    public static function rules(): array
    {
        return [
            'bail', 'required', 'string', 'max:72',
            static function (string $attribute, string $value, Closure $fail): void {
                if (strlen($value) > 72 || str_contains($value, "\0")) {
                    $fail('Use a password of at most 72 bytes without null characters.');
                }
            },
            Password::min(15)->uncompromised(),
        ];
    }
}
