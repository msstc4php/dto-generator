<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Application\Port;

use MSSTC4PHP\DtoGenerator\Application\Port\PhpRequirement;
use PHPUnit\Framework\TestCase;

final class PhpRequirementTest extends TestCase
{
    public function testDescribesTheThreeOutcomes(): void
    {
        $none = PhpRequirement::none();
        $found = PhpRequirement::found('/p/composer.json', '^8.1');
        $unusable = PhpRequirement::unusable('/p/composer.json', 'is not valid JSON');

        self::assertSame([null, null, null], [$none->file(), $none->constraint(), $none->problem()]);
        self::assertSame(['/p/composer.json', '^8.1', null], [$found->file(), $found->constraint(), $found->problem()]);
        self::assertSame(['/p/composer.json', null, 'is not valid JSON'], [$unusable->file(), $unusable->constraint(), $unusable->problem()]);
    }

    public function testDescribesItsProblemWithTheFile(): void
    {
        self::assertSame('/p/composer.json is not valid JSON', PhpRequirement::unusable('/p/composer.json', 'is not valid JSON')->problemDescription());
        self::assertNull(PhpRequirement::found('/p/composer.json', '^8.1')->problemDescription());
    }
}
