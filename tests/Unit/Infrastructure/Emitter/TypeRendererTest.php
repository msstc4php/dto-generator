<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Infrastructure\Emitter;

use LogicException;
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
use MSSTC4PHP\DtoGenerator\Infrastructure\Emitter\TypeRenderer;
use MSSTC4PHP\DtoGenerator\Tests\Support\EmitterFixture;
use PhpParser\Node;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\Function_;
use PhpParser\PrettyPrinter\Standard;
use PHPUnit\Framework\TestCase;

final class TypeRendererTest extends TestCase
{
    /**
     * @dataProvider types
     */
    public function testRendersNativeAndDocTypes(TypeModel $type, string $php, ?string $native, string $doc, bool $needsDoc): void
    {
        $renderer = new TypeRenderer('App\Dto', EmitterFixture::target($php, 'mutable', 'getters'));

        self::assertSame($native, $renderer->native($type));
        self::assertSame($doc, $renderer->doc($type));
        self::assertSame($needsDoc, $renderer->tags($type, 'var') !== []);
        $node = $renderer->nativeNode($type);
        self::assertSame($native, $node instanceof Node ? $this->print($node) : null);
    }

    /**
     * @dataProvider tags
     *
     * @param list<string> $expected
     */
    public function testWritesPortableTagsAndRefinesThemForPhpstan(TypeModel $type, string $php, array $expected): void
    {
        $renderer = new TypeRenderer('App\Dto', EmitterFixture::target($php, 'mutable', 'getters'));

        self::assertSame($expected, $renderer->tags($type, 'param', ' $value'));
    }

    /**
     * @return array<string, array{TypeModel, string, list<string>}>
     */
    public static function tags(): array
    {
        $tag = new ClassType(ClassName::fromFqcn('App\Dto\Tag'));
        $currency = new EnumType(ClassName::fromFqcn('App\Dto\Currency'), EnumBacking::from(EnumBacking::STRING), ['EUR' => 'EUR']);
        $level = new EnumType(ClassName::fromFqcn('App\Dto\Level'), EnumBacking::from(EnumBacking::INT), [1 => 'VALUE_1']);
        $name = ScalarType::string('non-empty-string');

        return [
            'plain' => [ScalarType::string(), '8.2', []],
            'refined scalar' => [new NullableType($name), '8.2', ['@phpstan-param ?non-empty-string $value']],
            'refined int' => [ScalarType::int('int<0, 30>'), '8.2', ['@phpstan-param int<0, 30> $value']],
            'list of classes' => [new NullableType(new ListType($tag)), '8.2', ['@param ?list<Tag> $value']],
            'list of refined scalars' => [new ListType($name), '8.2', ['@param list<string> $value', '@phpstan-param list<non-empty-string> $value']],
            'map of refined scalars' => [new MapType(ScalarType::int('non-negative-int')), '8.2', ['@param array<array-key, int> $value', '@phpstan-param array<array-key, non-negative-int> $value']],
            'string enum without PHP enums' => [new NullableType($currency), '7.4', ['@phpstan-param Currency::*|null $value']],
            'int enum without PHP enums' => [$level, '8.0', ['@phpstan-param Level::* $value']],
            'list of enums without PHP enums' => [new ListType($currency), '7.4', ['@param list<string> $value', '@phpstan-param list<Currency::*> $value']],
            'enum with PHP enums' => [new NullableType($currency), '8.2', []],
            'union without native unions' => [new UnionType(ScalarType::int(), $name), '7.4', ['@param int|string $value', '@phpstan-param int|non-empty-string $value']],
            'union of refinements of one kind' => [new UnionType(ScalarType::string('non-empty-string'), ScalarType::string('numeric-string')), '8.2', ['@phpstan-param non-empty-string|numeric-string $value']],
        ];
    }

