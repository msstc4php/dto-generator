<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Infrastructure\Extension;

use MSSTC4PHP\DtoGenerator\Application\Port\ExtensionFailed;
use MSSTC4PHP\DtoGenerator\Application\Port\ExtensionLoader;
use MSSTC4PHP\DtoGenerator\Contract\Extension;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;
use ReflectionClass;
use Throwable;

/**
 * Creates an extension class through the autoloader of the running process.
 */
final class ClassExtensionLoader implements ExtensionLoader
{
    /**
     * Codes of foreign exceptions are not always ints (PDO uses strings), so only the message and the cause carry over.
     */
    public function load(ClassName $class): Extension
    {
        $fqcn = $class->fqcn();
        // Autoloading runs the extension's own code, which may fail in any way.
        try {
            $exists = class_exists($fqcn) || interface_exists($fqcn);
        } catch (Throwable $exception) {
            throw new ExtensionFailed(sprintf('Class %s could not be loaded: %s', $fqcn, $exception->getMessage()), 0, $exception);
        }

        if (!$exists) {
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

        try {
            return $reflection->newInstance();
        } catch (Throwable $exception) {
            throw new ExtensionFailed(sprintf('Class %s could not be created: %s', $fqcn, $exception->getMessage()), 0, $exception);
        }
    }
}
