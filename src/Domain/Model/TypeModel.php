<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Model;

/**
 * @api
 */
interface TypeModel
{
    /**
     * Canonical PHPDoc-style form; two types are equal when their descriptions are equal.
     */
    public function describe(): string;
}
