<?php

declare(strict_types=1);

namespace App\Domain;

use DomainException;

final class BusinessStructure
{
    public static function networkConfiguration(int $distributors, int $shops): ?string
    {
        return match (true) {
            $distributors > 0 && $shops > 0 => 'Hybrid Network',
            $distributors > 0 => 'Distribution Network',
            $shops > 0 => 'Retail Network',
            default => null,
        };
    }

    /**
     * Check a proposed child against its authoritative parent record.
     *
     * @param  list<string>  $parentOwners
     * @param  list<string>  $childOwners
     */
    public static function validateChild(string $parentType, string $childType, array $parentOwners, array $childOwners): void
    {
        if ($parentType !== 'Organization' || ! in_array($childType, ['Distributor', 'Shop'], true)) {
            throw new DomainException('A Distributor or Shop requires a direct Parent Organization.');
        }

        foreach ([$parentOwners, $childOwners] as $owners) {
            if ($owners === []) {
                throw new DomainException('Each business requires distinct ownership links.');
            }

            foreach ($owners as $owner) {
                if (! is_string($owner) || trim($owner) === '') {
                    throw new DomainException('An ownership link requires an owner identifier.');
                }
            }

            if (count(array_unique($owners)) !== count($owners)) {
                throw new DomainException('Each business requires distinct ownership links.');
            }
        }

        sort($parentOwners, SORT_STRING);
        sort($childOwners, SORT_STRING);

        if ($parentOwners !== $childOwners) {
            throw new DomainException('Child and parent owner sets must match.');
        }
    }
}
