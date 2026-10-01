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
        self::assertTrue(Identifier::isReserved('Enum'));
        self::assertTrue(Identifier::isReserved('match'));
        self::assertTrue(Identifier::isReserved('Readonly'));
        self::assertTrue(Identifier::isReserved('__PROPERTY__'));
        self::assertFalse(Identifier::isReserved('listing'));
        self::assertFalse(Identifier::isReserved('Resource'));
        self::assertFalse(Identifier::isReserved('numeric'));
    }

    public function testKnowsWhichWordsBreakAPhp74Namespace(): void
    {
        self::assertTrue(Identifier::isPhp74Keyword('List'));
        self::assertTrue(Identifier::isPhp74Keyword('fn'));
        self::assertFalse(Identifier::isPhp74Keyword('Enum'));
        self::assertFalse(Identifier::isPhp74Keyword('String'));
        self::assertFalse(Identifier::isPhp74Keyword('Match'));
        self::assertFalse(Identifier::isPhp74Keyword('listing'));
    }

    public function testLowercasesAsciiOnlyRegardlessOfLocale(): void
    {
        self::assertSame("ab\xC4\xD6", Identifier::asciiLower("AB\xC4\xD6"));
    }

    public function testChangesTheCaseOfTheFirstAsciiLetterOnly(): void
    {
        self::assertSame('User', Identifier::asciiUpperFirst('user'));
        self::assertSame('userName', Identifier::asciiLowerFirst('UserName'));
        self::assertSame("\xC3\xA4b", Identifier::asciiUpperFirst("\xC3\xA4b"));
        self::assertSame('', Identifier::asciiUpperFirst(''));
        self::assertSame('', Identifier::asciiLowerFirst(''));
    }

    public function testRecognisesSuperglobalsCaseSensitively(): void
    {
        self::assertTrue(Identifier::isSuperglobal('GLOBALS'));
        self::assertTrue(Identifier::isSuperglobal('_GET'));
        self::assertTrue(Identifier::isSuperglobal('_ENV'));
        self::assertFalse(Identifier::isSuperglobal('globals'));
        self::assertFalse(Identifier::isSuperglobal('_get'));
    }
}
