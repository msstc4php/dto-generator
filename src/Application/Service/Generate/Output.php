<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Service\Generate;

use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;

/**
 * @api
 */
final class Output
{
    private Status $status;

    private Diagnostics $diagnostics;

    private ?WritePlan $plan;

    /** @var list<GeneratedFile> */
    private array $files;

    /**
     * @param list<GeneratedFile> $files
     */
    public function __construct(Status $status, Diagnostics $diagnostics, ?WritePlan $plan, array $files)
    {
        $this->status = $status;
        $this->diagnostics = $diagnostics;
        $this->plan = $plan;
        $this->files = $files;
    }

    public function status(): Status
    {
        return $this->status;
    }

    public function diagnostics(): Diagnostics
    {
        return $this->diagnostics;
    }

    /**
     * Null when the run stopped before planning (a config or schema error).
     */
    public function plan(): ?WritePlan
    {
        return $this->plan;
    }

    /**
     * @return list<GeneratedFile>
     */
    public function files(): array
    {
        return $this->files;
    }
}
