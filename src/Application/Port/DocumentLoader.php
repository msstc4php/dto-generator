<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Port;

use MSSTC4PHP\DtoGenerator\Application\ValueObject\Document;

interface DocumentLoader
{
    /**
     * @throws DocumentLoadFailed
     */
    public function load(string $path): Document;
}
