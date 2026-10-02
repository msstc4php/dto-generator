<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Support\Extensions;

use MSSTC4PHP\DtoGenerator\Contract\ClassContext;
use MSSTC4PHP\DtoGenerator\Contract\ClassEnricher;
use MSSTC4PHP\DtoGenerator\Contract\Extension;
use MSSTC4PHP\DtoGenerator\Contract\ExtensionRegistry;
use MSSTC4PHP\DtoGenerator\Domain\Model\ArgumentValue;
use MSSTC4PHP\DtoGenerator\Domain\Model\AttributeArgument;
use MSSTC4PHP\DtoGenerator\Domain\Model\AttributeModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;

/**
 * Marks every class with the consumer's version of symfony/validator, or "none".
 */
final class PackageVersionExtension implements Extension, ClassEnricher
{
    public function name(): string
    {
        return 'package-version';
    }

    public function register(ExtensionRegistry $registry, array $config): void
    {
        $registry->addClassEnricher($this);
    }

    public function enrichClass(ClassContext $context): array
    {
        $version = $context->packages()->version('symfony/validator') ?? 'none';

        return [new AttributeModel(ClassName::fromFqcn('App\Attr\Validator'), [AttributeArgument::positional(ArgumentValue::literal($version))])];
    }
}
