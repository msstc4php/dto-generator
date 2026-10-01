<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Service\Schemas\Load;

use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Schema\ResolvedSchema;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaGraph;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;

/**
 * Mutable accumulator for one load run.
 */
final class GraphBuilder
{
    /** @var array<string, ResolvedSchema> */
    private array $schemas = [];

    /** @var array<string, true> */
    private array $failed = [];

    /** @var array<string, list<string>> target key → keys of the schemas that reference it */
    private array $referrers = [];

    public function add(ResolvedSchema $schema): void
    {
        $this->schemas[$schema->location()->toString()] = $schema;
    }

    public function knows(SchemaLocation $location): bool
    {
        $key = $location->toString();

        return isset($this->schemas[$key]) || isset($this->failed[$key]);
    }

    public function markFailed(SchemaLocation $location): void
    {
        $this->failed[$location->toString()] = true;
    }

    public function addReference(SchemaLocation $from, SchemaLocation $to): void
    {
        $this->referrers[$to->toString()][] = $from->toString();
    }

    /**
     * A schema from a file outside every source belongs to the sources that reference it (spec §4);
     * more than one owner leaves its namespace undecidable.
     */
    public function build(Diagnostics $diagnostics): SchemaGraph
    {
        $owners = $this->propagateOwners();
        $schemas = [];
        foreach ($this->schemas as $key => $schema) {
            if ($schema->source() === null) {
                $candidates = array_keys($owners[$key] ?? []);
                if (count($candidates) > 1) {
                    $diagnostics->error(
                        sprintf(
                            'Schema is referenced from sources %s, so its namespace is ambiguous; add its file as a source.',
                            implode(', ', array_map(static fn (int $index): string => '#' . $index, $candidates)),
                        ),
                        $schema->location(),
                    );
                } elseif ($candidates !== []) {
                    $schema = $schema->withSource($candidates[0]);
                }
            }

            $schemas[] = $schema;
        }

        return new SchemaGraph($schemas);
    }

    /**
     * @return array<string, array<int, true>>
     */
    private function propagateOwners(): array
    {
        $owners = [];
        foreach ($this->schemas as $key => $schema) {
            if ($schema->source() !== null) {
                $owners[$key] = [$schema->source() => true];
            }
        }

        do {
            $changed = false;
            foreach ($this->referrers as $target => $referrerKeys) {
                if (!isset($this->schemas[$target]) || $this->schemas[$target]->source() !== null) {
                    continue;
                }

                foreach ($referrerKeys as $referrerKey) {
                    foreach (array_keys($owners[$referrerKey] ?? []) as $owner) {
                        if (!isset($owners[$target][$owner])) {
                            $owners[$target][$owner] = true;
                            $changed = true;
                        }
                    }
                }
            }
        } while ($changed);

        return $owners;
    }
}
