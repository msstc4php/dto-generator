<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Infrastructure\Emitter;

use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassType;
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
        self::assertSame($needsDoc, $renderer->needsDoc($type));
        $node = $renderer->nativeNode($type);
        self::assertSame($native, $node instanceof Node ? $this->print($node) : null);
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
            'union on 8.0' => [$union, '8.0', 'int|string', 'int|string', false],
            'union on 7.4' => [$union, '7.4', null, 'int|string', true],
            'nullable union' => [new NullableType($union), '8.0', 'int|string|null', 'int|string|null', false],
            'union of arrays' => [new UnionType(new ListType(ScalarType::int()), new MapType(ScalarType::string())), '8.0', 'array', 'list<int>|array<array-key, string>', true],
            'nullable union of arrays' => [new NullableType(new UnionType(new ListType(ScalarType::int()), new MapType(ScalarType::string()))), '8.0', '?array', 'list<int>|array<array-key, string>|null', true],
        ];
    }

    /**
     * @param Node\ComplexType|Node\Identifier|Node\Name $type
     */
    private function print(Node $type): string
    {
        $printer = new Standard();
        $param = new Param(new Variable('x'), null, $type);
        $code = $printer->prettyPrint([new Function_('f', ['params' => [$param]])]);

        return (string) preg_replace('/^function f\((.*) \$x\).*$/s', '$1', $code);
    }
}
