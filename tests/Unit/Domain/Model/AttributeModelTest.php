<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Domain\Model;

use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\ArgumentValue;
use MSSTC4PHP\DtoGenerator\Domain\Model\AttributeArgument;
use MSSTC4PHP\DtoGenerator\Domain\Model\AttributeModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;
use MSSTC4PHP\DtoGenerator\Domain\Model\ImportAlias;
use PHPUnit\Framework\TestCase;

final class AttributeModelTest extends TestCase
{
    public function testHoldsClassArgumentsAndAlias(): void
    {
        $alias = new ImportAlias('\Symfony\Component\Validator\Constraints', 'Assert');
        $arguments = [
            AttributeArgument::positional(ArgumentValue::literal(1)),
            AttributeArgument::named('max', ArgumentValue::literal(10)),
        ];
        $attribute = new AttributeModel(ClassName::fromFqcn('Symfony\Component\Validator\Constraints\Length'), $arguments, $alias);

        self::assertSame('Symfony\Component\Validator\Constraints\Length', $attribute->className()->fqcn());
        self::assertSame($arguments, $attribute->arguments());
        self::assertSame($alias, $attribute->importAlias());
        self::assertSame('Symfony\Component\Validator\Constraints', $alias->namespace());
        self::assertSame('Assert', $alias->alias());
    }

    public function testArgumentsDefaultToNone(): void
    {
        $attribute = new AttributeModel(ClassName::fromFqcn('App\Sensitive'));

        self::assertSame([], $attribute->arguments());
        self::assertNull($attribute->importAlias());
    }

    public function testRejectsAnAliasOutsideTheClassNamespace(): void
    {
        $this->expectException(InvalidModel::class);
        $this->expectExceptionMessage('is not inside the import alias namespace');

        new AttributeModel(ClassName::fromFqcn('App\Sensitive'), [], new ImportAlias('Symfony\Component\Validator\Constraints', 'Assert'));
    }

    public function testRejectsAnInvalidAlias(): void
    {
        $this->expectException(InvalidModel::class);

        new ImportAlias('Symfony\Component\Validator\Constraints', 'As-sert');
    }

    public function testRejectsPositionalAfterNamed(): void
    {
        $this->expectException(InvalidModel::class);
        $this->expectExceptionMessage('Positional argument after named');

        AttributeArgument::assertWellFormed([
            AttributeArgument::named('max', ArgumentValue::literal(10)),
            AttributeArgument::positional(ArgumentValue::literal(1)),
        ]);
    }

    public function testRejectsDuplicateNamedArguments(): void
    {
        $this->expectException(InvalidModel::class);
        $this->expectExceptionMessage('Named argument "max" is repeated');

        AttributeArgument::assertWellFormed([
            AttributeArgument::named('max', ArgumentValue::literal(10)),
            AttributeArgument::named('max', ArgumentValue::literal(11)),
        ]);
    }

    public function testRejectsAnInvalidArgumentName(): void
    {
        $this->expectException(InvalidModel::class);

        AttributeArgument::named('max-length', ArgumentValue::literal(1));
    }

    public function testExposesArgumentParts(): void
    {
        $value = ArgumentValue::literal(1);

        self::assertFalse(AttributeArgument::positional($value)->isNamed());
        self::assertNull(AttributeArgument::positional($value)->name());
        self::assertTrue(AttributeArgument::named('min', $value)->isNamed());
        self::assertSame('min', AttributeArgument::named('min', $value)->name());
        self::assertSame($value, AttributeArgument::named('min', $value)->value());
    }
}
