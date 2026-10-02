<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Support\Annotations;

/**
 * @Annotation
 */
final class Pair
{
    /** @var mixed */
    public $value;

    /** @var mixed */
    public $strict;
}
