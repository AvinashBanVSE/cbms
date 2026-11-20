<?php

declare(strict_types=1);

namespace App\Validation;

use Illuminate\Validation\NotPwnedVerifier;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

final class RequiredPasswordVerifier extends NotPwnedVerifier
{
    protected function search($hashPrefix)
    {
        $rows = parent::search($hashPrefix);
        if ($rows->isEmpty() || $rows->contains(
            static fn (string $row): bool => preg_match('/^[A-F0-9]{35}:[0-9]+$/D', trim($row)) !== 1
        )) {
            throw new ServiceUnavailableHttpException(30, 'Password verification is temporarily unavailable.');
        }

        return $rows;
    }
}
