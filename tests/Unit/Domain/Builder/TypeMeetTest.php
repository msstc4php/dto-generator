<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Domain\Builder;

use MSSTC4PHP\DtoGenerator\Domain\Builder\Declarations;
use MSSTC4PHP\DtoGenerator\Domain\Builder\TypeMeet;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassType;
use MSSTC4PHP\DtoGenerator\Domain\Model\EnumBacking;
use MSSTC4PHP\DtoGenerator\Domain\Model\EnumType;
use MSSTC4PHP\DtoGenerator\Domain\Model\ListType;
use MSSTC4PHP\DtoGenerator\Domain\Model\MapType;
use MSSTC4PHP\DtoGenerator\Domain\Model\MixedType;
use MSSTC4PHP\DtoGenerator\Domain\Model\NullableType;
use MSSTC4PHP\DtoGenerator\Domain\Model\ScalarType;
use MSSTC4PHP\DtoGenerator\Domain\Model\TypeModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\UnionType;
use PHPUnit\Framework\TestCase;

final class TypeMeetTest extends TestCase
{
    /**
     * @dataProvider meets
     */
    public function testTakesTheTypeThatAdmitsOnlyValuesOfBoth(TypeModel $a, TypeModel $b, ?string $expected): void
    {
        $meet = $this->meet();

        self::assertSame($expected, $this->members($meet->of($a, $b)));
        self::assertSame($expected, $this->members($meet->of($b, $a)), 'either way round');
    }

    /**
     * @dataProvider admissions
     */
    public function testAdmitsTheValuesOfANarrowerType(TypeModel $wide, TypeModel $narrow, bool $expected): void
    {
        self::assertSame($expected, $this->meet()->admits($wide, $narrow));
    }

    /**
     * @return iterable<string, array{TypeModel, TypeModel, bool}>
     */
    public static function admissions(): iterable
    {
        $int = ScalarType::int();
        $string = ScalarType::string();

        yield 'every member of a union' => [ScalarType::float(), UnionType::of($int, ScalarType::float()), true];
        yield 'not every member' => [$string, UnionType::of($int, $string), false];
        yield 'by one member' => [UnionType::of($int, $string), $string, true];
        yield 'items' => [new ListType(new MixedType()), new ListType($string), true];
        yield 'items of another type' => [new ListType($int), new ListType($string), false];
        yield 'a list for a map' => [new ListType(new MixedType()), new MapType(new MixedType()), false];
        yield 'a date for an int' => [$int, self::class('DateTimeImmutable'), false];
        yield 'a date for a refined string' => [ScalarType::string('non-empty-string'), self::class('DateTimeImmutable'), false];
    }

