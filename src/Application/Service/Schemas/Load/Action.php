<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Service\Schemas\Load;

use MSSTC4PHP\DtoGenerator\Application\Port\Document;
use MSSTC4PHP\DtoGenerator\Application\Port\DocumentLoader;
use MSSTC4PHP\DtoGenerator\Application\Port\DocumentLoadFailed;
use MSSTC4PHP\DtoGenerator\Domain\Builder\SchemaParser;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;
use MSSTC4PHP\DtoGenerator\Domain\Schema\JsonPointer;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Reference;
use MSSTC4PHP\DtoGenerator\Domain\Schema\ReferenceUse;
use MSSTC4PHP\DtoGenerator\Domain\Schema\ResolvedSchema;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Json;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Path;

/**
 * Loads every selected component schema of every source, then follows `$ref`s until the graph is closed.
 *
 * @phpstan-import-type JsonValue from Json
 */
final class Action
{
    private DocumentLoader $loader;

    private SchemaParser $parser;

    public function __construct(DocumentLoader $loader, SchemaParser $parser)
    {
        $this->loader = $loader;
        $this->parser = $parser;
    }

    public function __invoke(Input $input): Output
    {
        $config = $input->config();
        $diagnostics = new Diagnostics();
        $graph = new GraphBuilder();
        /** @var array<string, int> $owners spec path → source index */
        $owners = [];
        $queue = [];

        foreach ($config->sources() as $index => $source) {
            $specAt = $config->location()->child('sources', (string) $index, 'spec');
            $spec = Path::normalize($source->spec());
            if (isset($owners[$spec])) {
                $diagnostics->error(sprintf('The specification is already used by source #%d.', $owners[$spec]), $specAt);

                continue;
            }

            $document = $this->load($spec, $specAt, $diagnostics, $graph);
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
                $schema = new ResolvedSchema($this->parser->parse($node, $location, $diagnostics), $index, $name, true);
                $graph->add($schema);
                $queue[] = $schema;
            }
        }

        while ($queue !== []) {
            $current = array_shift($queue);
            foreach ($current->schema()->references() as $use) {
                $target = $this->target($use, $diagnostics);
                if (!$target instanceof SchemaLocation) {
                    continue;
                }

                $graph->addReference($use, $current->location(), $target);
                if ($graph->knows($target)) {
                    continue;
                }

                $resolved = $this->resolve($target, $use, $owners, $diagnostics, $graph);
                if (!$resolved instanceof ResolvedSchema) {
                    $graph->markFailed($target);

                    continue;
                }

                $graph->add($resolved);
                $queue[] = $resolved;
            }
        }

        return new Output($graph->build($diagnostics), $diagnostics);
    }

    /**
     * A file that failed once is reported once, however many sources or references point into it.
     */
    private function load(string $path, SchemaLocation $requestedAt, Diagnostics $diagnostics, GraphBuilder $graph): ?Document
    {
        if ($graph->isUnloadable($path)) {
            return null;
        }

        try {
            return $this->loader->load($path);
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

    private function target(ReferenceUse $use, Diagnostics $diagnostics): ?SchemaLocation
    {
        try {
            $target = Reference::target($use->ref(), $use->location());
        } catch (InvalidModel $exception) {
            $diagnostics->error($exception->getMessage(), $use->location());

            return null;
        }

        if (!$target instanceof SchemaLocation) {
            $diagnostics->error(
                sprintf('Remote $ref "%s" is not supported; save the document next to the specification and refer to it by path.', $use->ref()),
                $use->location(),
            );
        }

        return $target;
    }

    /**
     * @param array<string, int> $owners
     */
    private function resolve(SchemaLocation $target, ReferenceUse $use, array $owners, Diagnostics $diagnostics, GraphBuilder $graph): ?ResolvedSchema
    {
        $document = $this->load($target->file(), $use->location(), $diagnostics, $graph);
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

        return new ResolvedSchema($this->parser->parse($node, $target, $diagnostics), $owners[$document->path()] ?? null, $this->nameOf($target), false);
    }

    private function nameOf(SchemaLocation $location): string
    {
        $segments = JsonPointer::segments($location->pointer());
        $name = $segments === [] ? '' : $segments[count($segments) - 1];
        if ($name === '') {
            $name = pathinfo($location->file(), PATHINFO_FILENAME);
        }

        return $name === '' ? 'Schema' : $name;
    }
}
