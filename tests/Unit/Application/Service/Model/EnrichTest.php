<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Application\Service\Model;

use Closure;
use MSSTC4PHP\DtoGenerator\Application\Extension\Registry;
use MSSTC4PHP\DtoGenerator\Application\Port\ClassVerifier;
use MSSTC4PHP\DtoGenerator\Application\Service\Model\Enrich\Action;
use MSSTC4PHP\DtoGenerator\Application\Service\Model\Enrich\Input;
use MSSTC4PHP\DtoGenerator\Application\Service\Model\Enrich\Output;
use MSSTC4PHP\DtoGenerator\Contract\ClassContext;
use MSSTC4PHP\DtoGenerator\Contract\ClassEnricher;
use MSSTC4PHP\DtoGenerator\Contract\Extension;
use MSSTC4PHP\DtoGenerator\Contract\ExtensionRegistry;
use MSSTC4PHP\DtoGenerator\Contract\InstalledPackages;
use MSSTC4PHP\DtoGenerator\Contract\PropertyContext;
use MSSTC4PHP\DtoGenerator\Contract\PropertyEnricher;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostic;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Model\ArgumentValue;
use MSSTC4PHP\DtoGenerator\Domain\Model\AttributeArgument;
use MSSTC4PHP\DtoGenerator\Domain\Model\AttributeModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;
use MSSTC4PHP\DtoGenerator\Domain\Model\ImportAlias;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;
use MSSTC4PHP\DtoGenerator\Domain\Target\AccessorStyle;
use MSSTC4PHP\DtoGenerator\Domain\Target\DateTimeClass;
use MSSTC4PHP\DtoGenerator\Domain\Target\MetadataMode;
use MSSTC4PHP\DtoGenerator\Domain\Target\Mutability;
use MSSTC4PHP\DtoGenerator\Domain\Target\PhpVersion;
use MSSTC4PHP\DtoGenerator\Domain\Target\TargetProfile;
use MSSTC4PHP\DtoGenerator\Extension\CustomAttributes\CustomAttributes;
use MSSTC4PHP\DtoGenerator\Tests\Support\Extensions\MarkingExtension;
use MSSTC4PHP\DtoGenerator\Tests\Support\FixedClassVerifier;
use MSSTC4PHP\DtoGenerator\Tests\Support\GraphFixture;
use MSSTC4PHP\DtoGenerator\Tests\Support\ModelFixture;
use PHPUnit\Framework\TestCase;

final class EnrichTest extends TestCase
{
    private const AT = '/project/api/openapi.yaml#/components/schemas/';

