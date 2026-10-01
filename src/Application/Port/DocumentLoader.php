<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Port;

interface DocumentLoader
{
    /**
     * @throws DocumentLoadFailed
     */
    public function load(string $path): Document;
}
