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

        self::assertSame('/other.yaml#/X', $target->toString());
    }

    public function testResolvesRemoteReferences(): void
    {
        $remote = new SchemaLocation('https://example.com/common/v1/api.yaml');

        self::assertSame('https://example.com/schemas.json#/X', Reference::target('HTTPS://Example.com:443/schemas.json#/X', new SchemaLocation('/a.yaml'))->toString());
        self::assertSame('https://example.com/common/v1/money.yaml#/Money', Reference::target('money.yaml#/Money', $remote)->toString());
        self::assertSame('https://example.com/common/v2/money.yaml#', Reference::target('../v2/money.yaml', $remote)->toString());
        self::assertSame('https://example.com/common/v1/api.yaml#/components/schemas/A', Reference::target('#/components/schemas/A', $remote)->toString());
        self::assertSame('https://example.com/a%20b.yaml#', Reference::target('/a%20b.yaml', $remote)->toString());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function unfetchable(): array
    {
        return [
            'scheme' => ['file:///etc/passwd', 'URL "file:///etc/passwd": only http and https are supported.'],
            'credentials' => ['https://u:p@example.com/a.yaml', 'URL "https://u:p@example.com/a.yaml": credentials in a URL are not supported.'],
        ];
    }

    /**
     * @dataProvider unfetchable
     */
    public function testRefusesAReferenceItCannotFetch(string $ref, string $message): void
    {
        $this->expectException(InvalidModel::class);
        $this->expectExceptionMessage($message);

        Reference::target($ref, new SchemaLocation('https://example.com/api.yaml'));
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
