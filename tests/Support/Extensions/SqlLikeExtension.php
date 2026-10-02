<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Support\Extensions;

use MSSTC4PHP\DtoGenerator\Contract\Extension;
use MSSTC4PHP\DtoGenerator\Contract\ExtensionRegistry;
use RuntimeException;

/**
 * Fails like PDO does, with an exception whose code is a string.
 */
final class SqlLikeExtension implements Extension
{
    public function __construct()
    {
        throw new class('no database') extends RuntimeException {
            public function __construct(string $message)
            {
                parent::__construct($message);
                $this->code = 'HY000';
            }
        };
    }

    public function name(): string
    {
        return 'sql-like';
    }

    public function register(ExtensionRegistry $registry, array $config): void
    {
    }
}
