<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Model;

use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;

/**
 * @api
 */
final class ScalarType implements TypeModel
{
    /** @var 'string'|'int'|'float'|'bool' */
    private string $kind;

    private ?string $phpDoc;

    /**
     * @param 'string'|'int'|'float'|'bool' $kind
     */
    private function __construct(string $kind, ?string $phpDoc)
    {
        if ($phpDoc !== null && trim($phpDoc) === '') {
            throw new InvalidModel(sprintf('The PHPDoc refinement of a %s type must not be blank.', $kind));
        }

        if ($phpDoc !== null && preg_match('/\*\/|[\r\n]/', $phpDoc) === 1) {
            throw new InvalidModel(sprintf('The PHPDoc refinement of a %s type must be a single-line PHPDoc type without "*/".', $kind));
        }

        $this->kind = $kind;
        $this->phpDoc = $phpDoc;
    }

    public static function string(?string $phpDoc = null): self
    {
        return new self('string', $phpDoc);
    }

    public static function int(?string $phpDoc = null): self
    {
        return new self('int', $phpDoc);
    }

    public static function float(?string $phpDoc = null): self
    {
        return new self('float', $phpDoc);
    }

    public static function bool(?string $phpDoc = null): self
    {
        return new self('bool', $phpDoc);
    }

    /**
     * @return 'string'|'int'|'float'|'bool'
     */
    public function kind(): string
    {
        return $this->kind;
    }

    /**
     * A narrower PHPDoc type such as `non-empty-string` or `int<1, 10>`.
     */
    public function phpDoc(): ?string
    {
        return $this->phpDoc;
    }

    public function describe(): string
    {
        return $this->phpDoc ?? $this->kind;
    }
}
