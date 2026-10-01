<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Diagnostic;

use Countable;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;

/**
 * Collecting parameter: generation reports every problem it finds instead of stopping at the first.
 */
final class Diagnostics implements Countable
{
    /** @var list<Diagnostic> */
    private array $items = [];

    public function error(string $message, SchemaLocation $location): void
    {
        $this->add(new Diagnostic(Severity::from(Severity::ERROR), $message, $location));
    }

    public function warning(string $message, SchemaLocation $location): void
    {
        $this->add(new Diagnostic(Severity::from(Severity::WARNING), $message, $location));
    }

    /**
     * A subtree parsed twice (as a component and as a $ref target) must not report its problems twice.
     */
    public function add(Diagnostic $diagnostic): void
    {
        foreach ($this->items as $item) {
            if ($item->toString() === $diagnostic->toString()) {
                return;
            }
        }

        $this->items[] = $diagnostic;
    }

    public function merge(self $other): void
    {
        foreach ($other->items as $diagnostic) {
            $this->add($diagnostic);
        }
    }

    /**
     * @return list<Diagnostic>
     */
    public function all(): array
    {
        return $this->items;
    }

    /**
     * @return list<Diagnostic>
     */
    public function errors(): array
    {
        return array_values(array_filter(
            $this->items,
            static fn (Diagnostic $diagnostic): bool => $diagnostic->severity()->isError(),
        ));
    }

    public function hasErrors(): bool
    {
        return $this->errors() !== [];
    }

    public function count(): int
    {
        return count($this->items);
    }
}
