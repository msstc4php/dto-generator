<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Domain\Model;

use Closure;
use LogicException;
use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\ArgumentValue;
use MSSTC4PHP\DtoGenerator\Domain\Model\AttributeArgument;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class ArgumentValueTest extends TestCase
{
    /**
     * @dataProvider literals
     *
     * @param scalar|null $value
     */
    public function testHoldsScalarLiterals($value): void
    {
        $argument = ArgumentValue::literal($value);

        self::assertSame(ArgumentValue::KIND_LITERAL, $argument->kind());
        self::assertSame($value, $argument->literalValue());
    }

    /**
     * @return array<string, array{scalar|null}>
     */
    public static function literals(): array
    {
        return [
            'null' => [null],
            'bool' => [true],
            'int' => [4],
            'float' => [1.5],
            'string' => ['tail'],
        ];
    }

    /**
     * @dataProvider nonFiniteFloats
     */
    public function testRejectsNonFiniteFloats(float $value): void
    {
        $this->expectException(InvalidModel::class);
        $this->expectExceptionMessage('finite');

        ArgumentValue::literal($value);
    }

    /**
     * @return array<string, array{float}>
     */
    public static function nonFiniteFloats(): array
    {
        return [
            'infinity' => [INF],
            'negative infinity' => [-INF],
            'not a number' => [NAN],
        ];
    }

    public function testRejectsNonScalarLiterals(): void
    {
        $this->expectException(InvalidModel::class);
        $this->expectExceptionMessage('must be a scalar or null');

        ArgumentValue::literal([1]);
    }

    /**
     * @requires PHP >= 8.0
     */
    public function testListOfDropsKeysOfUnknownNamedArguments(): void
    {
        // Unknown named arguments are collected into the variadic under their names.
        $list = (new ReflectionMethod(ArgumentValue::class, 'listOf'))->invokeArgs(null, ['a' => ArgumentValue::literal(1)]);

        self::assertInstanceOf(ArgumentValue::class, $list);
        self::assertSame([0], array_keys($list->listItems()));
    }

    public function testHoldsListsAndMaps(): void
    {
        $one = ArgumentValue::literal(1);
        $list = ArgumentValue::listOf($one, $one);
        $map = ArgumentValue::mapOf(['keep' => $one, '200' => $one]);

        self::assertSame(ArgumentValue::KIND_LIST, $list->kind());
        self::assertSame([$one, $one], $list->listItems());
        self::assertSame(ArgumentValue::KIND_MAP, $map->kind());
        self::assertSame(['keep', '200'], array_map('strval', array_keys($map->mapItems())));
    }

    public function testHoldsConstants(): void
    {
        $classConstant = ArgumentValue::constant('TAIL', ClassName::fromFqcn('App\Mask'));
        $globalConstant = ArgumentValue::constant('PHP_INT_MAX');

        self::assertSame(ArgumentValue::KIND_CONSTANT, $classConstant->kind());
        self::assertSame('TAIL', $classConstant->constantName());
        self::assertNotNull($classConstant->constantClass());
        self::assertSame('App\Mask', $classConstant->constantClass()->fqcn());
        self::assertNull($globalConstant->constantClass());
    }

    /**
     * @dataProvider invalidConstantNames
     */
    public function testRejectsInvalidConstantNames(string $name): void
    {
        $this->expectException(InvalidModel::class);
        $this->expectExceptionMessage('is not a valid constant name');

        ArgumentValue::constant($name, ClassName::fromFqcn('App\Mask'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidConstantNames(): array
    {
        return [
            'class keyword' => ['class'],
            'class keyword uppercase' => ['CLASS'],
            'dash' => ['TAIL-END'],
            'empty' => [''],
            'trailing newline' => ["TAIL\n"],
        ];
    }

    public function testHoldsClassReferences(): void
    {
        $reference = ArgumentValue::classReference(ClassName::fromFqcn('App\User'));

        self::assertSame(ArgumentValue::KIND_CLASS_REFERENCE, $reference->kind());
        self::assertSame('App\User', $reference->className()->fqcn());
    }

    public function testHoldsNestedInstances(): void
    {
        $argument = AttributeArgument::named('min', ArgumentValue::literal(1));
        $instance = ArgumentValue::newInstance(ClassName::fromFqcn('App\Rule'), $argument);

        self::assertSame(ArgumentValue::KIND_NEW_INSTANCE, $instance->kind());
        self::assertSame('App\Rule', $instance->className()->fqcn());
        self::assertSame([$argument], $instance->arguments());
    }

    public function testNestedInstancesValidateArgumentOrder(): void
    {
        $this->expectException(InvalidModel::class);
        $this->expectExceptionMessage('Positional argument after named');

        ArgumentValue::newInstance(
            ClassName::fromFqcn('App\Rule'),
            AttributeArgument::named('min', ArgumentValue::literal(1)),
            AttributeArgument::positional(ArgumentValue::literal(2)),
        );
    }

    /**
     * @dataProvider wrongKindAccessors
     *
     * @param Closure(ArgumentValue): void $access
     */
    public function testEveryAccessorGuardsItsKind(ArgumentValue $value, Closure $access): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('is not one of');

        $access($value);
    }

    /**
     * @return array<string, array{ArgumentValue, Closure(ArgumentValue): void}>
     */
    public static function wrongKindAccessors(): array
    {
        $class = ClassName::fromFqcn('App\Rule');

        return [
            'literalValue on a list' => [ArgumentValue::listOf(), static function (ArgumentValue $value): void {
                $value->literalValue();
            }],
            'listItems on a map' => [ArgumentValue::mapOf([]), static function (ArgumentValue $value): void {
                $value->listItems();
            }],
            'mapItems on a list' => [ArgumentValue::listOf(), static function (ArgumentValue $value): void {
                $value->mapItems();
            }],
            'constantName on a literal' => [ArgumentValue::literal(1), static function (ArgumentValue $value): void {
                $value->constantName();
            }],
            'constantClass on a literal' => [ArgumentValue::literal(1), static function (ArgumentValue $value): void {
                $value->constantClass();
            }],
            'className on a constant' => [ArgumentValue::constant('TAIL', $class), static function (ArgumentValue $value): void {
                $value->className();
            }],
            'arguments on a class reference' => [ArgumentValue::classReference($class), static function (ArgumentValue $value): void {
                $value->arguments();
            }],
        ];
    }

    public function testAccessorsOfAnotherKindThrow(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('is not one of');

        ArgumentValue::literal(1)->className();
    }

    public function testListsTheValuesDirectlyInsideAValue(): void
    {
        $one = ArgumentValue::literal(1);
        $two = ArgumentValue::constant('TWO');
        $three = ArgumentValue::classReference(ClassName::fromFqcn('App\\Three'));

        self::assertSame([$one, $two], ArgumentValue::listOf($one, $two)->children());
        self::assertSame([$one, $three], ArgumentValue::mapOf(['a' => $one, 5 => $three])->children());
        self::assertSame([$one, $two], ArgumentValue::newInstance(ClassName::fromFqcn('App\\Created'), AttributeArgument::positional($one), AttributeArgument::named('n', $two))->children());
        self::assertSame([], $one->children());
        self::assertSame([], $two->children());
        self::assertSame([], $three->children());
    }
}
