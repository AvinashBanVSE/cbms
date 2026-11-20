<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Tests\TestCase;

final class PasswordVerifierTest extends TestCase
{
    public static function unavailable(): array
    {
        return [[503, ''], [200, ''], [200, 'invalid:1']];
    }

    #[DataProvider('unavailable')]
    public function test_requires_a_usable_range_response(int $status, string $body): void
    {
        Http::fake(['*' => Http::response($body, $status)]);
        $this->expectException(ServiceUnavailableHttpException::class);
        app(UncompromisedVerifier::class)->verify(['value' => 'sample', 'threshold' => 0]);
    }

    public function test_distinguishes_compromised_passwords_and_padding(): void
    {
        $suffix = substr(strtoupper(sha1('sample')), 5);
        Http::fake(['*' => Http::response($suffix.':2')]);
        $verifier = app(UncompromisedVerifier::class);
        $this->assertFalse($verifier->verify(['value' => 'sample', 'threshold' => 0]));
        $this->assertTrue($verifier->verify(['value' => 'other', 'threshold' => 0]));
    }
}
