<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Domain\Model;

use MSSTC4PHP\DtoGenerator\Domain\Model\DocModel;
use PHPUnit\Framework\TestCase;

final class DocModelTest extends TestCase
{
    public function testTrimsTheDescription(): void
    {
        self::assertSame('User e-mail', (new DocModel("  User e-mail \n"))->description());
    }

    public function testTreatsABlankDescriptionAsAbsent(): void
    {
        $doc = new DocModel("   \n");

        self::assertNull($doc->description());
        self::assertTrue($doc->isEmpty());
    }

    public function testDeprecationAloneIsNotEmpty(): void
    {
        $doc = new DocModel(null, true);

        self::assertTrue($doc->isDeprecated());
        self::assertFalse($doc->isEmpty());
    }

    public function testNoneIsEmpty(): void
    {
        self::assertTrue(DocModel::none()->isEmpty());
    }
}