    /**
     * @return array<string, array{TypeModel, string, ?string, string, bool}>
     */
    public static function types(): array
    {
        $tag = new ClassType(ClassName::fromFqcn('App\Dto\Tag'));
        $date = new ClassType(ClassName::fromFqcn('DateTimeImmutable'));
        $money = new ClassType(ClassName::fromFqcn('Brick\Money\Money'));
        $union = new UnionType(ScalarType::int(), ScalarType::string());
        $currency = new EnumType(ClassName::fromFqcn('App\Dto\Currency'), EnumBacking::from(EnumBacking::STRING), ['EUR' => 'EUR']);
        $level = new EnumType(ClassName::fromFqcn('Other\Level'), EnumBacking::from(EnumBacking::INT), [1 => 'VALUE_1']);

        return [
            'int' => [ScalarType::int(), '8.2', 'int', 'int', false],
            'refined int' => [ScalarType::int('positive-int'), '8.2', 'int', 'positive-int', true],
            'same namespace class' => [$tag, '8.2', 'Tag', 'Tag', false],
            'global class' => [$date, '7.4', '\DateTimeImmutable', '\DateTimeImmutable', false],
            'foreign class' => [$money, '7.4', '\Brick\Money\Money', '\Brick\Money\Money', false],
            'nullable' => [new NullableType(ScalarType::string()), '7.4', '?string', '?string', false],
            'nullable refined' => [new NullableType(ScalarType::string('non-empty-string')), '7.4', '?string', '?non-empty-string', true],
            'list' => [new ListType($tag), '8.2', 'array', 'list<Tag>', true],
            'map' => [new NullableType(new MapType(ScalarType::int())), '8.2', '?array', '?array<array-key, int>', true],
            'mixed on 8.0' => [new MixedType(), '8.0', 'mixed', 'mixed', false],
            'mixed on 7.4' => [new MixedType(), '7.4', null, 'mixed', true],
            'enum on 8.1' => [$currency, '8.1', 'Currency', 'Currency', false],
            'enum on 8.0' => [$currency, '8.0', 'string', 'Currency::*', true],
            'foreign int enum on 7.4' => [$level, '7.4', 'int', '\\Other\\Level::*', true],
            'nullable enum on 7.4' => [new NullableType($currency), '7.4', '?string', 'Currency::*|null', true],
            'nullable list of enums on 7.4' => [new NullableType(new ListType($currency)), '7.4', '?array', 'list<Currency::*>|null', true],
            'list of enums on 8.2' => [new ListType($currency), '8.2', 'array', 'list<Currency>', true],
            'union on 8.0' => [$union, '8.0', 'int|string', 'int|string', false],
            'union on 7.4' => [$union, '7.4', null, 'int|string', true],
            'nullable union on 7.4' => [new NullableType($union), '7.4', null, 'int|string|null', true],
            'nullable union' => [new NullableType($union), '8.0', 'int|string|null', 'int|string|null', false],
            'union of arrays' => [new UnionType(new ListType(ScalarType::int()), new MapType(ScalarType::string())), '8.0', 'array', 'list<int>|array<array-key, string>', true],
            'nullable union of arrays' => [new NullableType(new UnionType(new ListType(ScalarType::int()), new MapType(ScalarType::string()))), '8.0', '?array', 'list<int>|array<array-key, string>|null', true],
        ];
    }

    /**
     * @param Node\ComplexType|Identifier|Name $type
     */
    private function print(Node $type): string
    {
        $printer = new Standard();
        $param = new Param(new Variable('x'), null, $type);
        $code = $printer->prettyPrint([new Function_('f', ['params' => [$param]])]);

        return (string) preg_replace('/^function f\((.*) \$x\).*$/s', '$1', $code);
    }

    /**
     * @dataProvider nodes
     *
     * @param class-string<Node> $class
     */
    public function testBuildsTheMatchingNodeClass(TypeModel $type, string $class): void
    {
        self::assertInstanceOf($class, (new TypeRenderer('App\Dto', EmitterFixture::target('8.2', 'immutable')))->nativeNode($type));
    }

    /**
     * @return array<string, array{TypeModel, class-string<Node>}>
     */
    public static function nodes(): array
    {
        return [
            'builtin' => [ScalarType::int(), Identifier::class],
            'same namespace class' => [new ClassType(ClassName::fromFqcn('App\Dto\Tag')), Name::class],
            'foreign class' => [new ClassType(ClassName::fromFqcn('DateTimeImmutable')), FullyQualified::class],
            'nullable' => [new NullableType(ScalarType::string()), Node\NullableType::class],
            'union' => [new UnionType(ScalarType::int(), ScalarType::string()), Node\UnionType::class],
        ];
    }

    public function testRefusesTypesItCannotEmitYet(): void
    {
        $renderer = new TypeRenderer('App\Dto', EmitterFixture::target('8.2', 'immutable'));
        $enum = new class implements TypeModel {
            public function describe(): string
            {
                return 'App\Dto\Currency';
            }
        };

        foreach ([static fn (): ?string => $renderer->native($enum), static fn (): string => $renderer->doc($enum)] as $render) {
            try {
                $render();
                self::fail('An unknown type was accepted.');
            } catch (LogicException $exception) {
                self::assertSame('Type App\Dto\Currency cannot be emitted yet.', $exception->getMessage());
            }
        }
    }
}