    private const SCHEMAS = [
        'Pet' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]],
        'Cat' => ['allOf' => [['$ref' => '#/components/schemas/Pet'], ['properties' => ['lives' => ['type' => 'integer'], 'toy' => ['type' => 'object', 'properties' => ['kind' => []]]]]]],
    ];

    public function testAddsTheAttributesOfEveryEnricherToClassesAndTheirOwnProperties(): void
    {
        $output = $this->enrich(self::SCHEMAS, static function (ExtensionRegistry $registry): void {
            (new MarkingExtension())->register($registry, ['label' => 'dto']);
        });

        self::assertSame([], $this->messages($output));
        self::assertSame(
            [
                'App\Dto\Pet' => ['class: App\Attr\Marked(dto)', 'name: App\Attr\Marked(name)'],
                'App\Dto\Cat' => ['class: App\Attr\Marked(dto)', 'lives: App\Attr\Marked(lives)', 'toy: App\Attr\Marked(toy)'],
                'App\Dto\CatToy' => ['class: App\Attr\Marked(dto)', 'kind: App\Attr\Marked(kind)'],
            ],
            $this->attributes($output),
        );
    }

    public function testRefusesNewInAttributeArgumentsBelowPhp81WhenStrict(): void
    {
        $at = self::AT;
        $output = $this->enrich(self::SCHEMAS, $this->newInArguments(), '8.0', true);
        $messages = $this->messages($output);

        self::assertContains("error {$at}Pet/properties/name: Attribute App\\Attr\\Rule uses \"new\" in its arguments, which PHP 8.0 does not allow (from 8.1).", $messages);
        self::assertContains("error {$at}Cat/allOf/1/properties/lives: Attribute App\\Attr\\Rule uses \"new\" in its arguments, which PHP 8.0 does not allow (from 8.1).", $messages);
        self::assertNotContains('name: App\Attr\Rule(App\Attr\Inner)', $this->attributes($output)['App\Dto\Pet']);
    }

    public function testDropsAttributesWithNewBelowPhp81WhenNotStrict(): void
    {
        $at = self::AT;
        $output = $this->enrich(['Pet' => self::SCHEMAS['Pet']], $this->newInArguments(), '8.0', false);

        self::assertSame(
            [
                "warning {$at}Pet/properties/name: Attribute App\\Attr\\Rule uses \"new\" in its arguments, which PHP 8.0 does not allow (from 8.1); it is left out.",
                "warning {$at}Pet/properties/name: Attribute App\\Attr\\Deep uses \"new\" in its arguments, which PHP 8.0 does not allow (from 8.1); it is left out.",
            ],
            $this->messages($output),
        );
        self::assertSame(['App\Dto\Pet' => ['name: App\Attr\Plain', 'name: App\Attr\Flat({"k":1})']], $this->attributes($output));
    }

    public function testAcceptsNewInAttributeArgumentsFromPhp81(): void
    {
        $output = $this->enrich(['Pet' => self::SCHEMAS['Pet']], $this->newInArguments(), '8.1', true);

        self::assertSame([], $this->messages($output));
        self::assertSame(['App\Dto\Pet' => ['name: App\Attr\Rule(App\Attr\Inner)', 'name: App\Attr\Plain', 'name: App\Attr\Deep({"k":?})', 'name: App\Attr\Flat({"k":1})']], $this->attributes($output));
    }

    public function testReportsOneAliasForTwoNamespacesInOneFile(): void
    {
        $at = self::AT;
        $pet = ['type' => 'object', 'properties' => ['name' => ['type' => 'string'], 'lives' => ['type' => 'integer']]];
        $output = $this->enrich(['Pet' => $pet], static function (ExtensionRegistry $registry): void {
            $registry->addPropertyEnricher(new class implements PropertyEnricher {
                public function enrichProperty(PropertyContext $context): array
                {
                    return $context->property()->wireName() === 'name'
                        ? [
                            new AttributeModel(ClassName::fromFqcn('App\Plain')),
                            new AttributeModel(ClassName::fromFqcn('Symfony\Component\Validator\Constraints\NotBlank'), [], new ImportAlias('Symfony\Component\Validator\Constraints', 'Assert')),
                        ]
                        : [new AttributeModel(ClassName::fromFqcn('App\Assert\Rule'), [], new ImportAlias('App\Assert', 'Assert'))];
                }
            });
        });

        self::assertSame(["error {$at}Pet: Import alias \"Assert\" stands for both Symfony\\Component\\Validator\\Constraints and App\\Assert in App\\Dto\\Pet."], $this->messages($output));
    }

    private function newInArguments(): Closure
    {
        return static function (ExtensionRegistry $registry): void {
            $registry->addPropertyEnricher(new class implements PropertyEnricher {
                public function enrichProperty(PropertyContext $context): array
                {
                    $inner = ArgumentValue::newInstance(ClassName::fromFqcn('App\Attr\Inner'));

                    return [
                        new AttributeModel(ClassName::fromFqcn('App\Attr\Rule'), [AttributeArgument::positional(ArgumentValue::listOf($inner))]),
                        new AttributeModel(ClassName::fromFqcn('App\Attr\Plain')),
                        new AttributeModel(ClassName::fromFqcn('App\Attr\Deep'), [AttributeArgument::positional(ArgumentValue::mapOf(['k' => ArgumentValue::listOf(ArgumentValue::literal(1), $inner)]))]),
                        new AttributeModel(ClassName::fromFqcn('App\Attr\Flat'), [AttributeArgument::positional(ArgumentValue::mapOf(['k' => ArgumentValue::literal(1)]))]),
                    ];
                }
            });
        };
    }

    /**
     * @param array<string, array<array-key, mixed>> $schemas
     * @param Closure(ExtensionRegistry):void $register
     */
    private function enrich(array $schemas, Closure $register, string $php = '8.2', bool $strict = true, string $metadata = MetadataMode::ATTRIBUTES, ?ClassVerifier $verifier = null): Output
    {
        $built = ModelFixture::build($schemas);
        $diagnostics = new Diagnostics();
        $registry = new Registry($diagnostics, new SchemaLocation('/project/dto-generator.yaml'));
        $registry->register(new class($register) implements Extension {
            /** @var Closure(ExtensionRegistry):void */
            private Closure $register;

            /**
             * @param Closure(ExtensionRegistry):void $register
             */
            public function __construct(Closure $register)
            {
                $this->register = $register;
            }

            public function name(): string
            {
                return 'test';
            }

            public function register(ExtensionRegistry $registry, array $config): void
            {
                ($this->register)($registry);
            }
        }, []);
        $target = new TargetProfile(
            PhpVersion::fromString($php),
            MetadataMode::from($metadata),
            Mutability::from(Mutability::IMMUTABLE),
            AccessorStyle::from(AccessorStyle::AUTO),
            DateTimeClass::from(DateTimeClass::IMMUTABLE),
            $strict,
        );

        return (new Action())(new Input($built->classes(), $built->enums(), GraphFixture::load($schemas), $target, $registry, new InstalledPackages(), $verifier));
    }

    /**
     * @return Closure(ExtensionRegistry): void
     */
    private function propertyAttributes(AttributeModel ...$attributes): Closure
    {
        return static function (ExtensionRegistry $registry) use ($attributes): void {
            $registry->addPropertyEnricher(new class($attributes) implements PropertyEnricher {
                /** @var list<AttributeModel> */
                private array $attributes;

                /**
                 * @param list<AttributeModel> $attributes
                 */
                public function __construct(array $attributes)
                {
                    $this->attributes = $attributes;
                }

                public function enrichProperty(PropertyContext $context): array
                {
                    return $this->attributes;
                }
            });
        };
    }

    /**
     * @return array<string, list<string>> class → "class|property: Attribute(first argument)"
     */
    private function attributes(Output $output): array
    {
        $result = [];
        foreach ($output->classes() as $class) {
            $model = $class->model();
            $lines = [];
            foreach ($model->attributes() as $attribute) {
                $lines[] = 'class: ' . $this->describe($attribute);
            }

            foreach ($model->properties() as $property) {
                foreach ($property->attributes() as $attribute) {
                    $lines[] = $property->wireName() . ': ' . $this->describe($attribute);
                }
            }

            $result[$model->name()->fqcn()] = $lines;
        }

        return $result;
    }

    private function describe(AttributeModel $attribute): string
    {
        $arguments = $attribute->arguments();
        if ($arguments === []) {
            return $attribute->className()->fqcn();
        }

        $value = $arguments[0]->value();
        if ($value->kind() === ArgumentValue::KIND_MAP) {
            $item = $value->mapItems()['k'];
            $first = $item->kind() === ArgumentValue::KIND_LITERAL ? '{"k":1}' : '{"k":?}';
        } else {
            $first = $value->kind() === ArgumentValue::KIND_LIST ? $value->listItems()[0]->className()->fqcn() : (string) json_encode($value->literalValue());
        }

        return $attribute->className()->fqcn() . '(' . trim($first, '"') . ')';
    }

    /**
     * @return list<string>
     */
    private function messages(Output $output): array
    {
        return array_map(static fn (Diagnostic $diagnostic): string => $diagnostic->toString(), $output->diagnostics()->all());
    }

    public function testPutsTheAttributesOfAnInlineObjectOnItsPropertyOnly(): void
    {
        $output = $this->enrich(
            ['Order' => ['type' => 'object', 'properties' => [
                'inline' => ['type' => 'object', 'x-php-attributes' => [['class' => 'App\Attr\OnInline']], 'properties' => ['z' => ['type' => 'string']]],
                'lines' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['n' => ['type' => 'integer']]]],
            ]]],
            static function (ExtensionRegistry $registry): void {
                (new CustomAttributes([]))->register($registry, []);
            },
        );

        self::assertSame(['App\Dto\Order' => ['inline: App\Attr\OnInline'], 'App\Dto\OrderInline' => [], 'App\Dto\OrderLinesItem' => []], $this->attributes($output));
    }

    public function testChecksImportAliasesOfAnnotationsToo(): void
    {
        $output = $this->enrich(['Pet' => self::SCHEMAS['Pet']], self::conflictingAliases(), '7.4', true, MetadataMode::ANNOTATIONS);

        self::assertSame(['error ' . self::AT . 'Pet: Import alias "Assert" stands for both App\\One and App\\Two in App\\Dto\\Pet.'], $this->messages($output));
    }

    public function testChecksImportAliasesOnlyWhenMetadataRenders(): void
    {
        $output = $this->enrich(['Pet' => self::SCHEMAS['Pet']], $this->conflictingAliases(), '8.2', true, MetadataMode::NONE);

        self::assertSame([], $this->messages($output));
    }

    public function testComparesImportAliasesWithoutCase(): void
    {
        $output = $this->enrich(['Pet' => self::SCHEMAS['Pet']], static function (ExtensionRegistry $registry): void {
            $registry->addPropertyEnricher(new class implements PropertyEnricher {
                public function enrichProperty(PropertyContext $context): array
                {
                    return [
                        new AttributeModel(ClassName::fromFqcn('App\One\A'), [], new ImportAlias('App\One', 'Assert')),
                        new AttributeModel(ClassName::fromFqcn('App\One\B'), [], new ImportAlias('App\One', 'assert')),
                        new AttributeModel(ClassName::fromFqcn('App\Two\C'), [], new ImportAlias('App\Two', 'ASSERT')),
                    ];
                }
            });
        });

        self::assertSame(['error ' . self::AT . 'Pet: Import alias "ASSERT" stands for both App\\One and App\\Two in App\\Dto\\Pet.'], $this->messages($output));
    }

    private function conflictingAliases(): Closure
    {
        return static function (ExtensionRegistry $registry): void {
            $registry->addPropertyEnricher(new class implements PropertyEnricher {
                public function enrichProperty(PropertyContext $context): array
                {
                    return [
                        new AttributeModel(ClassName::fromFqcn('App\One\A'), [], new ImportAlias('App\One', 'Assert')),
                        new AttributeModel(ClassName::fromFqcn('App\Two\B'), [], new ImportAlias('App\Two', 'Assert')),
                    ];
                }
            });
        };
    }

    public function testReportsClassesAndConstantsTheConsumerLacks(): void
    {
        $at = self::AT;
        $verifier = new FixedClassVerifier(['App\\Attr\\Rule', 'App\\Attr\\Strictness'], ['App\\Attr\\Strictness::STRICT', 'PHP_INT_MAX']);
        $output = $this->enrich(['Pet' => self::SCHEMAS['Pet']], static function (ExtensionRegistry $registry): void {
            $registry->addPropertyEnricher(new class implements PropertyEnricher {
                public function enrichProperty(PropertyContext $context): array
                {
                    return [
                        new AttributeModel(ClassName::fromFqcn('App\\Attr\\Rule'), [
                            AttributeArgument::named('mode', ArgumentValue::constant('STRICT', ClassName::fromFqcn('App\\Attr\\Strictness'))),
                            AttributeArgument::named('limit', ArgumentValue::constant('PHP_INT_MAX')),
                            AttributeArgument::named('other', ArgumentValue::listOf(
                                ArgumentValue::constant('LOOSE', ClassName::fromFqcn('App\\Attr\\Strictness')),
                                ArgumentValue::constant('NOPE'),
                                ArgumentValue::mapOf(['k' => ArgumentValue::classReference(ClassName::fromFqcn('App\\Missing\\Ref'))]),
                                ArgumentValue::newInstance(ClassName::fromFqcn('App\\Missing\\Inner'), AttributeArgument::positional(ArgumentValue::classReference(ClassName::fromFqcn('App\\Missing\\Arg')))),
                            )),
                        ]),
                        new AttributeModel(ClassName::fromFqcn('App\\Missing\\Attribute')),
                    ];
                }
            });
        }, '8.2', true, MetadataMode::ATTRIBUTES, $verifier);

        self::assertSame(
            [
                "error {$at}Pet/properties/name: Constant App\\Attr\\Strictness::LOOSE, used by attribute App\\Attr\\Rule, does not exist.",
                "error {$at}Pet/properties/name: Constant NOPE, used by attribute App\\Attr\\Rule, does not exist.",
                "error {$at}Pet/properties/name: Class App\\Missing\\Ref, used by attribute App\\Attr\\Rule, does not exist.",
                "error {$at}Pet/properties/name: Class App\\Missing\\Inner, used by attribute App\\Attr\\Rule, does not exist.",
                "error {$at}Pet/properties/name: Class App\\Missing\\Arg, used by attribute App\\Attr\\Rule, does not exist.",
                "error {$at}Pet/properties/name: Attribute class App\\Missing\\Attribute does not exist.",
            ],
            $this->messages($output),
        );
    }

    public function testVerifiesNothingWhenNoMetadataRenders(): void
    {
        $output = $this->enrich(['Pet' => self::SCHEMAS['Pet']], static function (ExtensionRegistry $registry): void {
            (new MarkingExtension())->register($registry, []);
        }, '8.2', true, MetadataMode::NONE, new FixedClassVerifier([]));

        self::assertSame([], $this->messages($output));
    }

    public function testWarnsThatAnAnnotationCollectsSeveralPositionalArgumentsIntoValue(): void
    {
        $output = $this->enrich(['Pet' => self::SCHEMAS['Pet']], $this->propertyAttributes(new AttributeModel(ClassName::fromFqcn('App\\Attr\\Pair'), [
            AttributeArgument::positional(ArgumentValue::literal('a')),
            AttributeArgument::positional(ArgumentValue::listOf(
                ArgumentValue::newInstance(ClassName::fromFqcn('App\\Attr\\Inner'), AttributeArgument::positional(ArgumentValue::literal(1)), AttributeArgument::positional(ArgumentValue::literal(2))),
                ArgumentValue::newInstance(ClassName::fromFqcn('App\\Attr\\Blank')),
            )),
        ])), '7.4', true, MetadataMode::ANNOTATIONS);

        self::assertSame(
            [
                'warning ' . self::AT . 'Pet/properties/name: Attribute App\\Attr\\Pair passes 2 positional arguments; an annotation collects them into one list under "value".',
                'warning ' . self::AT . 'Pet/properties/name: Attribute App\\Attr\\Inner passes 2 positional arguments; an annotation collects them into one list under "value".',
            ],
            $this->messages($output),
        );
        self::assertSame(['App\\Dto\\Pet' => ['name: App\\Attr\\Pair(a)']], $this->attributes($output));
    }

    public function testRefusesAnAnnotationWithAPositionalArgumentAndANamedValueWhenStrict(): void
    {
        $attribute = new AttributeModel(ClassName::fromFqcn('App\\Attr\\Rule'), [AttributeArgument::named('inner', ArgumentValue::listOf(
            ArgumentValue::newInstance(ClassName::fromFqcn('App\\Attr\\Blank')),
            ArgumentValue::newInstance(ClassName::fromFqcn('App\\Attr\\Inner'), AttributeArgument::positional(ArgumentValue::literal('a')), AttributeArgument::named('value', ArgumentValue::literal('b'))),
        ))]);
        $kept = new AttributeModel(ClassName::fromFqcn('App\\Attr\\Kept'), [AttributeArgument::named('value', ArgumentValue::literal('only'))]);

        $strict = $this->enrich(['Pet' => self::SCHEMAS['Pet']], $this->propertyAttributes($attribute, $kept), '7.4', true, MetadataMode::ANNOTATIONS);
        $loose = $this->enrich(['Pet' => self::SCHEMAS['Pet']], $this->propertyAttributes($attribute, $kept), '7.4', false, MetadataMode::ANNOTATIONS);

        self::assertSame(['error ' . self::AT . 'Pet/properties/name: Attribute App\\Attr\\Rule passes App\\Attr\\Inner both a positional argument and a named "value", which one annotation cannot hold.'], $this->messages($strict));
        self::assertSame(['warning ' . self::AT . 'Pet/properties/name: Attribute App\\Attr\\Rule passes App\\Attr\\Inner both a positional argument and a named "value", which one annotation cannot hold; it is left out.'], $this->messages($loose));
        self::assertSame(['App\\Dto\\Pet' => ['name: App\\Attr\\Kept(only)']], $this->attributes($loose));
    }

    public function testRefusesAPositionalArgumentBesideANamedValueAtTheTopToo(): void
    {
        $output = $this->enrich(['Pet' => self::SCHEMAS['Pet']], $this->propertyAttributes(new AttributeModel(ClassName::fromFqcn('App\\Attr\\Rule'), [
            AttributeArgument::positional(ArgumentValue::literal('a')),
            AttributeArgument::named('value', ArgumentValue::literal('b')),
        ])), '7.4', true, MetadataMode::ANNOTATIONS);

        self::assertSame(['error ' . self::AT . 'Pet/properties/name: Attribute App\\Attr\\Rule has both a positional argument and a named "value", which one annotation cannot hold.'], $this->messages($output));
    }

    public function testWarnsThatAnAnnotationEscapesLineBreaksAndCommentEnds(): void
    {
        $output = $this->enrich(['Pet' => self::SCHEMAS['Pet']], $this->propertyAttributes(new AttributeModel(ClassName::fromFqcn('App\\Attr\\Note'), [AttributeArgument::named('text', ArgumentValue::mapOf(['k' => ArgumentValue::listOf(ArgumentValue::literal("two\nlines"))]))]), new AttributeModel(ClassName::fromFqcn('App\\Attr\\Key'), [AttributeArgument::positional(ArgumentValue::mapOf(["a\rb" => ArgumentValue::literal(1)]))]), new AttributeModel(ClassName::fromFqcn('App\\Attr\\Comment'), [AttributeArgument::positional(ArgumentValue::newInstance(ClassName::fromFqcn('App\\Attr\\Inner'), AttributeArgument::positional(ArgumentValue::literal('a */ b'))))]), new AttributeModel(ClassName::fromFqcn('App\\Attr\\Plain'), [
            AttributeArgument::positional(ArgumentValue::literal('C:\\new * / x')),
            AttributeArgument::named('map', ArgumentValue::mapOf([3 => ArgumentValue::literal('x')])),
            AttributeArgument::named('mode', ArgumentValue::constant('PHP_EOL')),
            AttributeArgument::named('type', ArgumentValue::classReference(ClassName::fromFqcn('App\\Dto\\Pet'))),
        ])), '7.4', true, MetadataMode::ANNOTATIONS);

        self::assertSame(
            array_map(
                static fn (string $class): string => 'warning ' . self::AT . 'Pet/properties/name: Attribute App\\Attr\\' . $class . ' has a string with a line break or "*/"; an annotation writes them as the characters "\\n" and "*\\/".',
                ['Note', 'Key', 'Comment'],
            ),
            $this->messages($output),
        );
    }

    public function testChecksAnnotationArgumentsOnlyWhenAnnotationsRender(): void
    {
        $output = $this->enrich(['Pet' => self::SCHEMAS['Pet']], $this->propertyAttributes(new AttributeModel(ClassName::fromFqcn('App\\Attr\\Pair'), [
            AttributeArgument::positional(ArgumentValue::literal("a\nb")),
            AttributeArgument::positional(ArgumentValue::literal('c')),
        ])), '8.2', true, MetadataMode::ATTRIBUTES);

        self::assertSame([], $this->messages($output));
    }

    public function testVerifiesClassAttributesBeforePropertyOnesAndReportsEachMissingNameOnce(): void
    {
        $output = $this->enrich(self::SCHEMAS, static function (ExtensionRegistry $registry): void {
            (new MarkingExtension())->register($registry, []);
        }, '8.2', true, MetadataMode::ATTRIBUTES, new FixedClassVerifier([]));

        self::assertSame(['error ' . self::AT . 'Pet: Attribute class App\\Attr\\Marked does not exist.'], $this->messages($output));
    }

    public function testReportsAMissingNameOnceWhicheverAttributeUsesIt(): void
    {
        $at = self::AT;
        $missing = ArgumentValue::classReference(ClassName::fromFqcn('App\\Gone'));
        $output = $this->enrich(['Pet' => self::SCHEMAS['Pet']], $this->propertyAttributes(
            new AttributeModel(ClassName::fromFqcn('App\\Attr\\Rule'), [AttributeArgument::positional($missing)]),
            new AttributeModel(ClassName::fromFqcn('App\\Attr\\Make'), [AttributeArgument::positional($missing)]),
        ), '8.2', true, MetadataMode::ATTRIBUTES, new FixedClassVerifier(['App\\Attr\\Rule', 'App\\Attr\\Make']));

        self::assertSame(["error {$at}Pet/properties/name: Class App\\Gone, used by attribute App\\Attr\\Rule, does not exist."], $this->messages($output));
    }

    public function testSharesOneReportBetweenAMissingAttributeClassAndReferencesToIt(): void
    {
        $at = self::AT;
        $output = $this->enrich(['Pet' => self::SCHEMAS['Pet']], $this->propertyAttributes(
            new AttributeModel(ClassName::fromFqcn('App\\Attr\\Rule'), [AttributeArgument::positional(ArgumentValue::classReference(ClassName::fromFqcn('App\\Gone')))]),
            new AttributeModel(ClassName::fromFqcn('App\\Gone')),
            new AttributeModel(ClassName::fromFqcn('App\\Other')),
        ), '8.2', true, MetadataMode::ATTRIBUTES, new FixedClassVerifier(['App\\Attr\\Rule']));

        self::assertSame(
            [
                "error {$at}Pet/properties/name: Class App\\Gone, used by attribute App\\Attr\\Rule, does not exist.",
                "error {$at}Pet/properties/name: Attribute class App\\Other does not exist.",
            ],
            $this->messages($output),
        );
    }

    public function testReportsAMissingNameOnceWhateverCaseItIsWrittenIn(): void
    {
        $at = self::AT;
        $output = $this->enrich(['Pet' => self::SCHEMAS['Pet']], $this->propertyAttributes(
            new AttributeModel(ClassName::fromFqcn('App\\Attr\\Rule'), [
                AttributeArgument::positional(ArgumentValue::classReference(ClassName::fromFqcn('App\\Gone'))),
                AttributeArgument::positional(ArgumentValue::constant('LEVEL', ClassName::fromFqcn('App\\Gone'))),
                AttributeArgument::positional(ArgumentValue::constant('LEVEL', ClassName::fromFqcn('app\\GONE'))),
                AttributeArgument::positional(ArgumentValue::constant('level', ClassName::fromFqcn('App\\Gone'))),
            ]),
            new AttributeModel(ClassName::fromFqcn('app\\gone')),
        ), '8.2', true, MetadataMode::ATTRIBUTES, new FixedClassVerifier(['App\\Attr\\Rule']));

        self::assertSame(
            [
                "error {$at}Pet/properties/name: Class App\\Gone, used by attribute App\\Attr\\Rule, does not exist.",
                "error {$at}Pet/properties/name: Constant App\\Gone::LEVEL, used by attribute App\\Attr\\Rule, does not exist.",
                "error {$at}Pet/properties/name: Constant App\\Gone::level, used by attribute App\\Attr\\Rule, does not exist.",
            ],
            $this->messages($output),
        );
    }

    public function testKeepsMissingNamesOfDifferentKindsApart(): void
    {
        $at = self::AT;
        $output = $this->enrich(['Pet' => self::SCHEMAS['Pet']], $this->propertyAttributes(new AttributeModel(ClassName::fromFqcn('App\\Attr\\Rule'), [
            AttributeArgument::positional(ArgumentValue::listOf(
                ArgumentValue::constant('level', ClassName::fromFqcn('App\\Gone')),
                ArgumentValue::classReference(ClassName::fromFqcn('App\\Gonelevel')),
                ArgumentValue::constant('level', ClassName::fromFqcn('App\\Lost')),
                ArgumentValue::constant('gone'),
                ArgumentValue::classReference(ClassName::fromFqcn('Gone')),
                ArgumentValue::constant('OTHER'),
                ArgumentValue::newInstance(ClassName::fromFqcn('App\\Fresh')),
            )),
        ])), '8.2', true, MetadataMode::ATTRIBUTES, new FixedClassVerifier(['App\\Attr\\Rule']));

        self::assertSame(
            array_map(
                static fn (string $missing): string => "error {$at}Pet/properties/name: {$missing}, used by attribute App\\Attr\\Rule, does not exist.",
                ['Constant App\\Gone::level', 'Class App\\Gonelevel', 'Constant App\\Lost::level', 'Constant gone', 'Class Gone', 'Constant OTHER', 'Class App\\Fresh'],
            ),
            $this->messages($output),
        );
    }

    public function testTreatsTheClassesAndEnumsOfThisRunAsExisting(): void
    {
        $at = self::AT;
        $verifier = new FixedClassVerifier(['App\\Attr\\Rule']);
        $schemas = ['Pet' => self::SCHEMAS['Pet'], 'Status' => ['type' => 'string', 'enum' => ['active', 'gone']]];
        $output = $this->enrich($schemas, static function (ExtensionRegistry $registry): void {
            $registry->addPropertyEnricher(new class implements PropertyEnricher {
                public function enrichProperty(PropertyContext $context): array
                {
                    return [new AttributeModel(ClassName::fromFqcn('App\\Attr\\Rule'), [
                        AttributeArgument::named('type', ArgumentValue::classReference(ClassName::fromFqcn('app\\dto\\PET'))),
                        AttributeArgument::named('status', ArgumentValue::constant('ACTIVE', ClassName::fromFqcn('App\\Dto\\Status'))),
                        AttributeArgument::named('other', ArgumentValue::constant('NOPE', ClassName::fromFqcn('App\\Dto\\Status'))),
                        AttributeArgument::named('copy', ArgumentValue::newInstance(ClassName::fromFqcn('App\\Dto\\Pet'))),
                    ])];
                }
            });
        }, '8.2', true, MetadataMode::ATTRIBUTES, $verifier);

        self::assertSame(["error {$at}Pet/properties/name: Constant App\\Dto\\Status::NOPE, used by attribute App\\Attr\\Rule, does not exist."], $this->messages($output));
        self::assertSame(['class App\\Attr\\Rule'], $verifier->asked);
    }

    public function testAcceptsInterfacesAsClassReferencesButNotAsAttributes(): void
    {
        $at = self::AT;
        $output = $this->enrich(['Pet' => self::SCHEMAS['Pet']], static function (ExtensionRegistry $registry): void {
            $registry->addClassEnricher(new class implements ClassEnricher {
                public function enrichClass(ClassContext $context): array
                {
                    return [
                        new AttributeModel(ClassName::fromFqcn('App\\Attr\\Rule'), [AttributeArgument::positional(ArgumentValue::classReference(ClassName::fromFqcn('App\\Contract')))]),
                        new AttributeModel(ClassName::fromFqcn('App\\Attr\\Make'), [AttributeArgument::positional(ArgumentValue::newInstance(ClassName::fromFqcn('App\\Other')))]),
                        new AttributeModel(ClassName::fromFqcn('App\\Contract')),
                    ];
                }
            });
        }, '8.2', true, MetadataMode::ATTRIBUTES, new FixedClassVerifier(['App\\Attr\\Rule', 'App\\Attr\\Make'], [], ['App\\Contract', 'App\\Other']));

        self::assertSame(
            [
                "error {$at}Pet: Class App\\Other, used by attribute App\\Attr\\Make, does not exist.",
                "error {$at}Pet: Attribute class App\\Contract does not exist.",
            ],
            $this->messages($output),
        );
    }

    public function testReportsAFailingAutoloaderOnceAndStopsVerifying(): void
    {
        $verifier = new FixedClassVerifier([], [], [], 'Loading /project/vendor/autoload.php failed: boom');
        $output = $this->enrich(self::SCHEMAS, static function (ExtensionRegistry $registry): void {
            (new MarkingExtension())->register($registry, []);
        }, '8.2', true, MetadataMode::ATTRIBUTES, $verifier);

        self::assertSame(['error ' . self::AT . 'Pet: Loading /project/vendor/autoload.php failed: boom'], $this->messages($output));
        self::assertCount(1, $verifier->asked);
    }
}
