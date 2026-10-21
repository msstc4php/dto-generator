<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Domain\Builder;

use MSSTC4PHP\DtoGenerator\Domain\Builder\NameResolver;
use PHPUnit\Framework\TestCase;

final class NameResolverTest extends TestCase
{
    /**
     * @param array<array-key, string> $names
     * @param array<array-key, ?string> $expected
     *
     * @dataProvider namesApart
     */
    public function testSpellsTheSignsThatTellNamesApart(array $names, array $expected): void
    {
        $resolver = new NameResolver();

        self::assertSame($expected, $resolver->spelledApart($names, static fn (string $name): ?string => $resolver->enumCaseName($name)));
    }

    /**
     * @return iterable<string, array{array<array-key, string>, array<array-key, ?string>}>
     */
    public static function namesApart(): iterable
    {
        yield 'leading signs' => [['+1', '-1'], ['PLUS_1', 'MINUS_1']];
        yield 'signs within' => [['a' => 'Etc/GMT+0', 'b' => 'Etc/GMT-0', 'c' => 'Etc/GMT'], ['a' => 'ETC_GMT_PLUS_0', 'b' => 'ETC_GMT_MINUS_0', 'c' => 'ETC_GMT']];
        yield 'a sign some lack' => [['x+1', 'x_1'], ['X_PLUS_1', 'X_1']];
        yield 'a sign at either end' => [['x+', 'x', 'x-'], ['X_PLUS', 'X', 'X_MINUS']];
        yield 'a sign between letters joins words' => [['in-progress', 'in_progress'], ['IN_PROGRESS', 'IN_PROGRESS']];
        yield 'no sign to spell' => [['§', '§§'], [null, null]];
        yield 'a case apart' => [['a.B', 'a.b'], ['A_B', 'A_B']];
        yield 'leading signs told apart keep their spelling' => [['+a.b', 'a.b'], ['PLUS_A_DOT_B', 'A_B']];
        yield 'a shared sign stays' => [['v1.0+1', 'v1.0-1'], ['V1_0_PLUS_1', 'V1_0_MINUS_1']];
        yield 'leading signs alike' => [['+a+b', '+a+B'], ['PLUS_A_PLUS_B', 'PLUS_A_PLUS_B']];
        yield 'a name without characters' => [['§+', '§-'], ['PLUS', 'MINUS']];
        yield 'a sign after a digit' => [['a1-b', 'a1_b'], ['A1_MINUS_B', 'A1_B']];
        yield 'a sign before a digit' => [['a-1b', 'a_1b'], ['A_MINUS_1B', 'A_1B']];
    }

    public function testComparesSpelledNamesWithoutLetterCase(): void
    {
        $resolver = new NameResolver();

        self::assertSame(['a plus 1', 'A plus 1', 'a minus 1'], $resolver->spelledApart(['a+1', 'A+1', 'a-1'], static fn (string $name): string => trim(preg_replace('/ +/', ' ', $name) ?? '')));
    }

    /**
     * @dataProvider classNames
     */
    public function testDerivesPascalCaseClassNames(string $schemaName, ?string $expected): void
    {
        self::assertSame($expected, (new NameResolver())->className($schemaName));
    }

    /**
     * @return array<string, array{string, string|null}>
     */
    public static function classNames(): array
    {
        return [
            'plain' => ['user', 'User'],
            'snake case' => ['user_profile', 'UserProfile'],
            'kebab case' => ['user-profile', 'UserProfile'],
            'spaces' => ['My Type', 'MyType'],
            'slash' => ['a/b', 'AB'],
            'acronym kept' => ['HTTPResponse', 'HTTPResponse'],
            'leading digit' => ['200', '_200'],
            'leading digit word' => ['2fa_settings', '_2faSettings'],
            'digit inside' => ['version 2', 'Version2'],
            'reserved word' => ['list', 'List_'],
            'reserved type name' => ['Object', 'Object_'],
            'utf-8 letters' => ["Gr\xC3\xB6\xC3\x9Fe", "Gr\xC3\xB6\xC3\x9Fe"],
            'nothing usable' => ['***', null],
            'no-break space' => ["x\u{00A0}y", 'XY'],
            'currency sign' => ['€', null],
            'non-ascii letters' => ["\u{00FC}ber_ma\u{00DF}", "\u{00FC}berMa\u{00DF}"],
            'combining mark' => ["cafe\u{0301}_x", "Cafe\u{0301}X"],
            'invalid utf-8' => ["ab\xFF", 'Ab'],
        ];
    }

