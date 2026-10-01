<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Domain\Schema;

use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;
use MSSTC4PHP\DtoGenerator\Domain\Schema\ReferenceUse;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;
use PHPUnit\Framework\TestCase;

final class ReferenceUseTest extends TestCase
{
    public function testNeedsATarget(): void
    {
        $this->expectException(InvalidModel::class);
        $this->expectExceptionMessage('A reference needs a target');

        new ReferenceUse('', new SchemaLocation('/a.yaml'));
    }

    public function testKeysTellUsesApart(): void
    {
        $x = new SchemaLocation('/a.yaml', '/x');
        $xy = new SchemaLocation('/a.yaml', '/xy');
        $keys = [
            (new ReferenceUse('yz', $x))->key(),
            (new ReferenceUse('z', $xy))->key(),
            (new ReferenceUse('z', $x))->key(),
        ];

        self::assertCount(3, array_unique($keys));
    }
}
