<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Application\Service\Model;

use Closure;
use MSSTC4PHP\DtoGenerator\Application\Extension\Registry;
use MSSTC4PHP\DtoGenerator\Application\Service\Model\Enrich\Action;
use MSSTC4PHP\DtoGenerator\Application\Service\Model\Enrich\Input;
use MSSTC4PHP\DtoGenerator\Application\Service\Model\Enrich\Output;
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
    private function enrich(array $schemas, Closure $register, string $php = '8.2', bool $strict = true, string $metadata = MetadataMode::ATTRIBUTES): Output
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

        return (new Action())(new Input($built->classes(), GraphFixture::load($schemas), $target, $registry, new InstalledPackages()));
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
}
