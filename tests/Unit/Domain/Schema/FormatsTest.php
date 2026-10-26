<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Domain\Schema;

use MSSTC4PHP\DtoGenerator\Domain\Schema\Formats;
use PHPUnit\Framework\TestCase;

final class FormatsTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function names(): iterable
    {
        yield 'known' => ['date-time', 'date-time'];
        yield 'camel case' => ['dateTime', 'date-time'];
        yield 'spaces' => ['ISO 8601 date-time', 'date-time'];
        yield 'underscores' => ['phone_number', 'phone'];
        yield 'surrounding space' => [' uuid ', 'uuid'];
        yield 'capitals of a known name' => ['UUID', 'uuid'];
        yield 'capitals of an integer format' => ['UnixTime', 'unix-time'];
        yield 'capitals of a number format' => ['Double', 'double'];
        yield 'url' => ['url', 'uri-reference'];
        yield 'netmask' => ['ip/netmask', 'cidr'];
        yield 'prefix length' => ['ipv4/prefix_length', 'ipv4-cidr'];
        yield 'month and year' => ['MM/YYYY', 'mm/yyyy'];
        yield 'hex colour' => ['hex-color', 'hex-color'];
        yield 'hex colour in camel case' => ['hexColor', 'hex-color'];
        yield 'css colour' => ['css-color', 'color'];
        yield 'unknown in lower case and hyphens' => ['Ssh_Key', 'ssh-key'];
    }

    /**
     * @dataProvider names
     */
    public function testNormalizesTheSpellingsOfAFormat(string $format, string $expected): void
    {
        self::assertSame($expected, Formats::normalize($format));
    }

    /**
     * @return iterable<string, array{string, 'string'|'integer'|'number', bool}>
     */
    public static function known(): iterable
    {
        yield 'string format' => ['phone', 'string', true];
        yield 'string format of an integer' => ['phone', 'integer', false];
        yield 'integer format' => ['uint32', 'integer', true];
        yield 'integer in a string' => ['uint32', 'string', true];
        yield 'unix time' => ['unix-time', 'integer', true];
        yield 'unix time of a number' => ['unix-time', 'number', true];
        yield 'unix time of a string' => ['unix-time', 'string', false];
        yield 'integer format of a number' => ['int64', 'number', true];
        yield 'number format' => ['decimal', 'number', true];
        yield 'number format of an integer' => ['double', 'integer', false];
        yield 'unknown' => ['ssh-key', 'string', false];
        yield 'hex colour' => ['hex-color', 'string', true];
    }

    /**
     * @dataProvider known
     *
     * @param 'string'|'integer'|'number' $type
     */
    public function testKnowsTheFormatsOfEachType(string $format, string $type, bool $expected): void
    {
        self::assertSame($expected, Formats::isKnown($format, $type));
    }
}
