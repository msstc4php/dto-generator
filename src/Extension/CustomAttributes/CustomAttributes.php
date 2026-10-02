<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Extension\CustomAttributes;

use MSSTC4PHP\DtoGenerator\Contract\ClassContext;
use MSSTC4PHP\DtoGenerator\Contract\ClassEnricher;
use MSSTC4PHP\DtoGenerator\Contract\Extension;
use MSSTC4PHP\DtoGenerator\Contract\ExtensionRegistry;
use MSSTC4PHP\DtoGenerator\Contract\PropertyContext;
use MSSTC4PHP\DtoGenerator\Contract\PropertyEnricher;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Model\AttributeModel;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Schema;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Json;
use UnexpectedValueException;

/**
 * The built-in extension (spec §7.1, §7.2): `x-php-attributes` first, then the `attributeAliases` the schema uses, in
 * the order the config lists them.
 *
 * @phpstan-import-type JsonValue from Json
 */
final class CustomAttributes implements Extension, PropertyEnricher, ClassEnricher
{
    /** @var array<string, array<array-key, mixed>> */
    private array $aliases;

    private AttributeParser $parser;

    private AliasExpander $expander;

    /**
     * @param array<string, array<array-key, mixed>> $aliases attributeAliases of the config: key → `{class, args}` template
     */
    public function __construct(array $aliases)
    {
        $this->aliases = $aliases;
        $this->parser = new AttributeParser();
        $this->expander = new AliasExpander();
    }

    public function name(): string
    {
        return 'custom-attributes';
    }

    public function register(ExtensionRegistry $registry, array $config): void
    {
        $registry->addClassEnricher($this);
        $registry->addPropertyEnricher($this);
    }

    public function enrichClass(ClassContext $context): array
    {
        return $this->attributes($context->schema(), $context->diagnostics());
    }

    public function enrichProperty(PropertyContext $context): array
    {
        return $this->attributes($context->schema(), $context->diagnostics());
    }

    /**
     * @return list<AttributeModel>
     */
    private function attributes(Schema $schema, Diagnostics $diagnostics): array
    {
        $extensions = $schema->extensions();
        $attributes = [];
        if ($extensions->has('x-php-attributes')) {
            $attributes = $this->declared($extensions->get('x-php-attributes'), $schema, $diagnostics);
        }

        foreach ($this->aliases as $key => $template) {
            if ($extensions->has($key)) {
                $attribute = $this->aliased($key, array_map([Json::class, 'value'], $template), $extensions->get($key), $schema, $diagnostics);
                if ($attribute instanceof AttributeModel) {
                    $attributes[] = $attribute;
                }
            }
        }

        return $attributes;
    }

    /**
     * @param JsonValue $declared
     *
     * @return list<AttributeModel>
     */
    private function declared($declared, Schema $schema, Diagnostics $diagnostics): array
    {
        $at = $schema->location()->child('x-php-attributes');
        if (!is_array($declared) || !Json::isList($declared)) {
            $diagnostics->error('"x-php-attributes" must be a list of attributes.', $at);

            return [];
        }

        $attributes = [];
        foreach ($declared as $index => $declaration) {
            try {
                $attributes[] = $this->parser->attribute(Json::value($declaration), $at->child((string) $index));
            } catch (GrammarError $error) {
                $diagnostics->error($error->getMessage(), $error->location());
            }
        }

        return $attributes;
    }

    /**
     * @param array<array-key, JsonValue> $template
     * @param JsonValue $value
     */
    private function aliased(string $key, array $template, $value, Schema $schema, Diagnostics $diagnostics): ?AttributeModel
    {
        $at = $schema->location()->child($key);
        try {
            return $this->parser->attribute($this->expander->expand($template, $value), $at);
        } catch (UnexpectedValueException $exception) {
            $diagnostics->error(sprintf('Alias "%s" %s.', $key, $exception->getMessage()), $at);
        } catch (GrammarError $error) {
            $diagnostics->error(sprintf('Alias "%s": %s', $key, $error->getMessage()), $at);
        }

        return null;
    }
}
