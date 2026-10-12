<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Domain\Diagnostic;

use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\KeywordChecks;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;
use PHPUnit\Framework\TestCase;

final class KeywordChecksTest extends TestCase
{
    public function testKeepsTheOccurrencesNobodyChecked(): void
    {
        $checks = new KeywordChecks(['contains', 'propertyNames']);
        $a = new SchemaLocation('a.yaml', '/A');
        $b = new SchemaLocation('a.yaml', '/B');
        $checks->expect('contains', $a);
        $checks->expect('propertyNames', $a);
        $checks->expect('contains', $b);
        $checks->expect('contains', $b);
        $checks->checked($a, 'contains');
        $checks->checked($b, 'propertyNames');

        self::assertSame(
            ['a.yaml#/A propertyNames', 'a.yaml#/B contains'],
            array_map(static fn (array $pending): string => $pending[1]->toString() . ' ' . $pending[0], $checks->unchecked()),
        );
        self::assertTrue($checks->isClaimed('contains'));
        self::assertFalse($checks->isClaimed('not'));
        self::assertFalse((new KeywordChecks())->isClaimed('contains'));
    }
}
