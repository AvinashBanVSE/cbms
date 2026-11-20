<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\BusinessStructure;
use DomainException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BusinessStructureTest extends TestCase
{
    public static function cases(): array
    {
        return [
            ['Organization', 'Shop', ['a', 'b'], ['b', 'a'], true],
            ['Organization', 'Distributor', ['a'], ['a'], true],
            ['Distributor', 'Shop', ['a'], ['a'], false],
            ['Organization', 'Organization', ['a'], ['a'], false],
            ['Organization', 'Shop', [], [], false],
            ['Organization', 'Shop', ['a'], ['a', 'a'], false],
            ['Organization', 'Shop', ['a'], ['b'], false],
            ['Organization', 'Shop', [''], [''], false],
            ['Organization', 'Shop', [['nested']], ['a'], false],
        ];
    }

    #[DataProvider('cases')]
    public function test_structure(string $parent, string $child, array $owners, array $links, bool $valid): void
    {
        if (! $valid) {
            $this->expectException(DomainException::class);
        } else {
            $this->expectNotToPerformAssertions();
        }

        BusinessStructure::validateChild($parent, $child, $owners, $links);
    }
}
