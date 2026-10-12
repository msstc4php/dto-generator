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

    /** @var array<string, ClaimedKeyword> by the location of the keyword */
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
    public function expect(SchemaLocation $schema, string $keyword): void
    {
        $occurrence = new ClaimedKeyword($schema, $keyword);
        $this->pending[$occurrence->location()->toString()] = $occurrence;
    }

    /**
     * The extension checked the keyword of this schema, or warned that it cannot. The schema is the one that holds the
     * keyword: the target of a `$ref` or the `allOf` branch, not the property that reaches it.
     */
    public function checked(SchemaLocation $schema, string $keyword): void
    {
        unset($this->pending[$schema->child($keyword)->toString()]);
    }

    /**
     * @internal
     *
     * @return list<ClaimedKeyword> each occurrence nobody checked
     */
    public function unchecked(): array
    {
        return array_values($this->pending);
    }
}
