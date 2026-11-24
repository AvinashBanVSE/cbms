<?php

declare(strict_types=1);

namespace App\Authorization;

/**
 * A single authorization decision plus the evidence behind it (plan §6: an evaluator must
 * return a decision with a reason and the versions used, not just a role name).
 *
 * Introduced in SHADOW mode only: nothing enforces this yet. Existing enforcement paths are
 * unchanged so the team can compare decisions before any cutover.
 */
final class AuthorizationDecision
{
    /**
     * @param  list<string>  $allowed
     * @param  list<string>  $denied
     * @param  array<string, string>  $denyReasons  action => reason
     */
    public function __construct(
        public readonly string $businessId,
        public readonly string $kind,
        public readonly array $allowed,
        public readonly array $denied,
        public readonly array $denyReasons,
        public readonly string $source,
        public readonly int $catalogueVersion,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'business_id' => $this->businessId,
            'kind' => $this->kind,
            'allowed' => $this->allowed,
            'denied' => $this->denied,
            'deny_reasons' => $this->denyReasons,
            'source' => $this->source,
            'catalogue_version' => $this->catalogueVersion,
        ];
    }
}