    /**
     * @return iterable<string, array{TypeModel, TypeModel, ?string}>
     */
    public static function meets(): iterable
    {
        $string = ScalarType::string();
        $int = ScalarType::int();
        $date = self::class('DateTimeImmutable');
        $pet = self::class('App\\Pet');
        $owner = self::class('App\\Owner');
        $status = self::enum('App\\Status', EnumBacking::STRING, ['a', 'b']);
        $narrow = self::enum('App\\Narrow', EnumBacking::STRING, ['a']);
        $level = self::enum('App\\Level', EnumBacking::INT, [1, 2]);

        yield 'the same' => [$string, ScalarType::string(), 'string'];
        yield 'mixed' => [new MixedType(), $pet, 'App\\Pet'];
        yield 'a refined string' => [$string, ScalarType::string('non-empty-string'), 'non-empty-string'];
        yield 'a refined int' => [ScalarType::int('positive-int'), $int, 'positive-int'];
        yield 'two refinements' => [ScalarType::int('positive-int'), ScalarType::int('non-negative-int'), null];
        yield 'an int among numbers' => [ScalarType::float(), $int, 'int'];
        yield 'a date' => [$string, $date, 'DateTimeImmutable'];
        yield 'a class a format or x-php-type names' => [$string, self::class('App\\Money'), null];
        yield 'such a class for an object' => [new MapType(new MixedType()), self::class('App\\Money'), null];
        yield 'members that share values PHP cannot type' => [UnionType::of($pet, $int), UnionType::of($owner, $int), null];
        yield 'a class of unknown values' => [UnionType::of(self::class('App\\Money'), $int), $string, null];
        yield 'members of other kinds' => [UnionType::of(new ListType($int), $string, ScalarType::bool()), new MapType(new MixedType()), null];
        yield 'an integer among numbers of a union' => [UnionType::of($string, $int), ScalarType::float(), 'int'];
        yield 'a date of a union' => [UnionType::of($date, new ListType($int)), $string, 'DateTimeImmutable'];
        yield 'an enum of a union' => [UnionType::of($level, ScalarType::bool()), $int, 'App\\Level'];
        $bool = ScalarType::bool();
        $money = self::class('App\\Money');
        yield 'a class of a union and a string' => [UnionType::of($pet, $string), UnionType::of($string, $int), 'string'];
        yield 'a class of unknown values and a string' => [UnionType::of($money, $string), UnionType::of($string, $int), null];
        yield 'a class of unknown values and a boolean' => [UnionType::of($money, $bool), UnionType::of($bool, $int), null];
        yield 'a date and a boolean' => [UnionType::of($date, $bool), UnionType::of($bool, $int), 'bool'];
        yield 'an object of a union' => [UnionType::of($pet, $string), new MapType(new MixedType()), 'App\\Pet'];
        yield 'unions of the same members' => [UnionType::of($int, ScalarType::float()), UnionType::of(ScalarType::float(), $int), 'float|int'];
        yield 'two types of the same values' => [ScalarType::float(), UnionType::of(ScalarType::float(), $int), 'float'];
        yield 'maps that meet by their values' => [new MapType(UnionType::of($int, $string)), new MapType(UnionType::of($string, ScalarType::bool())), 'array<array-key, string>'];
        yield 'maps of others' => [new MapType($int), new MapType($string), null];
        yield 'a class is no string' => [$string, $pet, null];
        yield 'a string enum' => [$string, $status, 'App\\Status'];
        yield 'an int enum' => [$int, $level, 'App\\Level'];
        yield 'an enum of other values' => [$int, $status, null];
        yield 'fewer cases' => [$status, $narrow, 'App\\Narrow'];
        yield 'other cases' => [$narrow, self::enum('App\\Other', EnumBacking::STRING, ['b']), null];
        yield 'another backing' => [$level, self::enum('App\\Text', EnumBacking::STRING, ['1']), null];
        yield 'two classes' => [$pet, $owner, null];
        yield 'null left out' => [NullableType::of($string), $string, 'string'];
        yield 'both nullable' => [NullableType::of($string), NullableType::of($date), 'DateTimeImmutable|null'];
        yield 'a union member' => [UnionType::of($int, $date), $string, 'DateTimeImmutable'];
        yield 'a nullable union' => [NullableType::of(UnionType::of($int, $date)), $string, 'DateTimeImmutable'];
        yield 'unions' => [UnionType::of($int, $string, ScalarType::bool()), UnionType::of($string, $int), 'int|string'];
        yield 'nothing in common' => [UnionType::of($int, ScalarType::bool()), $string, null];
        yield 'lists' => [new ListType(new MixedType()), new ListType($string), 'list<string>'];
        yield 'lists of nullables' => [new ListType(NullableType::of($string)), new ListType($string), 'list<string>'];
        yield 'lists of others' => [new ListType($int), new ListType($string), null];
        yield 'lists that meet by their items' => [new ListType(UnionType::of($int, $date)), new ListType($string), 'list<DateTimeImmutable>'];
        yield 'maps' => [new MapType(new MixedType()), new MapType($int), 'array<array-key, int>'];
        yield 'an object of any properties' => [NullableType::of(new MapType(new MixedType())), NullableType::of($pet), 'App\\Pet|null'];
        yield 'an object of typed properties' => [new MapType($int), $pet, null];
        yield 'a list is no map' => [new MapType(new MixedType()), new ListType($int), null];
    }

    /**
     * The members of a union in order, as either argument may come first.
     */
    private function members(?TypeModel $type): ?string
    {
        if (!$type instanceof TypeModel) {
            return null;
        }

        $members = explode('|', $type->describe());
        sort($members);

        return implode('|', $members);
    }

    private function meet(): TypeMeet
    {
        return new TypeMeet(ClassName::fromFqcn('DateTimeImmutable'), new Declarations(['/a#/Pet' => ClassName::fromFqcn('App\\Pet'), '/a#/Owner' => ClassName::fromFqcn('App\\Owner')]));
    }

    private static function class(string $fqcn): ClassType
    {
        return new ClassType(ClassName::fromFqcn($fqcn));
    }

    /**
     * @param non-empty-list<int|string> $values
     */
    private static function enum(string $fqcn, string $backing, array $values): EnumType
    {
        $cases = [];
        foreach ($values as $value) {
            $cases[$value] = 'C' . $value;
        }

        return new EnumType(ClassName::fromFqcn($fqcn), EnumBacking::from($backing), $cases);
    }
}
