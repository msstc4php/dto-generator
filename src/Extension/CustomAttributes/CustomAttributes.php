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
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Json;
use MSSTC4PHP\DtoGenerator\Domain\Target\AttributeRules;
use MSSTC4PHP\DtoGenerator\Domain\Target\TargetProfile;
use UnexpectedValueException;

/**
 * The built-in extension (spec §7.1, §7.2): `x-php-attributes` first, then the `attributeAliases` the schema uses, in
 * the order the config lists them.
 *
 * @phpstan-import-type JsonValue from Json
 * @phpstan-import-type AttributeDeclaration from AttributeModel
 */
final class CustomAttributes implements Extension, PropertyEnricher, ClassEnricher
{
    /** @var array<string, AttributeDeclaration> */
    private array $aliases;

    private AttributeParser $parser;

    private AliasExpander $expander;

    /**
     * @param array<string, AttributeDeclaration> $aliases attributeAliases of the config: key → `{class, args}` template
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
        // The keys of an inline object describe the property that holds it.
        return $context->isInline() ? [] : $this->attributes($context->schema(), $context->target(), $context->diagnostics());
    }

    public function enrichProperty(PropertyContext $context): array
    {
        return $this->attributes($context->schema(), $context->target(), $context->diagnostics());
    }

    /**
     * @return list<AttributeModel>
     */
    private function attributes(Schema $schema, TargetProfile $target, Diagnostics $diagnostics): array
    {
        $extensions = $schema->extensions();
        $attributes = [];
        if ($extensions->has('x-php-attributes')) {
            $attributes = $this->declared($extensions->get('x-php-attributes'), $schema, $target, $diagnostics);
        }

        foreach ($this->aliases as $key => $template) {
            if ($extensions->has($key)) {
                $at = $schema->location()->child($key);
                $attribute = $this->aliased($key, $template, $extensions->get($key), $at, $diagnostics);
                if ($attribute instanceof AttributeModel) {
                    // Checked here, where the alias key locates the attribute exactly.
                    array_push($attributes, ...AttributeRules::admitted([$attribute], $target, $at, $diagnostics));
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
    private function declared($declared, Schema $schema, TargetProfile $target, Diagnostics $diagnostics): array
    {
        $at = $schema->location()->child('x-php-attributes');
        if (!is_array($declared) || !Json::isList($declared)) {
            $diagnostics->error('"x-php-attributes" must be a list of attributes.', $at);

            return [];
        }

        $attributes = [];
        foreach ($declared as $index => $declaration) {
            $item = $at->child((string) $index);
            try {
                array_push($attributes, ...AttributeRules::admitted([$this->parser->attribute(Json::value($declaration), $item)], $target, $item, $diagnostics));
            } catch (GrammarError $error) {
                $diagnostics->error($error->getMessage(), $error->location());
            }
        }

        return $attributes;
    }

    /**
     * @param AttributeDeclaration $template
     * @param JsonValue $value
     */
    private function aliased(string $key, array $template, $value, SchemaLocation $at, Diagnostics $diagnostics): ?AttributeModel
    {
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
