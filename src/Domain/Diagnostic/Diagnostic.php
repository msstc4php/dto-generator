<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Diagnostic;

use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;

final class Diagnostic
{
    private Severity $severity;

    private string $message;

    private ?SchemaLocation $location;

    public function __construct(Severity $severity, string $message, ?SchemaLocation $location = null)
    {
        if (trim($message) === '') {
            throw new InvalidModel('A diagnostic needs a message.');
        }

        $this->severity = $severity;
        $this->message = $message;
        $this->location = $location;
    }

    public function severity(): Severity
    {
        return $this->severity;
    }

    public function message(): string
    {
        return $this->message;
    }

    public function location(): ?SchemaLocation
    {
        return $this->location;
    }

    public function toString(): string
    {
        if (!$this->location instanceof SchemaLocation) {
            return sprintf('%s: %s', $this->severity->value(), $this->message);
        }

        return sprintf('%s %s: %s', $this->severity->value(), $this->location->toString(), $this->message);
    }
}
