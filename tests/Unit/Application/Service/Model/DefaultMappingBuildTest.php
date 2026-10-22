<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Application\Service\Model;

use MSSTC4PHP\DtoGenerator\Application\Service\Model\Build\Output;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;
use MSSTC4PHP\DtoGenerator\Domain\Model\DiscriminatorModel;
use MSSTC4PHP\DtoGenerator\Tests\Support\ModelFixture;
use PHPUnit\Framework\TestCase;

final class DefaultMappingBuildTest extends TestCase
{
    private const AT = '/project/api/openapi.yaml#/components/schemas/';

    private const KIND = ['type' => 'object', 'required' => ['kind'], 'properties' => ['kind' => ['type' => 'string']]];

    private const OPTIONAL_KIND = ['type' => 'object', 'properties' => ['kind' => ['type' => 'string'], 'note' => ['type' => 'string']]];

    public function testLeavesTheDiscriminatorOfTheDefaultVariantUnchecked(): void
    {
        $output = ModelFixture::build([
            'Pet' => [
                'oneOf' => [['$ref' => '#/components/schemas/Cat'], ['$ref' => '#/components/schemas/Other']],
                'discriminator' => ['propertyName' => 'kind', 'mapping' => ['cat' => 'Cat'], 'defaultMapping' => 'Other'],
            ],
            'Cat' => self::KIND,
            'Other' => self::OPTIONAL_KIND,
        ]);

        self::assertSame([], ModelFixture::messages($output));
        self::assertSame(['App\Dto\Other', 'Other'], $this->defaultOf($output, 'App\Dto\Pet'));
        self::assertSame(['App\Dto\Cat' => ["kind: 'cat'"]], ModelFixture::selections($output));
    }

    public function testTakesTheDefaultOfAnAllOfHierarchy(): void
    {
        $output = ModelFixture::build([
            'Pet' => self::KIND + ['discriminator' => ['propertyName' => 'kind', 'defaultMapping' => '#/components/schemas/Other']],
            'Cat' => ['allOf' => [['$ref' => '#/components/schemas/Pet'], ['properties' => ['claws' => ['type' => 'boolean']]]]],
            'Other' => ['allOf' => [['$ref' => '#/components/schemas/Pet'], ['properties' => ['note' => ['type' => 'string']]]]],
        ]);

        self::assertSame([], ModelFixture::messages($output));
        self::assertSame(['App\Dto\Other', 'Other'], $this->defaultOf($output, 'App\Dto\Pet'));
        self::assertSame(['App\Dto\Cat' => ["kind: 'Cat'"]], ModelFixture::selections($output));
    }

    public function testLeavesTheVariantsOfAnAbstractDefaultUncheckedByThatDiscriminatorOnly(): void
    {
        $breed = ['type' => 'object', 'required' => ['breed'], 'properties' => ['kind' => ['type' => 'string'], 'breed' => ['type' => 'string']]];
        $output = ModelFixture::build([
            'Pet' => [
                'oneOf' => [['$ref' => '#/components/schemas/Cat'], ['$ref' => '#/components/schemas/Dog']],
                'discriminator' => ['propertyName' => 'kind', 'defaultMapping' => 'Cat'],
            ],
            'Cat' => [
                'oneOf' => [['$ref' => '#/components/schemas/Siamese'], ['$ref' => '#/components/schemas/Persian']],
                'discriminator' => ['propertyName' => 'breed'],
            ],
            'Siamese' => $breed,
            'Persian' => $breed,
            'Dog' => self::KIND,
        ]);

        self::assertSame([], ModelFixture::messages($output));
        self::assertSame(
            ['App\Dto\Siamese' => ["breed: 'Siamese'"], 'App\Dto\Persian' => ["breed: 'Persian'"], 'App\Dto\Dog' => ["kind: 'Dog'"]],
            ModelFixture::selections($output),
        );
    }

    public function testNamesTheDefaultByTheValueTheMappingGivesIt(): void
    {
        $output = ModelFixture::build([
            'Pet' => [
                'oneOf' => [['$ref' => '#/components/schemas/Cat'], ['$ref' => '#/components/schemas/Other']],
                'discriminator' => ['propertyName' => 'kind', 'mapping' => ['cat' => 'Cat', 'memo' => 'Other'], 'defaultMapping' => 'Other'],
            ],
            'Cat' => self::KIND,
            'Other' => self::OPTIONAL_KIND,
        ]);

        self::assertSame([], ModelFixture::messages($output));
        self::assertSame(['App\Dto\Other', 'memo'], $this->defaultOf($output, 'App\Dto\Pet'));
        self::assertSame(['App\Dto\Cat' => ["kind: 'cat'"]], ModelFixture::selections($output));
    }

    public function testKeepsTheCheckAnotherDiscriminatedBaseGivesTheDefault(): void
    {
        $output = ModelFixture::build([
            'Signal' => [
                'oneOf' => [['$ref' => '#/components/schemas/Ping'], ['$ref' => '#/components/schemas/Note']],
                'discriminator' => ['propertyName' => 'kind', 'mapping' => ['ping' => 'Ping'], 'defaultMapping' => 'Note'],
            ],
            'Feed' => [
                'oneOf' => [['$ref' => '#/components/schemas/Note']],
                'discriminator' => ['propertyName' => 'kind', 'mapping' => ['memo' => 'Note']],
            ],
            'Ping' => self::KIND,
            'Note' => self::OPTIONAL_KIND,
        ]);

        self::assertSame([], ModelFixture::messages($output));
        self::assertSame(["kind: 'memo'"], ModelFixture::selections($output)['App\Dto\Note'] ?? null);
    }

    public function testRefusesADefaultThatIsNoVariant(): void
    {
        $output = ModelFixture::build(
            [
                'Pet' => [
                    'oneOf' => [['$ref' => '#/components/schemas/Cat']],
                    'discriminator' => ['propertyName' => 'kind', 'defaultMapping' => 'Dog'],
                ],
                'Cat' => self::KIND,
                'Dog' => self::KIND,
            ],
            [],
            ['Pet'],
        );

        self::assertSame(['error ' . self::AT . 'Pet/discriminator/defaultMapping: The default mapping names a schema that is not one of the variants.'], ModelFixture::messages($output));
        self::assertNull($this->defaultOf($output, 'App\Dto\Pet'));
    }

    /**
     * @return array{string, ?string}|null the default class and its value
     */
    private function defaultOf(Output $output, string $fqcn): ?array
    {
        foreach ($output->classes() as $class) {
            $discriminator = $class->model()->discriminator();
            if ($class->model()->name()->fqcn() === $fqcn && $discriminator instanceof DiscriminatorModel) {
                $default = $discriminator->defaultClass();

                return $default instanceof ClassName ? [$default->fqcn(), $discriminator->defaultValue()] : null;
            }
        }

        return null;
    }
}
