<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Application\Service\Schemas\Load;

use MSSTC4PHP\DtoGenerator\Application\Port\Document;
use MSSTC4PHP\DtoGenerator\Application\Service\Schemas\Load\Reads;
use PHPUnit\Framework\TestCase;

final class ReadsTest extends TestCase
{
    public function testIndexesTheAnchorsOfADocumentOnce(): void
    {
        $reads = new Reads();
        $anchors = $reads->anchors(new Document('/p/a.yaml', ['A' => ['$anchor' => 'A']]));

        self::assertSame($anchors, $reads->anchors(new Document('/p/a.yaml', [])));
        self::assertSame(['/A'], $anchors->pointersOf('A'));
        self::assertNotSame($anchors, $reads->anchors(new Document('/p/b.yaml', ['A' => ['$anchor' => 'A']])));
    }
}
