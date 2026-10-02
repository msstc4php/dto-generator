<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Infrastructure\Extension;

use MSSTC4PHP\DtoGenerator\Application\Port\ExtensionFailed;
use MSSTC4PHP\DtoGenerator\Application\Port\ExtensionLoader;
use MSSTC4PHP\DtoGenerator\Contract\Extension;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;
use ReflectionClass;

/**
 * Creates an extension class through the autoloader of the running process.
 */
final class ClassExtensionLoader implements ExtensionLoader
{
    public function load(ClassName $class): Extension
    {
        $fqcn = $class->fqcn();
        if (!class_exists($fqcn) && !interface_exists($fqcn)) {
            throw new ExtensionFailed(sprintf('Class %s does not exist.', $fqcn));
        }

        if (!is_a($fqcn, Extension::class, true)) {
            throw new ExtensionFailed(sprintf('Class %s does not implement %s.', $fqcn, Extension::class));
        }

        $reflection = new ReflectionClass($fqcn);
        if (!$reflection->isInstantiable()) {
            throw new ExtensionFailed(sprintf('Class %s cannot be instantiated.', $fqcn));
        }

        $constructor = $reflection->getConstructor();
        if ($constructor !== null && $constructor->getNumberOfRequiredParameters() > 0) {
            throw new ExtensionFailed(sprintf('Class %s needs constructor arguments; an extension is created without any.', $fqcn));
        }

        return $reflection->newInstance();
    }
}
