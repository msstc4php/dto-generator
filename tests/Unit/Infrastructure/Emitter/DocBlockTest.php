<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Infrastructure\Emitter;

use MSSTC4PHP\DtoGenerator\Infrastructure\Emitter\DocBlock;
use PHPUnit\Framework\TestCase;

final class DocBlockTest extends TestCase
{
    public function testRendersNothingWithoutContent(): void
    {
        self::assertNull(DocBlock::render(null, []));
        self::assertNull(DocBlock::render("  \n ", []));
    }

    public function testRendersADescription(): void
    {
        self::assertSame("/**\n * A user.\n */", DocBlock::render('A user.', []));
    }

    public function testSeparatesTheDescriptionFromTags(): void
    {
        self::assertSame("/**\n * The id.\n *\n * @var positive-int\n * @deprecated\n */", DocBlock::render('The id.', ['@var positive-int', '@deprecated']));
    }

    public function testRendersTagsAlone(): void
    {
        self::assertSame("/**\n * @return list<int>\n */", DocBlock::render(null, ['@return list<int>']));
    }

    public function testKeepsLinesAndEscapesTheCommentTerminator(): void
    {
        self::assertSame(
            "/**\n * First line.\n *\n * Then *\\/ here.\n */",
            DocBlock::render("\r\nFirst line.  \r\n\r\nThen */ here.\n", []),
        );
    }
}
