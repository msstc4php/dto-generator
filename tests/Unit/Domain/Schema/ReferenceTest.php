<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Domain\Schema;

use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Reference;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;
use PHPUnit\Framework\TestCase;

final class ReferenceTest extends TestCase
{
    /**
     * @dataProvider targets
     */
    public function testResolvesAgainstTheReferringFile(string $ref, string $expected): void
    {
        $from = new SchemaLocation('/spec/api/openapi.yaml', '/components/schemas/User/properties/tag');
        $target = Reference::target($ref, $from);

        self::assertNotNull($target);
        self::assertSame($expected, $target->toString());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function targets(): array
    {
        return [
            'internal' => ['#/components/schemas/Tag', '/spec/api/openapi.yaml#/components/schemas/Tag'],
            'external file and pointer' => ['common.json#/definitions/Money', '/spec/api/common.json#/definitions/Money'],
            'parent directory' => ['../shared/x.yaml#/X', '/spec/shared/x.yaml#/X'],
            'whole file' => ['common.json', '/spec/api/common.json#'],
            'whole current document' => ['#', '/spec/api/openapi.yaml#'],
            'percent-encoded fragment' => ['#/components/schemas/My%20Type', '/spec/api/openapi.yaml#/components/schemas/My Type'],
            'escaped slash stays escaped' => ['#/components/schemas/a~1b', '/spec/api/openapi.yaml#/components/schemas/a~1b'],
            'percent-encoded file' => ['my%20file.yaml#/X', '/spec/api/my file.yaml#/X'],
            'scheme-like text inside the pointer' => ['#/components/schemas/http://x', '/spec/api/openapi.yaml#/components/schemas/http://x'],
        ];
    }

    public function testResolvesFromAFileInTheFilesystemRoot(): void
    {
        $target = Reference::target('other.yaml#/X', new SchemaLocation('/spec.yaml'));

        self::assertNotNull($target);
        self::assertSame('/other.yaml#/X', $target->toString());
    }

    public function testRemoteReferencesYieldNull(): void
    {
        self::assertNull(Reference::target('https://example.com/schemas.json#/X', new SchemaLocation('/a.yaml')));
    }

    /**
     * @dataProvider malformed
     */
    public function testRejectsMalformedReferences(string $ref, string $message): void
    {
        $this->expectException(InvalidModel::class);
        $this->expectExceptionMessage($message);

        Reference::target($ref, new SchemaLocation('/a.yaml'));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function malformed(): array
    {
        return [
            'empty' => ['', 'Empty $ref'],
            'anchor' => ['#User', 'only JSON pointer fragments are supported'],
            'bad escape' => ['#/a~2', 'is not a valid JSON pointer'],
        ];
    }
}
