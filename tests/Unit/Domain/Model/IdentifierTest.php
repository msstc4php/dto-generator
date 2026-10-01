<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Domain\Model;

use MSSTC4PHP\DtoGenerator\Domain\Model\Identifier;
use PHPUnit\Framework\TestCase;

final class IdentifierTest extends TestCase
{
    /**
     * @dataProvider validNames
     */
    public function testAcceptsPhpIdentifiers(string $name): void
    {
        self::assertTrue(Identifier::isValid($name));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function validNames(): array
    {
        return [
            'ascii' => ['userName'],
            'underscore first' => ['_id'],
            'utf-8' => ["Gr\xC3\xB6\xC3\x9Fe"],
        ];
    }

    /**
     * @dataProvider invalidNames
     */
    public function testRejectsNonIdentifiers(string $name): void
    {
        self::assertFalse(Identifier::isValid($name));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidNames(): array
    {
        return [
            'empty' => [''],
            'trailing newline' => ["user\n"],
            'leading space' => [' user'],
            'leading digit' => ['1user'],
            'dash' => ['user-name'],
        ];
    }

    public function testReservedWordsIgnoreAsciiCase(): void
    {
        self::assertTrue(Identifier::isReserved('LIST'));
        self::assertTrue(Identifier::isReserved('Resource'));
        self::assertTrue(Identifier::isReserved('numeric'));
        self::assertFalse(Identifier::isReserved('listing'));
    }

    public function testLowercasesAsciiOnlyRegardlessOfLocale(): void
    {
        self::assertSame("ab\xC4\xD6", Identifier::asciiLower("AB\xC4\xD6"));
    }
}
