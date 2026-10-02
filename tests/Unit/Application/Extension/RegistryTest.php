<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Application\Extension;

use Closure;
use MSSTC4PHP\DtoGenerator\Application\Extension\Registry;
use MSSTC4PHP\DtoGenerator\Contract\ClassContext;
use MSSTC4PHP\DtoGenerator\Contract\ClassEnricher;
use MSSTC4PHP\DtoGenerator\Contract\Extension;
use MSSTC4PHP\DtoGenerator\Contract\ExtensionRegistry;
use MSSTC4PHP\DtoGenerator\Contract\FormatMapping;
use MSSTC4PHP\DtoGenerator\Contract\InstalledPackages;
use MSSTC4PHP\DtoGenerator\Contract\PropertyContext;
use MSSTC4PHP\DtoGenerator\Contract\PropertyEnricher;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostic;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Model\AttributeModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassType;
use MSSTC4PHP\DtoGenerator\Domain\Model\ScalarType;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;
use MSSTC4PHP\DtoGenerator\Domain\Target\Mutability;
use MSSTC4PHP\DtoGenerator\Tests\Support\EmitterFixture;
use MSSTC4PHP\DtoGenerator\Tests\Support\GraphFixture;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RegistryTest extends TestCase
{
    private const CONFIG = '/project/dto-generator.yaml';

    public function testCollectsAttributesFromEnrichersInRegistrationOrder(): void
    {
        $registry = $this->registry($diagnostics);
        $registry->register($this->extension('first', static function (ExtensionRegistry $registry): void {
            $registry->addPropertyEnricher(self::propertyEnricher('App\First'));
            $registry->addClassEnricher(self::classEnricher('App\FirstClass'));
        }), []);
        $registry->register($this->extension('second', static function (ExtensionRegistry $registry): void {
            $registry->addPropertyEnricher(self::propertyEnricher('App\Second'));
        }), []);

        self::assertSame(['App\First', 'App\Second'], $this->names($registry->enrichProperty($this->propertyContext($diagnostics))));
        self::assertSame(['App\FirstClass'], $this->names($registry->enrichClass($this->classContext($diagnostics))));
        self::assertSame([], $this->messages($diagnostics));
    }

    public function testPassesEachExtensionItsConfig(): void
    {
        $registry = $this->registry($diagnostics);
        $seen = null;
        $registry->register($this->extension('mine', static function (ExtensionRegistry $registry, array $config) use (&$seen): void {
            $seen = $config;
        }), ['level' => 2]);

        self::assertSame(['level' => 2], $seen);
    }

    public function testReportsAnExtensionThatFailsToRegister(): void
    {
        $registry = $this->registry($diagnostics);
        $registry->register($this->extension('broken', static function (): void {
            throw new RuntimeException('boom');
        }), []);

        self::assertSame(['error ' . self::CONFIG . '#: Extension "broken" failed to register: boom'], $this->messages($diagnostics));
    }

    public function testReportsAnEnricherThatFailsAndKeepsTheOthers(): void
    {
        $registry = $this->registry($diagnostics);
        $registry->register($this->extension('broken', static function (ExtensionRegistry $registry): void {
            $registry->addPropertyEnricher(new class implements PropertyEnricher {
                public function enrichProperty(PropertyContext $context): array
                {
                    throw new RuntimeException('no luck');
                }
            });
            $registry->addClassEnricher(new class implements ClassEnricher {
                public function enrichClass(ClassContext $context): array
                {
                    throw new RuntimeException('no class');
                }
            });
            $registry->addPropertyEnricher(self::propertyEnricher('App\After'));
        }), []);

        $property = $this->propertyContext($diagnostics);
        self::assertSame(['App\After'], $this->names($registry->enrichProperty($property)));
        self::assertSame([], $registry->enrichClass($this->classContext($diagnostics)));
        self::assertSame(
            [
                'error ' . $property->schema()->location()->toString() . ': Extension "broken" failed on property "label": no luck',
                'error /project/api/openapi.yaml#/components/schemas/Tag: Extension "broken" failed on class App\Dto\Tag: no class',
            ],
            $this->messages($diagnostics),
        );
    }

    public function testMergesFormatsWithTheConfigWinning(): void
    {
        $registry = $this->registry($diagnostics);
        $registry->register($this->extension('uid', static function (ExtensionRegistry $registry): void {
            $registry->addFormat('uuid', new FormatMapping(new ClassType(ClassName::fromFqcn('Symfony\Component\Uid\Uuid'))));
            $registry->addFormat('ulid', new FormatMapping(new ClassType(ClassName::fromFqcn('Symfony\Component\Uid\Ulid'))));
        }), []);
        $configured = new ClassType(ClassName::fromFqcn('App\Uuid'));

        $formats = $registry->formats(['uuid' => $configured]);

        self::assertSame(['uuid', 'ulid'], array_keys($formats));
        self::assertSame($configured, $formats['uuid']);
        self::assertSame('Symfony\Component\Uid\Ulid', $formats['ulid']->describe());
    }

    public function testReportsTwoExtensionsRegisteringOneFormat(): void
    {
        $registry = $this->registry($diagnostics);
        $add = static function (ExtensionRegistry $registry): void {
            $registry->addFormat('money', new FormatMapping(ScalarType::string()));
        };
        $registry->register($this->extension('one', $add), []);
        $registry->register($this->extension('two', $add), []);

        self::assertSame(['error ' . self::CONFIG . '#: Extensions "one" and "two" both register format "money".'], $this->messages($diagnostics));
        self::assertSame('string', $registry->formats([])['money']->describe());
    }

    public function testClaimsExtensionKeysByGlob(): void
    {
        $registry = $this->registry($diagnostics);
        $registry->register($this->extension('symfony', static function (ExtensionRegistry $registry): void {
            $registry->claimExtensionKeys('x-assert-*', 'x-serializer-group?');
        }), []);

        self::assertTrue($registry->isClaimed('x-assert-length'));
        self::assertTrue($registry->isClaimed('x-serializer-groups'));
        self::assertFalse($registry->isClaimed('x-serializer-group'));
        self::assertFalse($registry->isClaimed('x-other'));
        self::assertFalse($registry->isClaimed('x-assert'));
        self::assertSame([], $this->messages($diagnostics));
    }

    public function testRefusesClaimsOnTheCoreVocabulary(): void
    {
        $registry = $this->registry($diagnostics);
        $registry->register($this->extension('greedy', static function (ExtensionRegistry $registry): void {
            $registry->claimExtensionKeys('x-*', 'x-php-type', 'name', 'x-enum-*', 'x-ok');
        }), []);

        self::assertSame(
            [
                'error ' . self::CONFIG . '#: Extension "greedy" cannot claim "x-*": it covers keys of the core vocabulary.',
                'error ' . self::CONFIG . '#: Extension "greedy" cannot claim "x-php-type": it covers keys of the core vocabulary.',
                'error ' . self::CONFIG . '#: Extension "greedy" cannot claim "name": extension keys start with "x-".',
                'error ' . self::CONFIG . '#: Extension "greedy" cannot claim "x-enum-*": it covers keys of the core vocabulary.',
            ],
            $this->messages($diagnostics),
        );
        self::assertTrue($registry->isClaimed('x-ok'));
        self::assertFalse($registry->isClaimed('x-anything'));
    }

    private static function propertyEnricher(string $attribute): PropertyEnricher
    {
        return new class($attribute) implements PropertyEnricher {
            private string $attribute;

            public function __construct(string $attribute)
            {
                $this->attribute = $attribute;
            }

            public function enrichProperty(PropertyContext $context): array
            {
                return [new AttributeModel(ClassName::fromFqcn($this->attribute))];
            }
        };
    }

    private static function classEnricher(string $attribute): ClassEnricher
    {
        return new class($attribute) implements ClassEnricher {
            private string $attribute;

            public function __construct(string $attribute)
            {
                $this->attribute = $attribute;
            }

            public function enrichClass(ClassContext $context): array
            {
                return [new AttributeModel(ClassName::fromFqcn($this->attribute))];
            }
        };
    }

    /**
     * @param non-empty-string $name
     * @param Closure(ExtensionRegistry, array<int|string, mixed>):void $register
     */
    private function extension(string $name, Closure $register): Extension
    {
        return new class($name, $register) implements Extension {
            /** @var non-empty-string */
            private string $name;

            /** @var Closure(ExtensionRegistry, array<int|string, mixed>):void */
            private Closure $register;

            /**
             * @param non-empty-string $name
             * @param Closure(ExtensionRegistry, array<int|string, mixed>):void $register
             */
            public function __construct(string $name, Closure $register)
            {
                $this->name = $name;
                $this->register = $register;
            }

            public function name(): string
            {
                return $this->name;
            }

            public function register(ExtensionRegistry $registry, array $config): void
            {
                ($this->register)($registry, $config);
            }
        };
    }

    /**
     * @param-out Diagnostics $diagnostics
     */
    private function registry(?Diagnostics &$diagnostics): Registry
    {
        $diagnostics = new Diagnostics();

        return new Registry($diagnostics, new SchemaLocation(self::CONFIG));
    }

    private function propertyContext(Diagnostics $diagnostics): PropertyContext
    {
        $tag = EmitterFixture::tag();
        $schema = GraphFixture::load(['Tag' => ['type' => 'object', 'properties' => ['label' => ['type' => 'string']]]])->all()[0]->schema()->requireProperty('label');

        return new PropertyContext($tag->properties()[0], $tag, $schema, EmitterFixture::target('8.2', Mutability::IMMUTABLE), new InstalledPackages(), $diagnostics);
    }

    private function classContext(Diagnostics $diagnostics): ClassContext
    {
        $schema = GraphFixture::load(['Tag' => ['type' => 'object', 'properties' => ['label' => ['type' => 'string']]]])->all()[0]->schema();

        return new ClassContext(EmitterFixture::tag(), $schema, EmitterFixture::target('8.2', Mutability::IMMUTABLE), new InstalledPackages(), $diagnostics);
    }

    /**
     * @param list<AttributeModel> $attributes
     *
     * @return list<string>
     */
    private function names(array $attributes): array
    {
        return array_map(static fn (AttributeModel $attribute): string => $attribute->className()->fqcn(), $attributes);
    }

    /**
     * @return list<string>
     */
    private function messages(?Diagnostics $diagnostics): array
    {
        self::assertNotNull($diagnostics);

        return array_map(static fn (Diagnostic $diagnostic): string => $diagnostic->toString(), $diagnostics->all());
    }
}
