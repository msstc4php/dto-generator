<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Service\Generate;

/**
 * Everything writing would do, computed before anything is touched.
 */
final class WritePlan
{
    /** @var list<FileChange> */
    private array $changes;

    /** @var array<string, string> */
    private array $conflicts;

    /** @var array<string, string> */
    private array $manifests;

    /** @var array<string, string> */
    private array $interimManifests;

    /**
     * @param list<FileChange> $changes
     * @param array<string, string> $conflicts path → why writing must not touch the file
     * @param array<string, string> $manifests manifest path → new contents, for manifests that change
     * @param array<string, string> $interimManifests manifest path → contents written before any file, listing both
     *                                                the files about to be written and those about to be deleted
     */
    public function __construct(array $changes, array $conflicts, array $manifests, array $interimManifests = [])
    {
        $this->changes = $changes;
        $this->conflicts = $conflicts;
        $this->manifests = $manifests;
        $this->interimManifests = $interimManifests;
    }

    /**
     * @return list<FileChange>
     */
    public function changes(): array
    {
        return $this->changes;
    }

    /**
     * @return array<string, string>
     */
    public function conflicts(): array
    {
        return $this->conflicts;
    }

    /**
     * @return array<string, string>
     */
    public function manifests(): array
    {
        return $this->manifests;
    }

    /**
     * @return array<string, string>
     */
    public function interimManifests(): array
    {
        return $this->interimManifests;
    }

    public function hasChanges(): bool
    {
        foreach ($this->changes as $change) {
            if ($change->isChange()) {
                return true;
            }
        }

        return $this->manifests !== [];
    }
}
