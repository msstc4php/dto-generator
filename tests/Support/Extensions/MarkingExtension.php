<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Support\Extensions;

use MSSTC4PHP\DtoGenerator\Contract\ClassContext;
use MSSTC4PHP\DtoGenerator\Contract\ClassEnricher;
use MSSTC4PHP\DtoGenerator\Contract\Extension;
use MSSTC4PHP\DtoGenerator\Contract\ExtensionRegistry;
use MSSTC4PHP\DtoGenerator\Contract\PropertyContext;
use MSSTC4PHP\DtoGenerator\Contract\PropertyEnricher;
use MSSTC4PHP\DtoGenerator\Domain\Model\ArgumentValue;
use MSSTC4PHP\DtoGenerator\Domain\Model\AttributeArgument;
use MSSTC4PHP\DtoGenerator\Domain\Model\AttributeModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;

/**
 * Marks every class and property with `App\Attr\Marked`, carrying the wire name of the property or the configured label.
 */
final class MarkingExtension implements Extension, PropertyEnricher, ClassEnricher
{
    private string $label = '';

    public function name(): string
    {
        return 'marking';
    }

    public function register(ExtensionRegistry $registry, array $config): void
    {
        $this->label = is_string($config['label'] ?? null) ? $config['label'] : 'class';
        $registry->addPropertyEnricher($this);
        $registry->addClassEnricher($this);
        $registry->claimExtensionKeys('x-marking-*');
    }

    public function enrichProperty(PropertyContext $context): array
    {
        return [new AttributeModel(ClassName::fromFqcn('App\Attr\Marked'), [AttributeArgument::positional(ArgumentValue::literal($context->property()->wireName()))])];
    }

    public function enrichClass(ClassContext $context): array
    {
        return [new AttributeModel(ClassName::fromFqcn('App\Attr\Marked'), [AttributeArgument::positional(ArgumentValue::literal($this->label))])];
    }
}
