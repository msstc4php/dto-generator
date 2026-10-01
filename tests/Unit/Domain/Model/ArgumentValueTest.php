<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Domain\Model;

use LogicException;
use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\ArgumentValue;
use MSSTC4PHP\DtoGenerator\Domain\Model\AttributeArgument;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;
use PHPUnit\Framework\TestCase;

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

        ArgumentValue::literal([1]);
    }

    public function testHoldsListsAndMaps(): void
    {
        $one = ArgumentValue::literal(1);
        $list = ArgumentValue::listOf($one, $one);
        $map = ArgumentValue::mapOf(['keep' => $one, '200' => $one]);

        self::assertSame(ArgumentValue::KIND_LIST, $list->kind());
        self::assertSame([$one, $one], $list->items());
        self::assertSame(ArgumentValue::KIND_MAP, $map->kind());
        self::assertSame(['keep', '200'], array_map('strval', array_keys($map->items())));
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

        ArgumentValue::constant($name, ClassName::fromFqcn('App\Mask'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidConstantNames(): array
    {
        return [
            'class keyword' => ['class'],
            'dash' => ['TAIL-END'],
            'empty' => [''],
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

        ArgumentValue::newInstance(
            ClassName::fromFqcn('App\Rule'),
            AttributeArgument::named('min', ArgumentValue::literal(1)),
            AttributeArgument::positional(ArgumentValue::literal(2)),
        );
    }

    public function testAccessorsOfAnotherKindThrow(): void
    {
        $this->expectException(LogicException::class);

        ArgumentValue::literal(1)->className();
    }
}
