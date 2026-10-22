<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Application\Service\Schemas;

use MSSTC4PHP\DtoGenerator\Application\Config\GeneratorConfig;
use MSSTC4PHP\DtoGenerator\Application\Config\RemoteHeader;
use MSSTC4PHP\DtoGenerator\Application\Config\RemoteRefsSettings;
use MSSTC4PHP\DtoGenerator\Application\Port\DocumentLoadFailed;
use MSSTC4PHP\DtoGenerator\Application\Port\RemotePolicy;
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

    public function testResolvesTheSelfOfARemoteDocumentAgainstItsUrl(): void
    {
        $remote = new InMemoryRemoteDocuments([
            self::MONEY => ['$self' => 'money.yaml', 'Money' => ['$ref' => 'rate.yaml#/Rate']],
            'https://schemas.example.com/common/v1/rate.yaml' => ['$self' => '/common/v2/rate.yaml', 'Rate' => ['type' => 'number']],
        ]);

        $output = $this->load($remote, ['https://schemas.example.com/common/'], true, ['price' => ['$ref' => self::MONEY . '#/Money']]);

        self::assertSame(
            ['warning https://schemas.example.com/common/v1/rate.yaml#/$self: "$self" "/common/v2/rate.yaml" is not read: references in this document resolve against where it was read from, and a "$ref" to that URI does not reach it.'],
            $this->messages($output),
        );
    }

    public function testFollowsAnAnchorInAnAllowedRemoteDocument(): void
    {
        $remote = new InMemoryRemoteDocuments([
            self::MONEY => ['$defs' => ['Money' => ['$anchor' => 'Money', 'type' => 'object', 'properties' => ['currency' => ['$ref' => '#Currency']]], 'Currency' => ['$anchor' => 'Currency', 'type' => 'string']]],
        ]);

        $output = $this->load($remote, ['https://schemas.example.com/'], true, ['price' => ['$ref' => self::MONEY . '#Money']]);

        self::assertSame([], $this->messages($output));
        self::assertSame([
            '/project/api/openapi.yaml#/components/schemas/Order',
            'https://schemas.example.com/common/v1/money.yaml#/$defs/Money',
            'https://schemas.example.com/common/v1/money.yaml#/$defs/Currency',
        ], array_map(static fn (ResolvedSchema $schema): string => $schema->location()->toString(), $output->graph()->all()));
        self::assertSame([[self::MONEY, true]], $remote->loads());
    }

    public function testNamesTheCacheOfTheRemoteDocumentsItRead(): void
    {
        $remote = new InMemoryRemoteDocuments([self::MONEY => ['Money' => ['type' => 'string']]]);

        $output = $this->load($remote, ['https://schemas.example.com/'], true, ['price' => ['$ref' => self::MONEY . '#/Money']]);

        self::assertSame([self::SPEC], $output->documents());
        self::assertSame(ConfigMother::remote(ConfigMother::source(self::SPEC), [])->remoteRefs()->cacheDir(), $output->remoteCache());
    }

    public function testNamesNoCacheWithoutRemoteDocuments(): void
    {
        $output = $this->load(new InMemoryRemoteDocuments([]), ['https://schemas.example.com/'], true, ['id' => ['type' => 'integer']]);

        self::assertSame([self::SPEC], $output->documents());
        self::assertNull($output->remoteCache());
    }

    public function testNamesAWholeRemoteDocumentAfterItsPath(): void
    {
        $url = 'https://schemas.example.com/common/v1/price.yaml?v=1.2';
        $remote = new InMemoryRemoteDocuments([$url => ['type' => 'integer']]);

        $output = $this->load($remote, ['https://schemas.example.com/'], true, ['price' => ['$ref' => $url]]);

        self::assertSame(['Order', 'price'], array_map(static fn (ResolvedSchema $schema): string => $schema->name(), $output->graph()->all()));
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

    public function testAsksForTheDocumentWithTheSettingsAndHeadersOfItsUrl(): void
    {
        $remote = new InMemoryRemoteDocuments([self::MONEY => ['Money' => ['type' => 'string']]]);
        $base = ConfigMother::remote(ConfigMother::source(self::SPEC), []);
        $header = new RemoteHeader('https://schemas.example.com/common/', 'Authorization', 'Bearer ${T}');
        $config = new GeneratorConfig($base->path(), $base->target(), $base->dto(), $base->formats(), $base->extensions(), $base->sources(), new RemoteRefsSettings(
            ['https://schemas.example.com/'],
            '/cache',
            7,
            [$header, new RemoteHeader('https://other.example.com/', 'X', 'y')],
        ));
        $loader = new InMemoryDocumentLoader([self::SPEC => $this->spec(['price' => ['$ref' => self::MONEY . '#/Money']])]);

        (new Action($loader, new SchemaParser(), $remote))(new Input($config, RemotePolicy::from(RemotePolicy::ALL)));

        [[$url, $request]] = $remote->requests();
        self::assertSame(self::MONEY, $url);
        self::assertSame('/cache', $request->cacheDir());
        self::assertSame(7, $request->timeout());
        self::assertTrue($request->fetchesCached());
        self::assertSame([$header], $request->headers());
    }

    public function testLoadsEachRemoteDocumentOncePerRun(): void
    {
        $remote = new InMemoryRemoteDocuments([self::MONEY => ['Money' => ['type' => 'string'], 'Rate' => ['type' => 'number']]]);
        $config = ConfigMother::remote(ConfigMother::source(self::SPEC), ['https://schemas.example.com/']);
        $loader = new InMemoryDocumentLoader([self::SPEC => $this->spec(['price' => ['$ref' => self::MONEY . '#/Money'], 'rate' => ['$ref' => self::MONEY . '#/Rate']])]);
        $action = new Action($loader, new SchemaParser(), $remote);

        $first = $action(new Input($config, RemotePolicy::from(RemotePolicy::ALL)));
        self::assertSame([], $this->messages($first));
        self::assertCount(1, $remote->requests());

        // The next run of the same action loads, and refreshes, again.
        $action(new Input($config, RemotePolicy::from(RemotePolicy::ALL)));
        self::assertCount(2, $remote->requests());
    }

    public function testNeedsRemoteDocumentsToLoadOne(): void
    {
        $config = ConfigMother::remote(ConfigMother::source(self::SPEC), ['https://schemas.example.com/']);
        $loader = new InMemoryDocumentLoader([self::SPEC => $this->spec(['price' => ['$ref' => self::MONEY . '#/Money']])]);

        $output = (new Action($loader, new SchemaParser()))(new Input($config, RemotePolicy::from(RemotePolicy::MISSING)));

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

        return (new Action($loader, new SchemaParser(), $remote))(new Input($config, $fetch ? RemotePolicy::from(RemotePolicy::MISSING) : RemotePolicy::from(RemotePolicy::CACHED)));
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
