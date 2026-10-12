<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Application\Port;

use MSSTC4PHP\DtoGenerator\Application\Port\HttpField;
use PHPUnit\Framework\TestCase;

final class HttpFieldTest extends TestCase
{
    public function testKnowsAFieldName(): void
    {
        self::assertTrue(HttpField::isName("PRIVATE-TOKEN!#$%&'*+.^_`|~9"));
        self::assertFalse(HttpField::isName(''));
        self::assertFalse(HttpField::isName('X Y'));
        self::assertFalse(HttpField::isName("X\n"));
        self::assertFalse(HttpField::isName('X:'));
    }

    public function testKnowsControlCharactersButATab(): void
    {
        self::assertFalse(HttpField::hasControlCharacters("a\tb ~"));
        self::assertTrue(HttpField::hasControlCharacters("a\rb"));
        self::assertTrue(HttpField::hasControlCharacters("\x00"));
        self::assertTrue(HttpField::hasControlCharacters("\x1F"));
        self::assertTrue(HttpField::hasControlCharacters("\x7F"));
    }

    public function testKnowsTheFieldsTheClientWrites(): void
    {
        foreach (['Host', 'CONNECTION', 'content-length', 'Transfer-Encoding', 'Proxy-Authorization', 'proxy-anything'] as $name) {
            self::assertTrue(HttpField::isReserved($name), $name);
        }

        foreach (['Authorization', 'Accept', 'X-Proxy', 'Hosts'] as $name) {
            self::assertFalse(HttpField::isReserved($name), $name);
        }
    }
}
