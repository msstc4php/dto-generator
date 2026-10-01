<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Domain\Schema;

use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Extensions;
use PHPUnit\Framework\TestCase;

final class ExtensionsTest extends TestCase
{
    public function testExposesValuesInDocumentOrder(): void
    {
        $extensions = new Extensions(['x-php-name' => 'userId', 'x-audit' => ['level' => 2]]);

        self::assertTrue($extensions->has('x-audit'));
        self::assertFalse($extensions->has('x-missing'));
        self::assertSame('userId', $extensions->get('x-php-name'));
        self::assertSame(['level' => 2], $extensions->get('x-audit'));
        self::assertSame(['x-php-name', 'x-audit'], $extensions->keys());
        self::assertFalse($extensions->isEmpty());
    }

    public function testKeepsAnExplicitNull(): void
    {
        $extensions = new Extensions(['x-nothing' => null]);

        self::assertTrue($extensions->has('x-nothing'));
        self::assertNull($extensions->get('x-nothing'));
    }

    public function testIsEmptyByDefault(): void
    {
        self::assertTrue((new Extensions())->isEmpty());
        self::assertSame([], (new Extensions())->all());
    }

    /**
     * @dataProvider invalidKeys
     */
    public function testRejectsKeysThatAreNotExtensions(string $key): void
    {
        $this->expectException(InvalidModel::class);

        new Extensions([$key => true]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidKeys(): array
    {
        return [
            'plain keyword' => ['format'],
            'prefix only' => ['x-'],
            'x without dash' => ['xa'],
            'uppercase prefix' => ['X-foo'],
        ];
    }

    public function testRejectsANumericKeyAsAModelError(): void
    {
        $this->expectException(InvalidModel::class);

        new Extensions(['200' => true]);
    }

    public function testGetRejectsAMissingKey(): void
    {
        $this->expectException(InvalidModel::class);
        $this->expectExceptionMessage('Extension "x-missing" is not set.');

        (new Extensions())->get('x-missing');
    }
}
