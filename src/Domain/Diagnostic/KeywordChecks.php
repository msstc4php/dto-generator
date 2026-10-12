<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Diagnostic;

use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;

/**
 * The occurrences of the keywords extensions claim. An extension marks each one it checks, or warns about itself; the
 * generator warns that the others have no effect, as it does for keywords nobody claims.
 *
 * @api
 */
final class KeywordChecks
{
    /** @var list<string> */
    private array $claimed;

    /** @var array<string, array{string, SchemaLocation}> keyword location => the keyword and its schema */
    private array $pending = [];

    /**
     * @param list<string> $claimed
     *
     * @internal
     */
    public function __construct(array $claimed = [])
    {
        $this->claimed = $claimed;
    }

    /**
     * @internal
     */
    public function isClaimed(string $keyword): bool
    {
        return in_array($keyword, $this->claimed, true);
    }

    /**
     * @internal the parser reports each occurrence of a claimed keyword
     */
    public function expect(string $keyword, SchemaLocation $schema): void
    {
        $this->pending[$schema->child($keyword)->toString()] = [$keyword, $schema];
    }

    /**
     * The extension checked the keyword of this schema, or warned that it cannot.
     */
    public function checked(SchemaLocation $schema, string $keyword): void
    {
        unset($this->pending[$schema->child($keyword)->toString()]);
    }

    /**
     * @internal
     *
     * @return list<array{string, SchemaLocation}> each keyword nobody checked, with its schema
     */
    public function unchecked(): array
    {
        return array_values($this->pending);
    }
}
