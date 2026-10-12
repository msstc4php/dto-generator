<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Domain\Diagnostic;

use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\ClaimedKeyword;
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
        $checks->expect($a, 'contains');
        $checks->expect($a, 'propertyNames');
        $checks->expect($b, 'contains');
        $checks->expect($b, 'contains');
        $checks->checked($a, 'contains');
        $checks->checked($b, 'propertyNames');

        self::assertSame(
            ['a.yaml#/A propertyNames', 'a.yaml#/B contains'],
            array_map(static fn (ClaimedKeyword $pending): string => $pending->schema()->toString() . ' ' . $pending->keyword(), $checks->unchecked()),
        );
        self::assertTrue($checks->isClaimed('contains'));
        self::assertFalse($checks->isClaimed('not'));
        self::assertFalse((new KeywordChecks())->isClaimed('contains'));
        self::assertSame('a.yaml#/A/propertyNames', $checks->unchecked()[0]->location()->toString());
    }
}
