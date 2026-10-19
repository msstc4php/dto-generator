<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Service\Schemas\Load;

use MSSTC4PHP\DtoGenerator\Application\Config\NameOverride;
use MSSTC4PHP\DtoGenerator\Application\Config\SourceConfig;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Schema\JsonPointer;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Schema;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Json;

/**
 * Writes the names the configuration gives into the schemas as they are read, as if the document set them, so the
 * parser checks them like any other.
 *
 * @phpstan-import-type JsonValue from Json
 */
final class NameOverrides
{
    /** @var list<array{int, NameOverride}> each with the index of its source */
    private array $overrides = [];

    /** @var array<int, bool> by index into the overrides */
    private array $applied = [];

    /**
     * Two different names for one schema are an error at the later one, which is left out.
     *
     * @param list<SourceConfig> $sources
     */
    public function __construct(array $sources, Diagnostics $diagnostics)
    {
        /** @var array<string, array<string, NameOverride>> $first by keyword, then target */
        $first = [];
        foreach ($sources as $index => $source) {
            foreach ($source->nameOverrides() as $override) {
                $target = $override->target()->toString();
                $earlier = $first[$override->keyword()][$target] ?? null;
                if ($earlier instanceof NameOverride) {
                    if ($earlier->name() !== $override->name()) {
                        $diagnostics->error(
                            sprintf('%s is named "%s" already, at %s.', $override->target()->toString(), $earlier->name(), $earlier->declaredAt()->toString()),
                            $override->declaredAt(),
                        );
                    }

                    continue;
                }

                $first[$override->keyword()][$target] = $override;
                $this->overrides[] = [$index, $override];
            }
        }
    }

    /**
     * @param JsonValue $node
     *
     * @return JsonValue
     */
    public function apply($node, SchemaLocation $at)
    {
        $prefix = $at->pointer() . '/';
        foreach ($this->overrides as $index => [, $override]) {
            $target = $override->target();
            if ($target->file() !== $at->file() || ($target->pointer() !== $at->pointer() && strpos($target->pointer(), $prefix) !== 0)) {
                continue;
            }

            $segments = array_slice(JsonPointer::segments($target->pointer()), count(JsonPointer::segments($at->pointer())));
            $relative = JsonPointer::fromSegments(...$segments);
            if (!is_array($node) || !Schema::leadsToASubschema($segments) || !JsonPointer::has($node, $relative)) {
                continue;
            }

            $schema = JsonPointer::get($node, $relative);
            if (is_array($schema) && ($schema === [] || !Json::isList($schema))) {
                $node = $this->with($node, $segments, $override);
                $this->applied[$index] = true;
            }
        }

        return $node;
    }

    /**
     * Warns of a name given to no schema that was read, which a typo in the reference or an excluded schema leaves; a
     * source whose specification was not read has its own error.
     *
     * @param list<int> $read the indexes of the sources whose specification was read
     */
    public function reportUnused(array $read, Diagnostics $diagnostics): void
    {
        foreach ($this->overrides as $index => [$source, $override]) {
            if (!($this->applied[$index] ?? false) && in_array($source, $read, true)) {
                $target = $override->target();
                $diagnostics->warning(
                    sprintf(
                        'No schema read is at %s; the name is not used.%s',
                        $target->toString(),
                        $target->pointer() === '' ? ' A key names a schema by a $ref such as "#/components/schemas/Pet".' : '',
                    ),
                    $override->declaredAt(),
                );
            }
        }
    }

    /**
     * @param array<array-key, mixed> $node as JsonValue holds it
     * @param list<string> $segments
     *
     * @return array<array-key, mixed>
     */
    private function with(array $node, array $segments, NameOverride $override): array
    {
        $segment = array_shift($segments);
        if ($segment === null) {
            $node[$override->keyword()] = $override->name();

            return $node;
        }

        $child = $node[$segment];
        if (is_array($child)) {
            $node[$segment] = $this->with($child, $segments, $override);
        }

        return $node;
    }
}
