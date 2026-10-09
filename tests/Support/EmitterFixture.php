<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Support;

use MSSTC4PHP\DtoGenerator\Domain\Model\ArgumentValue;
use MSSTC4PHP\DtoGenerator\Domain\Model\AttributeArgument;
use MSSTC4PHP\DtoGenerator\Domain\Model\AttributeModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassKind;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassType;
use MSSTC4PHP\DtoGenerator\Domain\Model\DiscriminatorModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\DiscriminatorValues;
use MSSTC4PHP\DtoGenerator\Domain\Model\DocModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\EnumBacking;
use MSSTC4PHP\DtoGenerator\Domain\Model\EnumCase;
use MSSTC4PHP\DtoGenerator\Domain\Model\EnumModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\EnumType;
use MSSTC4PHP\DtoGenerator\Domain\Model\ImportAlias;
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

    /**
     * The metadata mode a config with "auto" gets: attributes from PHP 8.0, annotations below.
     */
    public static function target(string $php, string $mutability, string $accessors = AccessorStyle::AUTO, ?string $metadata = null, bool $withers = true): TargetProfile
    {
        $version = PhpVersion::fromString($php);

        return new TargetProfile(
            $version,
            MetadataMode::from($metadata ?? MetadataMode::defaultFor($version)->value()),
            Mutability::from($mutability),
            AccessorStyle::from($accessors),
            DateTimeClass::from(DateTimeClass::IMMUTABLE),
            true,
            $withers,
        );
    }

    /**
     * @return list<ClassModel>
     */
    public static function classes(string $mutability = Mutability::IMMUTABLE): array
    {
        return [
            self::sample($mutability),
            self::tag($mutability),
            self::copy($mutability),
            self::animal($mutability),
            self::dog($mutability),
            self::shape($mutability),
            self::circle($mutability),
            self::square($mutability),
            self::wallet($mutability),
            self::euroWallet($mutability),
        ];
    }

    /**
     * `new` in attribute arguments, which only PHP 8.1 and later allow.
     */
    public static function rules(string $mutability = Mutability::IMMUTABLE): ClassModel
    {
        $limit = ArgumentValue::newInstance(ClassName::fromFqcn('App\\Attr\\Limit'), AttributeArgument::named('max', ArgumentValue::literal(3)));

        return self::model('App\\Dto\\Rules', null, [
            self::property('count', ScalarType::int(), true)->withAddedAttributes(new AttributeModel(ClassName::fromFqcn('App\\Attr\\Guard'), [AttributeArgument::positional($limit)])),
        ], $mutability);
    }

    /**
     * The properties a fixture class inherits, root first.
     *
     * @return list<PropertyModel>
     */
    public static function inherited(ClassModel $class): array
    {
        $byName = [];
        foreach (self::classes($class->mutability()->value()) as $candidate) {
            $byName[$candidate->name()->fqcn()] = $candidate;
        }

        $parent = $class->parent();

        return $parent instanceof ClassName ? array_merge(self::inherited($byName[$parent->fqcn()]), $byName[$parent->fqcn()]->properties()) : [];
    }

    /**
     * An `allOf` base: open, with one required and one optional property.
     */
    public static function animal(string $mutability = Mutability::IMMUTABLE): ClassModel
    {
        return self::model('App\Dto\Animal', 'An animal.', [
            self::property('id', ScalarType::string(), true),
            self::property('nickname', new NullableType(ScalarType::string()), false, new DefaultValue(null)),
        ], $mutability)->withHierarchy(ClassKind::from(ClassKind::OPEN), null, null);
    }

    /**
     * Interleaves its own required and optional parameters with the inherited ones.
     */
    public static function dog(string $mutability = Mutability::IMMUTABLE): ClassModel
    {
        return self::model('App\Dto\Dog', null, [
            self::property('breed', ScalarType::string(), true),
            self::property('goodBoy', new NullableType(ScalarType::bool()), false, new DefaultValue(true)),
        ], $mutability)->withHierarchy(ClassKind::from(ClassKind::FINAL), ClassName::fromFqcn('App\Dto\Animal'), null);
    }

    /**
     * A discriminated base.
     */
    public static function shape(string $mutability = Mutability::IMMUTABLE): ClassModel
    {
        return self::model('App\Dto\Shape', null, [self::property('kind', ScalarType::string(), true)], $mutability)->withHierarchy(
            ClassKind::from(ClassKind::ABSTRACT),
            null,
            new DiscriminatorModel('kind', [
                'circle' => ClassName::fromFqcn('App\Dto\Circle'),
                'round' => ClassName::fromFqcn('App\Dto\Circle'),
                'square' => ClassName::fromFqcn('App\Dto\Square'),
            ]),
        );
    }

    /**
     * Selected by two values, so the discriminator stays a required parameter.
     */
    public static function circle(string $mutability = Mutability::IMMUTABLE): ClassModel
    {
        return self::model('App\Dto\Circle', null, [self::property('radius', ScalarType::float(), true)], $mutability)
            ->withHierarchy(ClassKind::from(ClassKind::FINAL), ClassName::fromFqcn('App\Dto\Shape'), null)
            ->withDiscriminatorValues(new DiscriminatorValues('kind', ['circle', 'round']))
        ;
    }

    /**
     * Selected by one value, its default, so the discriminator moves behind the required parameters.
     */
    public static function square(string $mutability = Mutability::IMMUTABLE): ClassModel
    {
        return self::model('App\Dto\Square', null, [self::property('side', ScalarType::float(), true)], $mutability)
            ->withHierarchy(ClassKind::from(ClassKind::FINAL), ClassName::fromFqcn('App\Dto\Shape'), null)
            ->withDiscriminatorValues(new DiscriminatorValues('kind', ['square']))
        ;
    }

    /**
     * A base discriminated by an enum.
     */
    public static function wallet(string $mutability = Mutability::IMMUTABLE): ClassModel
    {
        return self::model('App\Dto\Wallet', null, [self::property('currency', EnumType::of(self::currency()), true)], $mutability)->withHierarchy(
            ClassKind::from(ClassKind::ABSTRACT),
            null,
            new DiscriminatorModel('currency', ['EUR' => ClassName::fromFqcn('App\Dto\EuroWallet')]),
        );
    }

    public static function euroWallet(string $mutability = Mutability::IMMUTABLE): ClassModel
    {
        return self::model('App\Dto\EuroWallet', null, [self::property('balance', ScalarType::int(), true)], $mutability)
            ->withHierarchy(ClassKind::from(ClassKind::FINAL), ClassName::fromFqcn('App\Dto\Wallet'), null)
            ->withDiscriminatorValues(new DiscriminatorValues('currency', ['EUR']))
        ;
    }

    public static function sample(string $mutability = Mutability::IMMUTABLE): ClassModel
    {
        $tag = new ClassType(ClassName::fromFqcn('App\Dto\Tag'));

        $assert = new ImportAlias('App\Attr\Constraints', 'Assert');

        return self::model('App\Dto\Sample', 'A sample DTO.', [
            self::property('id', ScalarType::int('positive-int'), true, null, 'Identifier.')
                ->withAddedAttributes(new AttributeModel(ClassName::fromFqcn('App\Attr\Constraints\Positive'), [], $assert)),
            self::property('name', new NullableType(ScalarType::string('non-empty-string')), false, new DefaultValue('anonymous')),
            self::property('tags', new ListType($tag), true, null, "Labels.\nAt most */ ten.\n@var string is text, not a tag."),
            self::property('code', new UnionType(ScalarType::int(), ScalarType::string()), true)->withAddedAttributes(new AttributeModel(
                ClassName::fromFqcn('App\Attr\Choice'),
                [
                    AttributeArgument::named('choices', ArgumentValue::listOf(ArgumentValue::literal(1), ArgumentValue::literal('A'))),
                    AttributeArgument::named('mode', ArgumentValue::constant('STRICT', ClassName::fromFqcn('App\Attr\Mode'))),
                ],
            )),
            self::property('createdAt', new NullableType(new ClassType(ClassName::fromFqcn('DateTimeImmutable'))), false, new DefaultValue(null), 'When it was created.'),
            self::property('score', new NullableType(ScalarType::float()), false, new DefaultValue(1.5)),
            self::property('meta', new NullableType(new MapType(ScalarType::int())), false, new DefaultValue(null)),
            self::property('flags', new NullableType(new ListType(ScalarType::bool())), false, new DefaultValue([true, false])),
            self::property('extra', new MixedType(), false, new DefaultValue(null), null, true),
            self::property('currency', new NullableType(EnumType::of(self::currency())), false, new DefaultValue('EUR'), 'Settlement currency.')
                ->withAddedAttributes(new AttributeModel(ClassName::fromFqcn('App\Attr\Meta'), [AttributeArgument::positional(ArgumentValue::classReference(ClassName::fromFqcn('App\Dto\Currency')))])),
        ], $mutability)->withAddedAttributes(
            new AttributeModel(ClassName::fromFqcn('App\Attr\Table'), [AttributeArgument::named('name', ArgumentValue::literal("sample\tdto"))]),
            new AttributeModel(ClassName::fromFqcn('App\Attr\Constraints\Valid'), [], $assert),
        );
    }

    /**
     * A class without a namespace whose property name collides with the clone-assign temporary.
     */
    public static function copy(string $mutability = Mutability::IMMUTABLE): ClassModel
    {
        return self::model('Copy', null, [
            self::property('clone', ScalarType::int(), true),
            // Long enough that `clone($this, [...])` and `new self(...)` break across lines on 8.1+.
            self::property('deliberatelyLongPropertyNameThatBreaksTheWitherCall', ScalarType::int(), false, new DefaultValue(0)),
        ], $mutability);
    }

    /**
     * @return list<EnumModel>
     */
    public static function enums(): array
    {
        return [self::currency()];
    }

    public static function currency(): EnumModel
    {
        return new EnumModel(
            ClassName::fromFqcn('App\Dto\Currency'),
            EnumBacking::from(EnumBacking::STRING),
            [new EnumCase('EUR', 'EUR', new DocModel('Euro.')), new EnumCase('IN_PROGRESS', 'in-progress')],
            new DocModel('A currency.'),
            new SchemaLocation(self::SPEC, '/components/schemas/Currency'),
        );
    }

    public static function tag(string $mutability = Mutability::IMMUTABLE): ClassModel
    {
        return self::model('App\Dto\Tag', null, [self::property('label', ScalarType::string(), true)], $mutability);
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
