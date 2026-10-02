<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Support\Annotations;

/**
 * @Annotation
 */
final class Ref
{
    public const LEVEL = 3;

    /** @var mixed */
    public $type;

    /** @var mixed */
    public $level;

    /** @var mixed */
    public $inner;

    /** @var mixed */
    public $map;
}
