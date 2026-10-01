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
}
