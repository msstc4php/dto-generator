<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Domain\Model;

use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;
use PHPUnit\Framework\TestCase;

final class ClassNameTest extends TestCase
{
    public function testSplitsNamespaceAndShortName(): void
    {
        $name = ClassName::fromFqcn('App\Dto\Public\User');

        self::assertSame('App\Dto\Public\User', $name->fqcn());
        self::assertSame('App\Dto\Public', $name->namespace());
        self::assertSame('User', $name->shortName());
    }

    public function testAllowsReservedWordsInNamespaceSegments(): void
    {
        // PHP 8.0+ accepts them; rejecting them for 7.4 targets is the config's job, which knows the target.
        self::assertSame('App\Enum\List_\Status', ClassName::fromFqcn('App\Enum\List_\Status')->fqcn());
        self::assertSame('App\Dto\Public', ClassName::fromFqcn('App\Dto\Public\User')->namespace());
    }

    public function testDropsTheLeadingBackslash(): void
    {
        self::assertSame('App\User', ClassName::fromFqcn('\App\User')->fqcn());
    }

    public function testSupportsGlobalClasses(): void
    {
        $name = ClassName::fromFqcn('DateTimeImmutable');

        self::assertSame('', $name->namespace());
        self::assertSame('DateTimeImmutable', $name->fqcn());
    }

    public function testComparesByValue(): void
    {
        self::assertTrue(ClassName::fromFqcn('\App\User')->equals(ClassName::fromFqcn('App\User')));
        self::assertFalse(ClassName::fromFqcn('App\User')->equals(ClassName::fromFqcn('App\Admin')));
    }

    /**
     * @dataProvider invalidNames
     */
    public function testRejectsInvalidNames(string $fqcn, string $message): void
    {
        $this->expectException(InvalidModel::class);
        $this->expectExceptionMessage($message);

        ClassName::fromFqcn($fqcn);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function invalidNames(): array
    {
        return [
            'empty' => ['', 'must not be empty'],
            'only a backslash' => ['\\', 'must not be empty'],
            'starts with a digit' => ['App\1User', 'segment "1User" is not a PHP identifier'],
            'dash' => ['App\User-Profile', 'segment "User-Profile" is not a PHP identifier'],
            'empty segment' => ['App\\\\User', 'segment "" is not a PHP identifier'],
            'trailing backslash' => ['App\User\\', 'segment "" is not a PHP identifier'],
            'reserved short name' => ['App\Model\List', '"List" is a reserved word'],
            'reserved type name' => ['Object', '"Object" is a reserved word'],
        ];
    }
}
