<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Domain\Model;

use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassType;
use MSSTC4PHP\DtoGenerator\Domain\Model\ListType;
use MSSTC4PHP\DtoGenerator\Domain\Model\MapType;
use MSSTC4PHP\DtoGenerator\Domain\Model\MixedType;
use MSSTC4PHP\DtoGenerator\Domain\Model\NullableType;
use MSSTC4PHP\DtoGenerator\Domain\Model\ScalarType;
use MSSTC4PHP\DtoGenerator\Domain\Model\TypeModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\UnionType;
use PHPUnit\Framework\TestCase;

final class TypeModelTest extends TestCase
{
    /**
     * @dataProvider descriptions
     */
    public function testDescribesItself(TypeModel $type, string $expected): void
    {
        self::assertSame($expected, $type->describe());
    }

    /**
     * @return array<string, array{TypeModel, string}>
     */
    public static function descriptions(): array
    {
        $user = new ClassType(ClassName::fromFqcn('App\User'));

        return [
            'string' => [ScalarType::string(), 'string'],
            'refined string' => [ScalarType::string('non-empty-string'), 'non-empty-string'],
            'refined int' => [ScalarType::int('int<1, 10>'), 'int<1, 10>'],
            'float' => [ScalarType::float(), 'float'],
            'bool' => [ScalarType::bool(), 'bool'],
            'class' => [$user, 'App\User'],
            'list' => [new ListType($user), 'list<App\User>'],
            'map' => [new MapType(ScalarType::int()), 'array<string, int>'],
            'union' => [new UnionType($user, ScalarType::string()), 'App\User|string'],
            'nullable' => [new NullableType(new ListType(ScalarType::string())), 'list<string>|null'],
            'mixed' => [new MixedType(), 'mixed'],
        ];
    }

    public function testScalarExposesKindAndRefinement(): void
    {
        $type = ScalarType::int('positive-int');

        self::assertSame('int', $type->kind());
        self::assertSame('positive-int', $type->phpDoc());
        self::assertNull(ScalarType::bool()->phpDoc());
        self::assertSame('float', ScalarType::float('positive-float')->kind());
    }

    public function testScalarRejectsABlankRefinement(): void
    {
        $this->expectException(InvalidModel::class);

        ScalarType::string('  ');
    }

    public function testUnionFlattensNestedUnionsAndDropsDuplicates(): void
    {
        $union = new UnionType(
            new UnionType(ScalarType::string(), ScalarType::int()),
            ScalarType::string(),
            ScalarType::bool(),
        );

        self::assertSame('string|int|bool', $union->describe());
        self::assertCount(3, $union->members());
    }

    public function testUnionNeedsTwoDistinctMembers(): void
    {
        $this->expectException(InvalidModel::class);
        $this->expectExceptionMessage('at least two distinct members');

        new UnionType(ScalarType::string(), ScalarType::string());
    }

    public function testUnionRejectsNullableMembers(): void
    {
        $this->expectException(InvalidModel::class);
        $this->expectExceptionMessage('wrap the whole union in NullableType');

        new UnionType(ScalarType::string(), new NullableType(ScalarType::int()));
    }

    public function testUnionRejectsMixed(): void
    {
        $this->expectException(InvalidModel::class);

        new UnionType(ScalarType::string(), new MixedType());
    }

    public function testNullableRejectsNesting(): void
    {
        $this->expectException(InvalidModel::class);

        new NullableType(new NullableType(ScalarType::string()));
    }

    public function testNullableRejectsMixed(): void
    {
        $this->expectException(InvalidModel::class);

        new NullableType(new MixedType());
    }

    public function testWrappersExposeTheirParts(): void
    {
        $string = ScalarType::string();
        $user = ClassName::fromFqcn('App\User');

        self::assertSame($string, (new ListType($string))->item());
        self::assertSame($string, (new MapType($string))->value());
        self::assertSame($string, (new NullableType($string))->inner());
        self::assertSame($user, (new ClassType($user))->className());
    }
}
