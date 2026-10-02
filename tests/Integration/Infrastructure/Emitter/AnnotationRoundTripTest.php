<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Integration\Infrastructure\Emitter;

use Doctrine\Common\Annotations\AnnotationReader;
use MSSTC4PHP\DtoGenerator\Domain\Model\ArgumentValue;
use MSSTC4PHP\DtoGenerator\Domain\Model\AttributeArgument;
use MSSTC4PHP\DtoGenerator\Domain\Model\AttributeModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;
use MSSTC4PHP\DtoGenerator\Domain\Model\ImportAlias;
use MSSTC4PHP\DtoGenerator\Domain\Model\ScalarType;
use MSSTC4PHP\DtoGenerator\Domain\Target\Mutability;
use MSSTC4PHP\DtoGenerator\Infrastructure\Emitter\PhpParserEmitter;
use MSSTC4PHP\DtoGenerator\Tests\Support\Annotations\Inner;
use MSSTC4PHP\DtoGenerator\Tests\Support\Annotations\Note;
use MSSTC4PHP\DtoGenerator\Tests\Support\Annotations\Pair;
use MSSTC4PHP\DtoGenerator\Tests\Support\Annotations\Ref;
use MSSTC4PHP\DtoGenerator\Tests\Support\EmitterFixture;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

/**
 * Doctrine reads back what the emitter writes for PHP 7.4 (spec §7.1).
 */
final class AnnotationRoundTripTest extends TestCase
{
    private const SUPPORT = 'MSSTC4PHP\DtoGenerator\Tests\Support\Annotations';

    public function testDoctrineReadsTheAnnotationsAsTheyWereModelled(): void
    {
        $namespace = 'MSSTC4PHP\DtoGenerator\Tests\Generated\RoundTrip' . bin2hex(random_bytes(4));
        $order = ClassName::fromFqcn($namespace . '\Order');
        $class = EmitterFixture::model($order->fqcn(), 'An order.', [
            EmitterFixture::property('id', ScalarType::int(), true)->withAddedAttributes(
                new AttributeModel(ClassName::fromFqcn(Note::class), [AttributeArgument::positional(ArgumentValue::literal('only'))], new ImportAlias(self::SUPPORT, 'Ann')),
            ),
        ])->withAddedAttributes(
            new AttributeModel(ClassName::fromFqcn(Note::class), [AttributeArgument::named('text', ArgumentValue::literal('say "hi" from C:\dir'))]),
            new AttributeModel(ClassName::fromFqcn(Pair::class), [
                AttributeArgument::positional(ArgumentValue::literal('a')),
                AttributeArgument::positional(ArgumentValue::literal(2.0)),
                AttributeArgument::named('strict', ArgumentValue::literal(true)),
            ]),
            new AttributeModel(ClassName::fromFqcn(Ref::class), [
                AttributeArgument::named('type', ArgumentValue::classReference($order)),
                AttributeArgument::named('level', ArgumentValue::constant('LEVEL', ClassName::fromFqcn(Ref::class))),
                AttributeArgument::named('inner', ArgumentValue::listOf(
                    ArgumentValue::newInstance(ClassName::fromFqcn(Inner::class), AttributeArgument::named('size', ArgumentValue::literal(2))),
                    ArgumentValue::newInstance(ClassName::fromFqcn(Inner::class)),
                )),
                AttributeArgument::named('map', ArgumentValue::mapOf(['k' => ArgumentValue::literal(null), 'long' => ArgumentValue::literal(str_repeat('x', 80))])),
            ]),
        );

        $file = tempnam(sys_get_temp_dir(), 'dto-generator-round-trip-');
        self::assertIsString($file);
        file_put_contents($file, (new PhpParserEmitter())->emit($class, EmitterFixture::target('7.4', Mutability::IMMUTABLE)));
        // Doctrine reads the use statements from the class's file.
        try {
            require $file;
            $fqcn = $order->fqcn();
            if (!class_exists($fqcn)) {
                self::fail('The generated class did not load.');
            }

            $reader = new AnnotationReader();
            $annotations = $reader->getClassAnnotations(new ReflectionClass($fqcn));
            $property = $reader->getPropertyAnnotations(new ReflectionProperty($fqcn, 'id'));
        } finally {
            unlink($file);
        }

        self::assertCount(3, $annotations);
        [$note, $pair, $ref] = $annotations;
        self::assertInstanceOf(Note::class, $note);
        self::assertSame('say "hi" from C:\dir', $note->text);
        self::assertInstanceOf(Pair::class, $pair);
        self::assertSame(['a', 2.0], $pair->value);
        self::assertTrue($pair->strict);
        self::assertInstanceOf(Ref::class, $ref);
        self::assertSame($order->fqcn(), $ref->type);
        self::assertSame(Ref::LEVEL, $ref->level);
        self::assertIsArray($ref->inner);
        self::assertCount(2, $ref->inner);
        self::assertInstanceOf(Inner::class, $ref->inner[0]);
        self::assertSame(2, $ref->inner[0]->size);
        self::assertInstanceOf(Inner::class, $ref->inner[1]);
        self::assertNull($ref->inner[1]->size);
        self::assertSame(['k' => null, 'long' => str_repeat('x', 80)], $ref->map);
        self::assertCount(1, $property);
        self::assertInstanceOf(Note::class, $property[0]);
        self::assertSame('only', $property[0]->value);
    }
}