    /**
     * @dataProvider pascalCaseNames
     */
    public function testJoinsWordsInPascalCaseWithoutTheGuardsOfAWholeName(string $name, ?string $expected): void
    {
        self::assertSame($expected, (new NameResolver())->pascalCase($name));
    }

    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function pascalCaseNames(): iterable
    {
        yield 'a reserved word' => ['list', 'List'];
        yield 'a leading digit' => ['2fa', '2fa'];
        yield 'separated words' => ['foo-bar', 'FooBar'];
        yield 'no letters' => ['+', null];
        yield 'nothing' => ['', null];
    }

    /**
     * @dataProvider propertyNames
     */
    public function testDerivesCamelCasePropertyNames(string $wireName, ?string $expected): void
    {
        self::assertSame($expected, (new NameResolver())->propertyName($wireName));
    }

    /**
     * @return array<string, array{string, string|null}>
     */
    public static function propertyNames(): array
    {
        return [
            'snake case' => ['user_name', 'userName'],
            'pascal case' => ['UserName', 'userName'],
            'kebab case' => ['e-mail', 'eMail'],
            'all caps' => ['URL', 'url'],
            'all caps words' => ['URL_PATH', 'urlPATH'],
            'mixed caps' => ['userID', 'userID'],
            'acronym inside a word' => ['xmlHTTPRequest', 'xmlHTTPRequest'],
            'decomposed accent' => ["na\u{0308}ive", "na\u{0308}ive"],
            'leading acronym with a digit' => ['HTTP2Status', 'http2Status'],
            'short acronym with a digit' => ['S3Bucket', 's3Bucket'],
            'plural acronym' => ['IDs', 'ids'],
            'plural acronym with a separator' => ['URLs_list', 'urlsList'],
            'leading acronym' => ['HTTPStatus', 'httpStatus'],
            'leading acronym before a word' => ['URLPath', 'urlPath'],
            'no-break space in a property' => ["x\u{00A0}y", 'xY'],
            'non-ascii first letter' => ["\u{00FC}ber", "\u{00FC}ber"],
            'leading digit' => ['200', '_200'],
            'this' => ['this', 'this_'],
            'reserved words are fine for properties' => ['class', 'class'],
            'json-ld marker' => ['@type', 'type'],
            'nothing usable' => ['---', null],
        ];
    }

    /**
     * @dataProvider enumCases
     *
     * @param int|string $value
     */
    public function testDerivesUpperSnakeEnumCaseNames($value, ?string $expected): void
    {
        self::assertSame($expected, (new NameResolver())->enumCaseName($value));
    }

    /**
     * @return array<string, array{int|string, ?string}>
     */
    public static function enumCases(): array
    {
        return [
            'upper' => ['EUR', 'EUR'],
            'lower' => ['eur', 'EUR'],
            'dashes' => ['in-progress', 'IN_PROGRESS'],
            'camel' => ['inProgress', 'IN_PROGRESS'],
            'spaces' => ['on hold', 'ON_HOLD'],
            'leading digit' => ['1st', '_1ST'],
            'class' => ['class', 'CLASS_'],
            'Class' => ['Class', 'CLASS_'],
            'other keyword' => ['new', 'NEW'],
            'non-ascii' => ["\u{00FC}ber", "\u{00FC}BER"],
            'nothing usable' => ['***', null],
            'zero' => [0, 'VALUE_0'],
            'positive' => [42, 'VALUE_42'],
            'negative' => [-1, 'VALUE_MINUS_1'],
            'lowest integer' => [PHP_INT_MIN, 'VALUE_MINUS_9223372036854775808'],
            'empty string' => ['', 'EMPTY'],
        ];
    }
}
