<?php

declare(strict_types=1);

namespace App\Domain;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

final class ExternalLegalIdentityVerifier
{
    public function verify(string $name, string $reference): void
    {
        $url = config('portal.legal_verifier_url');
        $token = config('portal.legal_verifier_token');
        $parts = is_string($url) ? parse_url($url) : false;
        abort_unless(is_array($parts) && ($parts['scheme'] ?? '') === 'https' && isset($parts['host'])
            && ! isset($parts['user']) && ! isset($parts['pass']) && ! isset($parts['fragment'])
            && is_string($token) && trim($token) !== '', 503, 'External legal verification is unavailable.');
        try {
            $response = Http::acceptJson()->withToken($token)->withoutRedirecting()->connectTimeout(2)->timeout(5)
                ->post($url, ['legal_name' => $name, 'evidence_reference' => $reference]);
        } catch (ConnectionException) {
            abort(503, 'External legal verification is unavailable.');
        }
        abort_unless($response->successful(), 503, 'External legal verification is unavailable.');
        $result = $response->json();
        abort_unless(is_array($result) && is_bool($result['verified'] ?? null), 503, 'External legal verification returned an invalid response.');
        abort_unless($result['verified'] && ($result['legal_name'] ?? null) === $name
            && ($result['evidence_reference'] ?? null) === $reference, 422, 'The registry did not confirm this legal identity.');
    }
}
