<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Application\Service\Model;

use MSSTC4PHP\DtoGenerator\Application\Config\OperationFilter;
use MSSTC4PHP\DtoGenerator\Tests\Support\ModelFixture;
use PHPUnit\Framework\TestCase;

final class Swagger20Test extends TestCase
{
    private const AT = '/project/api/openapi.yaml#/';

    public function testReadsTheDefinitionsAsOpenApi30Schemas(): void
    {
        $output = ModelFixture::document(['swagger' => '2.0', 'definitions' => [
            'Pet' => ['type' => 'object', 'discriminator' => 'petType', 'required' => ['petType'], 'properties' => [
                'petType' => ['type' => 'string'],
                'name' => ['type' => 'string', 'x-nullable' => true],
                'photo' => ['type' => 'file'],
                'avatar' => ['type' => 'file', 'format' => 'byte'],
                'tag' => ['type' => 'string', 'x-nullable' => false],
                'size' => ['type' => 'integer', 'minimum' => 1, 'exclusiveMinimum' => true],
            ]],
            'Cat' => ['allOf' => [['$ref' => '#/definitions/Pet'], ['type' => 'object', 'properties' => ['lives' => ['type' => 'integer']]]]],
        ]]);

        self::assertSame([], ModelFixture::messages($output));
        self::assertSame('abstract by petType {Cat: App\Dto\Cat}', ModelFixture::hierarchy($output)['App\Dto\Pet']);
        self::assertSame(
            ['petType: string', 'name: string|null', 'photo: string|null', 'avatar: string|null', 'tag: string|null', 'size: int<2, max>|null'],
            ModelFixture::classes($output)['App\Dto\Pet'],
        );
    }

    public function testReadsTheDocumentsItRefersToAsSwagger(): void
    {
        $output = ModelFixture::document(
            ['swagger' => '2.0', 'definitions' => ['Pet' => ['type' => 'object', 'properties' => ['owner' => ['$ref' => 'common.yaml#/Owner']]]]],
            null,
            ['/project/api/common.yaml' => ['Owner' => ['type' => 'object', 'discriminator' => 'kind', 'properties' => ['kind' => ['type' => 'string'], 'name' => ['type' => 'string', 'x-nullable' => true]]]]],
        );

        self::assertSame(['kind: string|null', 'name: string|null'], ModelFixture::classes($output)['App\Dto\Owner']);
        self::assertSame(
            ['warning /project/api/common.yaml#/Owner/discriminator: The discriminator is ignored: no oneOf or anyOf lists variants and no named schema extends this one through allOf.'],
            ModelFixture::messages($output),
        );
    }

    public function testKeepsAnOpenApiDocumentThatAlsoStatesSwagger(): void
    {
        $output = ModelFixture::document(['openapi' => '3.1.0', 'swagger' => '2.0', 'definitions' => ['Pet' => ['type' => 'object', 'properties' => ['a' => ['type' => 'string']]]], 'components' => ['schemas' => ['Cat' => ['type' => 'object', 'properties' => ['a' => ['type' => 'string']]]]]]);

        self::assertSame(['App\Dto\Cat'], array_keys(ModelFixture::classes($output)));
    }

    public function testWarnsAboutAnotherSwaggerVersion(): void
    {
        $output = ModelFixture::document(['swagger' => '1.2', 'definitions' => ['Pet' => ['type' => 'object', 'properties' => ['a' => ['type' => 'string']]]]]);

        self::assertSame(['warning ' . self::AT . 'swagger: Expected Swagger 2.0, found "1.2"; the document is read as Swagger 2.0.'], ModelFixture::messages($output));
        self::assertSame(['App\Dto\Pet'], array_keys(ModelFixture::classes($output)));
    }

    public function testWarnsAboutAVersionThatIsNoString(): void
    {
        $output = ModelFixture::document(['swagger' => true, 'definitions' => []]);

        self::assertSame(['warning ' . self::AT . 'swagger: Expected Swagger 2.0, found "not a string"; the document is read as Swagger 2.0.'], ModelFixture::messages($output));
    }

    public function testWarnsAboutADocumentWithoutDefinitions(): void
    {
        $output = ModelFixture::document(['swagger' => '2.0', 'paths' => []]);

        self::assertSame(['warning ' . self::AT . 'definitions: The specification has no definitions; nothing to generate.'], ModelFixture::messages($output));
    }

    public function testRejectsDefinitionsThatAreNoObject(): void
    {
        $output = ModelFixture::document(['swagger' => '2.0', 'definitions' => [['type' => 'object']]]);

        self::assertSame(['error ' . self::AT . 'definitions: "definitions" must be an object.'], ModelFixture::messages($output));
    }

    public function testReadsNoOperationsOfASwaggerDocument(): void
    {
        $output = ModelFixture::document(
            ['swagger' => '2.0', 'paths' => ['/pets' => ['get' => ['operationId' => 'list', 'responses' => ['200' => ['schema' => ['type' => 'object']]]]]], 'definitions' => ['Pet' => ['type' => 'object', 'properties' => ['a' => ['type' => 'string']]]]],
            OperationFilter::all(),
        );

        self::assertSame(['warning /project/dto-generator.yaml#/sources/0/spec: The operations of a Swagger 2.0 document are not read; its definitions give the classes.'], ModelFixture::messages($output));
        self::assertSame(['App\Dto\Pet'], array_keys(ModelFixture::classes($output)));
    }
}
