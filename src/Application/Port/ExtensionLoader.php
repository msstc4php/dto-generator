<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Port;

use MSSTC4PHP\DtoGenerator\Contract\Extension;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;

interface ExtensionLoader
{
    /**
     * @throws ExtensionFailed when the class is no extension that can be created without arguments
     */
    public function load(ClassName $class): Extension;
}
