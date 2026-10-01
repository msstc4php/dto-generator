<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Support;

use MSSTC4PHP\DtoGenerator\Domain\Model\ClassKind;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassType;
use MSSTC4PHP\DtoGenerator\Domain\Model\DocModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\ListType;
use MSSTC4PHP\DtoGenerator\Domain\Model\MapType;
use MSSTC4PHP\DtoGenerator\Domain\Model\MixedType;
use MSSTC4PHP\DtoGenerator\Domain\Model\NullableType;
use MSSTC4PHP\DtoGenerator\Domain\Model\PropertyModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\ScalarType;
use MSSTC4PHP\DtoGenerator\Domain\Model\TypeModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\UnionType;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;
use MSSTC4PHP\DtoGenerator\Domain\Shared\DefaultValue;
use MSSTC4PHP\DtoGenerator\Domain\Target\AccessorStyle;
use MSSTC4PHP\DtoGenerator\Domain\Target\DateTimeClass;
use MSSTC4PHP\DtoGenerator\Domain\Target\MetadataMode;
use MSSTC4PHP\DtoGenerator\Domain\Target\Mutability;
use MSSTC4PHP\DtoGenerator\Domain\Target\PhpVersion;
use MSSTC4PHP\DtoGenerator\Domain\Target\TargetProfile;

/**
 * IR classes and target profiles for emitter tests; Sample exercises every type, default and doc rule.
 */
final class EmitterFixture
{
    private const SPEC = '/project/api/openapi.yaml';

    public static function target(string $php, string $mutability, string $accessors = AccessorStyle::AUTO): TargetProfile
    {
        return new TargetProfile(
            PhpVersion::fromString($php),
            MetadataMode::from(MetadataMode::NONE),
            Mutability::from($mutability),
            AccessorStyle::from($accessors),
            DateTimeClass::from(DateTimeClass::IMMUTABLE),
            true,
        );
    }

    /**
     * @return list<ClassModel>
     */
    public static function classes(): array
    {
        return [self::sample(), self::tag()];
    }

    public static function sample(): ClassModel
    {
        $tag = new ClassType(ClassName::fromFqcn('App\Dto\Tag'));

        return self::model('App\Dto\Sample', 'A sample DTO.', [
            self::property('id', ScalarType::int('positive-int'), true, null, 'Identifier.'),
            self::property('name', new NullableType(ScalarType::string('non-empty-string')), false, new DefaultValue('anonymous')),
            self::property('tags', new ListType($tag), true, null, "Labels.\nAt most */ ten."),
            self::property('code', new UnionType(ScalarType::int(), ScalarType::string()), true),
            self::property('createdAt', new NullableType(new ClassType(ClassName::fromFqcn('DateTimeImmutable'))), false, new DefaultValue(null), 'When it was created.'),
            self::property('score', new NullableType(ScalarType::float()), false, new DefaultValue(1.5)),
            self::property('meta', new NullableType(new MapType(ScalarType::int())), false, new DefaultValue(null)),
            self::property('flags', new NullableType(new ListType(ScalarType::bool())), false, new DefaultValue([true, false])),
            self::property('extra', new MixedType(), false, new DefaultValue(null), null, true),
        ]);
    }

    public static function tag(): ClassModel
    {
        return self::model('App\Dto\Tag', null, [self::property('label', ScalarType::string(), true)]);
    }

    /**
     * @param list<PropertyModel> $properties
     */
    public static function model(string $fqcn, ?string $description, array $properties, string $mutability = Mutability::IMMUTABLE): ClassModel
    {
        return new ClassModel(
            ClassName::fromFqcn($fqcn),
            ClassKind::from(ClassKind::FINAL),
            null,
            $properties,
            Mutability::from($mutability),
            new DocModel($description),
            new SchemaLocation(self::SPEC, '/components/schemas/' . ClassName::fromFqcn($fqcn)->shortName()),
        );
    }

    public static function property(
        string $name,
        TypeModel $type,
        bool $required,
        ?DefaultValue $default = null,
        ?string $description = null,
        bool $deprecated = false
    ): PropertyModel {
        return new PropertyModel(
            $name,
            $name,
            $type,
            $required,
            $default,
            new DocModel($description, $deprecated),
            new SchemaLocation(self::SPEC, '/components/schemas/X/properties/' . $name),
        );
    }
}
