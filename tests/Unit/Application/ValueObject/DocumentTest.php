<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Application\ValueObject;

use InvalidArgumentException;
use MSSTC4PHP\DtoGenerator\Application\ValueObject\Document;
use PHPUnit\Framework\TestCase;

final class DocumentTest extends TestCase
{
    /**
     * @dataProvider unusablePaths
     */
    public function testRequiresAnAbsoluteNormalizedPath(string $path): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be absolute and normalized');

        new Document($path, []);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unusablePaths(): array
    {
        return [
            'relative' => ['api/openapi.yaml'],
            'not normalized' => ['/project/../api/openapi.yaml'],
        ];
    }
}
