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

    /** @var list<string> */
    private array $conflicts;

    /** @var array<string, string> */
    private array $manifests;

    /**
     * @param list<FileChange> $changes
     * @param list<string> $conflicts files writing must not touch, each with the reason
     * @param array<string, string> $manifests manifest path → new contents, for manifests that change
     */
    public function __construct(array $changes, array $conflicts, array $manifests)
    {
        $this->changes = $changes;
        $this->conflicts = $conflicts;
        $this->manifests = $manifests;
    }

    /**
     * @return list<FileChange>
     */
    public function changes(): array
    {
        return $this->changes;
    }

    /**
     * @return list<string>
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
