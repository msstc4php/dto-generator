<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Domain\Builder;

use MSSTC4PHP\DtoGenerator\Domain\Builder\NameResolver;
use PHPUnit\Framework\TestCase;

final class NameResolverTest extends TestCase
{
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
        ];
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
            'leading digit' => ['200', '_200'],
            'this' => ['this', 'this_'],
            'reserved words are fine for properties' => ['class', 'class'],
            'json-ld marker' => ['@type', 'type'],
            'nothing usable' => ['---', null],
        ];
    }
}
