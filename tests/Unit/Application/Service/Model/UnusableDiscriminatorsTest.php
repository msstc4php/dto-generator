<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Application\Service\Model;

use MSSTC4PHP\DtoGenerator\Application\Service\Model\Build\Output;
use MSSTC4PHP\DtoGenerator\Tests\Support\ModelFixture;
use PHPUnit\Framework\TestCase;

final class UnusableDiscriminatorsTest extends TestCase
{
    private const AT = '/project/api/openapi.yaml#/components/schemas/';

    private const PROPERTIES = ['name' => ['type' => 'string'], 'status' => ['type' => 'string'], 'conclusion' => ['type' => 'string']];

    /**
     * @dataProvider restrictingVariants
     *
     * @param list<array<string, mixed>> $variants
     */
    public function testReadsAUnionThatOnlyRestrictsTheSchemaAsItsProperties(array $variants, string $several, string $union = 'oneOf'): void
    {
        $output = $this->generate($variants, [], $union);

        self::assertSame(
            [
                'warning ' . self::AT . 'Run/discriminator: The discriminator is ignored, as its inline variants only restrict the properties of the schema, and ' . $several . ' allows several values of "status"; the class keeps the properties.',
                'warning ' . self::AT . 'Run: "oneOf" and "anyOf" beside "properties" are not represented; the class keeps only its properties.',
            ],
            ModelFixture::messages($output),
        );
        self::assertSame('final', ModelFixture::hierarchy($output)['App\Dto\Run']);
        self::assertSame(['name: string|null', 'status: string|null', 'conclusion: string|null'], ModelFixture::classes($output)['App\Dto\Run']);
    }

    /**
     * @return iterable<string, array{0: list<array<string, mixed>>, 1: string, 2?: 'anyOf'}>
     */
    public static function restrictingVariants(): iterable
    {
        $completed = ['properties' => ['status' => ['enum' => ['completed']]], 'required' => ['status', 'conclusion']];

        yield 'an enum of several values' => [[$completed, ['properties' => ['status' => ['enum' => ['queued', 'in_progress']]]]], 'oneOf/1'];
        yield 'no enum' => [[['properties' => ['status' => ['type' => 'string']]], $completed], 'oneOf/0'];
        yield 'no such property' => [[$completed, ['required' => ['name']]], 'oneOf/1'];
        yield 'anyOf' => [[$completed, ['properties' => ['status' => ['enum' => ['queued', 'in_progress']]]]], 'anyOf/1', 'anyOf'];
        yield 'several values beside null' => [[$completed, ['type' => 'object', 'properties' => ['status' => ['enum' => ['a', null, 'b']]]]], 'oneOf/1'];
        yield 'annotations' => [[$completed + ['title' => 'Done', 'minProperties' => 1, 'x-note' => 'n'], ['properties' => ['status' => ['enum' => ['a', 'b']]], 'additionalProperties' => true]], 'oneOf/1'];
    }

    public function testLooksAtTheVariantsOfBothUnions(): void
    {
        $output = ModelFixture::operations(['components' => ['schemas' => ['Run' => [
            'type' => 'object',
            'properties' => self::PROPERTIES,
            'oneOf' => [['properties' => ['status' => ['const' => 'a']]]],
            'anyOf' => [['properties' => ['status' => ['enum' => ['b', 'c']]]], ['properties' => ['status' => ['const' => 'd']], 'not' => []]],
            'discriminator' => ['propertyName' => 'status'],
        ]]]]);

        self::assertSame([], array_filter(ModelFixture::messages($output), static fn (string $message): bool => strpos($message, 'discriminator is ignored, as') !== false));
    }

    public function testReadsTheReadAndTheWriteModelAlike(): void
    {
        $output = ModelFixture::views(['Run' => [
            'type' => 'object',
            'properties' => ['id' => ['type' => 'integer', 'readOnly' => true]] + self::PROPERTIES,
            'oneOf' => [['properties' => ['status' => ['const' => 'a']]], ['properties' => ['status' => ['enum' => ['b', 'c']]]]],
            'discriminator' => ['propertyName' => 'status'],
        ]]);

        self::assertSame(['App\Dto\RunRead' => 'final', 'App\Dto\RunWrite' => 'final'], ModelFixture::hierarchy($output));
    }

