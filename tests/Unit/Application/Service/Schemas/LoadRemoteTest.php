<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Application\Service\Schemas;

use MSSTC4PHP\DtoGenerator\Application\Port\DocumentLoadFailed;
use MSSTC4PHP\DtoGenerator\Application\Service\Schemas\Load\Action;
use MSSTC4PHP\DtoGenerator\Application\Service\Schemas\Load\Input;
use MSSTC4PHP\DtoGenerator\Application\Service\Schemas\Load\Output;
use MSSTC4PHP\DtoGenerator\Domain\Builder\SchemaParser;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostic;
use MSSTC4PHP\DtoGenerator\Domain\Schema\ResolvedSchema;
use MSSTC4PHP\DtoGenerator\Tests\Support\ConfigMother;
use MSSTC4PHP\DtoGenerator\Tests\Support\InMemoryDocumentLoader;
use MSSTC4PHP\DtoGenerator\Tests\Support\InMemoryRemoteDocuments;
use PHPUnit\Framework\TestCase;

/**
 * Remote $refs (spec F2).
 */
final class LoadRemoteTest extends TestCase
{
    private const SPEC = '/project/api/openapi.yaml';

    private const MONEY = 'https://schemas.example.com/common/v1/money.yaml';

    public function testLoadsAnAllowedRemoteDocumentAndTheDocumentsItRefersTo(): void
    {
        $remote = new InMemoryRemoteDocuments([
            self::MONEY => ['Money' => ['type' => 'object', 'properties' => ['currency' => ['$ref' => 'currency.yaml#/Currency']]]],
            'https://schemas.example.com/common/v1/currency.yaml' => ['Currency' => ['type' => 'string']],
        ]);

        $output = $this->load($remote, ['https://Schemas.Example.com/common/'], true, ['price' => ['$ref' => 'HTTPS://schemas.example.com:443/common/v1/money.yaml#/Money']]);

        self::assertSame([], $this->messages($output));
        self::assertSame([
            '/project/api/openapi.yaml#/components/schemas/Order',
            'https://schemas.example.com/common/v1/money.yaml#/Money',
            'https://schemas.example.com/common/v1/currency.yaml#/Currency',
        ], array_map(static fn (ResolvedSchema $schema): string => $schema->location()->toString(), $output->graph()->all()));
        self::assertSame([[self::MONEY, true], ['https://schemas.example.com/common/v1/currency.yaml', true]], $remote->loads());
    }

    public function testReadsOnlyTheCacheWhenTheRunDoesNotWrite(): void
    {
        $remote = new InMemoryRemoteDocuments([self::MONEY => ['Money' => ['type' => 'string']]]);

        $this->load($remote, ['https://schemas.example.com/'], false, ['price' => ['$ref' => self::MONEY . '#/Money']]);

        self::assertSame([[self::MONEY, false]], $remote->loads());
    }

    public function testRefusesARemoteDocumentNoPrefixAllows(): void
    {
        $remote = new InMemoryRemoteDocuments([
            self::MONEY => ['Money' => ['type' => 'object', 'properties' => ['rate' => ['$ref' => '../../rates/rate.yaml#/Rate']]]],
        ]);

        $output = $this->load($remote, [self::MONEY], true, [
            'price' => ['$ref' => self::MONEY . '#/Money'],
            'other' => ['$ref' => 'https://evil.example.org/x.yaml#/X'],
        ]);

        self::assertSame([
            'error /project/api/openapi.yaml#/components/schemas/Order/properties/other: Remote $ref "https://evil.example.org/x.yaml#/X" names https://evil.example.org/x.yaml, which remoteRefs.allow does not cover; add its prefix there, or save the document next to the specification and refer to it by path.',
            'error https://schemas.example.com/common/v1/money.yaml#/Money/properties/rate: Remote $ref "../../rates/rate.yaml#/Rate" names https://schemas.example.com/rates/rate.yaml, which remoteRefs.allow does not cover; add its prefix there, or save the document next to the specification and refer to it by path.',
        ], $this->messages($output));
        self::assertSame([[self::MONEY, true]], $remote->loads());
    }

    public function testReportsARemoteDocumentThatFailsWhereItIsReferredTo(): void
    {
        $remote = new InMemoryRemoteDocuments([self::MONEY => DocumentLoadFailed::remote(self::MONEY, 'could not be fetched: the server answered 404.')]);

        $output = $this->load($remote, ['https://schemas.example.com/'], true, ['price' => ['$ref' => self::MONEY . '#/Money'], 'cost' => ['$ref' => self::MONEY . '#/Money']]);

        self::assertSame([
            'error /project/api/openapi.yaml#/components/schemas/Order/properties/price: Remote document "https://schemas.example.com/common/v1/money.yaml" could not be fetched: the server answered 404.',
        ], $this->messages($output));
    }

    public function testNeedsRemoteDocumentsToLoadOne(): void
    {
        $config = ConfigMother::remote(ConfigMother::source(self::SPEC), ['https://schemas.example.com/']);
        $loader = new InMemoryDocumentLoader([self::SPEC => $this->spec(['price' => ['$ref' => self::MONEY . '#/Money']])]);

        $output = (new Action($loader, new SchemaParser()))(new Input($config, true));

        self::assertSame([
            'error /project/api/openapi.yaml#/components/schemas/Order/properties/price: Remote document "https://schemas.example.com/common/v1/money.yaml" cannot be loaded: this generator was built without remote documents.',
        ], $this->messages($output));
    }

    /**
     * @param list<string> $allow
     * @param array<string, array<array-key, mixed>> $properties
     */
    private function load(InMemoryRemoteDocuments $remote, array $allow, bool $fetch, array $properties): Output
    {
        $config = ConfigMother::remote(ConfigMother::source(self::SPEC), $allow);
        $loader = new InMemoryDocumentLoader([self::SPEC => $this->spec($properties)]);

        return (new Action($loader, new SchemaParser(), $remote))(new Input($config, $fetch));
    }

    /**
     * @param array<string, array<array-key, mixed>> $properties
     *
     * @return array<string, mixed>
     */
    private function spec(array $properties): array
    {
        return ['openapi' => '3.1.0', 'components' => ['schemas' => ['Order' => ['type' => 'object', 'properties' => $properties]]]];
    }

    /**
     * @return list<string>
     */
    private function messages(Output $output): array
    {
        return array_map(static fn (Diagnostic $diagnostic): string => $diagnostic->toString(), $output->diagnostics()->all());
    }
}
