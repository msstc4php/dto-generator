<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Port;

use RuntimeException;

/**
 * The consumer's autoloader could not answer: loading it or a class it maps failed.
 */
final class ClassVerificationFailed extends RuntimeException
{
}
