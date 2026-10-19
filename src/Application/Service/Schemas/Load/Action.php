<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Service\Schemas\Load;

use MSSTC4PHP\DtoGenerator\Application\Config\RemoteRefsSettings;
use MSSTC4PHP\DtoGenerator\Application\Port\Document;
use MSSTC4PHP\DtoGenerator\Application\Port\DocumentLoader;
use MSSTC4PHP\DtoGenerator\Application\Port\DocumentLoadFailed;
use MSSTC4PHP\DtoGenerator\Application\Port\RemoteDocuments;
use MSSTC4PHP\DtoGenerator\Application\Port\RemoteRequest;
use MSSTC4PHP\DtoGenerator\Domain\Builder\SchemaParser;
use MSSTC4PHP\DtoGenerator\Domain\Builder\UnionDistribution;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;
use MSSTC4PHP\DtoGenerator\Domain\Schema\JsonPointer;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Reference;
use MSSTC4PHP\DtoGenerator\Domain\Schema\ReferenceUse;
use MSSTC4PHP\DtoGenerator\Domain\Schema\ResolvedSchema;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Json;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Url;

/**
 * Loads every selected component schema of every source, then follows `$ref`s until the graph is closed.
 *
 * @phpstan-import-type JsonValue from Json
 */
final class Action
{
    private DocumentLoader $loader;

    private SchemaParser $parser;

    private ?RemoteDocuments $remote;

    public function __construct(DocumentLoader $loader, SchemaParser $parser, ?RemoteDocuments $remote = null)
    {
        $this->loader = $loader;
        $this->parser = $parser;
        $this->remote = $remote;
    }

    public function __invoke(Input $input): Output
    {
        $config = $input->config();
        $diagnostics = new Diagnostics();
        $graph = new GraphBuilder();
        /** @var array<string, int> $owners spec path → source index */
        $owners = [];
        $queue = [];
        $reads = new Reads();
        $parser = $this->parser->claiming($input->keywordChecks());

        foreach ($config->sources() as $index => $source) {
            $specAt = $config->location()->child('sources', (string) $index, 'spec');
            $spec = $source->spec();
            if (isset($owners[$spec])) {
                $diagnostics->error(sprintf('The specification is already used by source #%d.', $owners[$spec]), $specAt);

                continue;
            }

            $document = $this->load($spec, $specAt, $input, $diagnostics, $graph, $reads);
            if (!$document instanceof Document) {
                continue;
            }

            $owners[$document->path()] = $index;
            $this->checkOpenApiVersion($document, $diagnostics);
            foreach ($this->componentSchemas($document, $diagnostics) as [$name, $node]) {
                if (!$source->selects($name)) {
                    continue;
                }

                $location = (new SchemaLocation($document->path()))->child('components', 'schemas', $name);
                $schema = new ResolvedSchema($parser->parse($node, $location, $diagnostics), $index, $name, true);
                $graph->add($schema);
                $queue[] = $schema;
            }
        }

        while ($queue !== []) {
            $current = array_shift($queue);
            foreach ($current->schema()->references() as $use) {
                $target = $this->target($use, $config->remoteRefs(), $diagnostics);
                if (!$target instanceof SchemaLocation) {
                    continue;
                }

                $graph->addReference($use, $current->location(), $target);
                if ($graph->knows($target)) {
                    continue;
                }

                $resolved = $this->resolve($target, $use, $owners, $input, $diagnostics, $graph, $reads, $parser);
                if (!$resolved instanceof ResolvedSchema) {
                    $graph->markFailed($target);

                    continue;
                }

                $graph->add($resolved);
                $queue[] = $resolved;
            }
        }

        $this->followKeywordReferences($graph, $input, $diagnostics, $reads);

        // Every later stage sees an allOf of a union of objects as the union of the objects it makes.
        return new Output(UnionDistribution::of($graph->build($diagnostics), $diagnostics), $diagnostics, $reads->documents(), $reads->readsRemote() ? $config->remoteRefs()->cacheDir() : null);
    }

    /**
     * The `$ref`s in the schemas of keywords (`not`, `propertyNames`…), last: what only they reach gets no source, so
     * no class of its own. Only an extension reads them, so a target that cannot be loaded or read is a warning and
     * stays out of the graph, and what the parser says about one that can is left to the extension.
     */
    private function followKeywordReferences(GraphBuilder $graph, Input $input, Diagnostics $diagnostics, Reads $reads): void
    {
        $failures = new Diagnostics();
        /** @var list<array{ReferenceUse, ?SchemaLocation}> $queue each with the schema it names a subschema of, if structural */
        $queue = [];
        foreach ($graph->schemas() as $schema) {
            foreach ($schema->schema()->keywordReferences() as $use) {
                $queue[] = [$use, null];
            }
        }

        while ($queue !== []) {
            [$use, $from] = array_shift($queue);
            $target = $this->target($use, $input->config()->remoteRefs(), $failures);
            if (!$target instanceof SchemaLocation) {
                continue;
            }

            $graph->addReference($use, $from, $target);
            if ($graph->knows($target)) {
                continue;
            }

            $own = new Diagnostics();
            $resolved = $this->resolve($target, $use, [], $input, $own, $graph, $reads, $this->parser);
            foreach ($own->errors() as $error) {
                $failures->add($error);
            }

            if (!$resolved instanceof ResolvedSchema || $own->hasErrors()) {
                $graph->markFailed($target);

                continue;
            }

            $graph->add($resolved);
            foreach ($resolved->schema()->references() as $next) {
                $queue[] = [$next, $resolved->location()];
            }

            foreach ($resolved->schema()->keywordReferences() as $next) {
                $queue[] = [$next, null];
            }
        }

        foreach ($failures->all() as $diagnostic) {
            $diagnostics->warning($diagnostic->message(), $diagnostic->location());
        }
    }