    /**
     * @dataProvider namingVariants
     *
     * @param list<array<string, mixed>> $variants
     * @param array<string, mixed> $schema
     */
    public function testKeepsADiscriminatorItsVariantsCanUseOrThatNamesClasses(array $variants, array $schema = []): void
    {
        $output = $this->generate($variants, $schema);

        self::assertSame([], array_filter(ModelFixture::messages($output), static fn (string $message): bool => strpos($message, 'discriminator is ignored, as') !== false));
        $hierarchy = ModelFixture::hierarchy($output)['App\Dto\Run'] ?? '';
        $errors = array_filter(ModelFixture::messages($output), static fn (string $message): bool => strncmp($message, 'error', 5) === 0);
        self::assertTrue(strncmp($hierarchy, 'abstract', 8) === 0 || $errors !== [], $hierarchy);
    }

    /**
     * @return iterable<string, array{0: list<array<string, mixed>>, 1?: array<string, mixed>}>
     */
    public static function namingVariants(): iterable
    {
        $several = ['properties' => ['status' => ['enum' => ['queued', 'in_progress']]]];

        yield 'one value each' => [[['properties' => ['status' => ['const' => 'completed']]], ['properties' => ['status' => ['enum' => [null, 'queued']]]]]];
        yield 'a variant with a property of its own' => [[$several, ['properties' => ['status' => ['const' => 'done'], 'at' => ['type' => 'string']]]]];
        yield 'a variant behind a $ref' => [[$several, ['$ref' => '#/components/schemas/Other']]];
        yield 'a variant with an allOf' => [[$several, ['allOf' => [['properties' => ['status' => ['const' => 'done']]]]]]];
        yield 'a variant that is no object' => [[$several, ['type' => 'string']]];
        yield 'a status behind a $ref' => [[['properties' => ['status' => ['$ref' => '#/components/schemas/Status']]], ['properties' => ['status' => ['const' => 'done']]]]];
        yield 'a status with an allOf' => [[['properties' => ['status' => ['allOf' => [['const' => 'a']]]]], ['properties' => ['status' => ['const' => 'done']]]]];
        yield 'a status that is a union' => [[['properties' => ['status' => ['oneOf' => [['const' => 'a'], ['const' => 'b']]]]], ['properties' => ['status' => ['const' => 'done']]]]];
        yield 'a variant that is a union' => [[$several, ['oneOf' => [['$ref' => '#/components/schemas/Other'], ['type' => 'object']]]]];
        yield 'a variant with a discriminator' => [[$several, ['properties' => ['status' => ['type' => 'string']], 'discriminator' => ['propertyName' => 'status']]]];
        yield 'a variant with not' => [[$several, ['not' => ['required' => ['name']]]]];
        yield 'a variant with a schema for other properties' => [[$several, ['additionalProperties' => ['$ref' => '#/components/schemas/Other']]]];
        yield 'a variant with items' => [[$several, ['items' => ['type' => 'string']]]];
        yield 'a variant with an enum' => [[$several, ['enum' => [['status' => 'a']]]]];
        yield 'a variant that may be an array' => [[$several, ['type' => ['object', 'array']]]];
        yield 'a status that may only be null' => [[['properties' => ['status' => ['enum' => [null]]]], ['properties' => ['status' => ['const' => 'done']]]]];
        yield 'a mapping' => [[$several, ['properties' => ['status' => ['const' => 'done']]]], ['discriminator' => ['propertyName' => 'status', 'mapping' => ['x' => 'Other']]]];
        yield 'a default mapping' => [[$several, ['properties' => ['status' => ['const' => 'done']]]], ['discriminator' => ['propertyName' => 'status', 'defaultMapping' => 'Other']]];
        yield 'a schema without properties' => [[$several, ['properties' => ['status' => ['const' => 'done']]]], ['properties' => []]];
    }

    /**
     * @param list<array<string, mixed>> $variants
     * @param array<string, mixed> $schema
     */
    private function generate(array $variants, array $schema = [], string $union = 'oneOf'): Output
    {
        return ModelFixture::operations(['components' => ['schemas' => [
            'Run' => $schema + ['type' => 'object', 'properties' => self::PROPERTIES, $union => $variants, 'discriminator' => ['propertyName' => 'status']],
            'Other' => ['type' => 'object', 'properties' => ['status' => ['type' => 'string']]],
            'Status' => ['type' => 'string', 'enum' => ['a', 'b']],
        ]]]);
    }
}
