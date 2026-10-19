<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Service\Schemas\Load;

use MSSTC4PHP\DtoGenerator\Application\Config\NameOverride;
use MSSTC4PHP\DtoGenerator\Application\Config\SourceConfig;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Schema\JsonPointer;
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
    /** @var list<NameOverride> */
    private array $overrides = [];

    /** @var array<int, true> by index into the overrides */
    private array $applied = [];

    /**
     * @param list<SourceConfig> $sources
     */
    public function __construct(array $sources)
    {
        foreach ($sources as $source) {
            $this->overrides = array_merge($this->overrides, $source->names());
        }
    }

    /**
     * @param JsonValue $node
     *
     * @return JsonValue
     */
    public function apply($node, SchemaLocation $at)
    {
        foreach ($this->overrides as $index => $override) {
            $target = $override->target();
            $prefix = $at->pointer() . '/';
            if ($target->file() !== $at->file() || ($target->pointer() !== $at->pointer() && strpos($target->pointer(), $prefix) !== 0)) {
                continue;
            }

            $segments = JsonPointer::segments((string) substr($target->pointer(), strlen($at->pointer())));
            $found = false;
            $node = $this->set($node, $segments, $override, $found);
            if ($found) {
                $this->applied[$index] = true;
            }
        }

        return $node;
    }

    /**
     * Warns of a name given to no schema that was read, which a typo in the reference or an excluded schema leaves.
     */
    public function reportUnused(Diagnostics $diagnostics): void
    {
        foreach ($this->overrides as $index => $override) {
            if (!($this->applied[$index] ?? false)) {
                $diagnostics->warning(sprintf('No schema read is at %s; the name is not used.', $override->target()->toString()), $override->declaredAt());
            }
        }
    }

    /**
     * @param JsonValue $node
     * @param list<string> $segments
     *
     * @return JsonValue
     */
    private function set($node, array $segments, NameOverride $override, bool &$found)
    {
        if (!is_array($node)) {
            return $node;
        }

        if ($segments === []) {
            if ($node === [] || !Json::isList($node)) {
                $node[$override->keyword()] = $override->name();
                $found = true;
            }
        } else {
            $segment = array_shift($segments);
            if (array_key_exists($segment, $node)) {
                $node[$segment] = $this->set(Json::value($node[$segment]), $segments, $override, $found);
            }
        }

        return $node;
    }
}