    /**
     * A file that failed once is reported once, however many sources or references point into it.
     */
    private function load(string $path, SchemaLocation $requestedAt, Input $input, Diagnostics $diagnostics, GraphBuilder $graph, Reads $reads): ?Document
    {
        if ($graph->isUnloadable($path)) {
            return null;
        }

        try {
            if (!Url::isUrl($path)) {
                $document = $this->loader->load($path);
                $reads->document($document->path());

                return $document;
            }

            if (!$this->remote instanceof RemoteDocuments) {
                throw DocumentLoadFailed::remote($path, 'cannot be loaded: this generator was built without remote documents.');
            }

            $document = $reads->remoteDocument($path);
            if (!$document instanceof Document) {
                $settings = $input->config()->remoteRefs();
                $document = $this->remote->load($path, new RemoteRequest($settings->cacheDir(), $settings->timeout(), $input->remotePolicy(), $settings->headersFor($path)));
                $reads->remote($document);
            }

            return $document;
        } catch (DocumentLoadFailed $exception) {
            $diagnostics->error($exception->getMessage(), $requestedAt);
            $graph->markUnloadable($path);

            return null;
        }
    }

    private function checkOpenApiVersion(Document $document, Diagnostics $diagnostics): void
    {
        $version = $document->root()['openapi'] ?? null;
        $at = (new SchemaLocation($document->path()))->child('openapi');
        // Unquoted `openapi: 3.1` arrives from YAML as a float.
        if (is_float($version)) {
            $version = Json::floatToString($version);
        }

        if (!is_string($version)) {
            $diagnostics->warning('No "openapi" version; the document is read as OpenAPI 3.1.', $at);

            return;
        }

        if (preg_match('/^3\.1(?:\.\d+)?\z/', $version) !== 1) {
            $diagnostics->warning(
                sprintf('Expected OpenAPI 3.1, found "%s"; keywords specific to that version (such as "nullable") are not understood.', $version),
                $at,
            );
        }
    }

    /**
     * @return list<array{string, JsonValue}> name and node, in document order; numeric names stay strings
     */
    private function componentSchemas(Document $document, Diagnostics $diagnostics): array
    {
        $at = (new SchemaLocation($document->path()))->child('components', 'schemas');
        $components = $document->root()['components'] ?? null;
        if ($components !== null && (!is_array($components) || ($components !== [] && Json::isList($components)))) {
            $diagnostics->error('"components" must be an object.', (new SchemaLocation($document->path()))->child('components'));

            return [];
        }

        $schemas = is_array($components) ? ($components['schemas'] ?? null) : null;
        if ($schemas === null) {
            $diagnostics->warning('The specification has no components/schemas; nothing to generate.', $at);

            return [];
        }

        if (!is_array($schemas) || ($schemas !== [] && Json::isList($schemas))) {
            $diagnostics->error('"schemas" must be an object.', $at);

            return [];
        }

        $pairs = [];
        foreach ($schemas as $name => $node) {
            $name = (string) $name;
            if ($name === '') {
                $diagnostics->error('A component schema name must not be empty.', $at->child(''));

                continue;
            }

            $pairs[] = [$name, Json::value($node)];
        }

        return $pairs;
    }

    private function target(ReferenceUse $use, RemoteRefsSettings $remote, Diagnostics $diagnostics): ?SchemaLocation
    {
        try {
            $target = Reference::target($use->ref(), $use->location());
        } catch (InvalidModel $exception) {
            $diagnostics->error($exception->getMessage(), $use->location());

            return null;
        }

        if (Url::isUrl($target->file()) && !$remote->allows($target->file())) {
            $diagnostics->error(
                sprintf(
                    'Remote $ref "%s" names %s, which remoteRefs.allow does not cover; add its prefix there, or save the document next to the specification and refer to it by path.',
                    $use->ref(),
                    $target->file(),
                ),
                $use->location(),
            );

            return null;
        }

        return $target;
    }

    /**
     * @param array<string, int> $owners
     */
    private function resolve(SchemaLocation $target, ReferenceUse $use, array $owners, Input $input, Diagnostics $diagnostics, GraphBuilder $graph, Reads $reads, SchemaParser $parser): ?ResolvedSchema
    {
        $document = $this->load($target->file(), $use->location(), $input, $diagnostics, $graph, $reads);
        if (!$document instanceof Document) {
            return null;
        }

        if (!JsonPointer::has($document->root(), $target->pointer())) {
            $diagnostics->error(
                sprintf('$ref "%s" does not resolve: %s has nothing at "%s".', $use->ref(), $document->path(), $target->pointer()),
                $use->location(),
            );

            return null;
        }

        $node = JsonPointer::get($document->root(), $target->pointer());

        return new ResolvedSchema($parser->parse($node, $target, $diagnostics), $owners[$document->path()] ?? null, $this->nameOf($target), false);
    }

    private function nameOf(SchemaLocation $location): string
    {
        $segments = JsonPointer::segments($location->pointer());
        $name = $segments === [] ? '' : $segments[count($segments) - 1];
        if ($name === '') {
            // A URL may carry a query, which is no part of the document's name.
            $name = pathinfo(Url::isUrl($location->file()) ? explode('?', $location->file())[0] : $location->file(), PATHINFO_FILENAME);
        }

        return $name === '' ? 'Schema' : $name;
    }
}
