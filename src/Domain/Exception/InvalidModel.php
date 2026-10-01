<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Exception;

use InvalidArgumentException;

final class InvalidModel extends InvalidArgumentException implements DomainError
{
}
