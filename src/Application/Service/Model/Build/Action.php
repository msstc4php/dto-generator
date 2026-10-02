<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Service\Model\Build;

use MSSTC4PHP\DtoGenerator\Domain\Builder\AllOfResolver;
use MSSTC4PHP\DtoGenerator\Domain\Builder\ClassBuilder;
use MSSTC4PHP\DtoGenerator\Domain\Builder\ClassLookup;
use MSSTC4PHP\DtoGenerator\Domain\Builder\Composition;
use MSSTC4PHP\DtoGenerator\Domain\Builder\EnumBuilder;
use MSSTC4PHP\DtoGenerator\Domain\Builder\ExtensionVocabulary;
use MSSTC4PHP\DtoGenerator\Domain\Builder\Hierarchy;
use MSSTC4PHP\DtoGenerator\Domain\Builder\NameResolver;
use MSSTC4PHP\DtoGenerator\Domain\Builder\RequiredCycles;
use MSSTC4PHP\DtoGenerator\Domain\Builder\SchemaShape;
use MSSTC4PHP\DtoGenerator\Domain\Builder\TypeMapper;
use MSSTC4PHP\DtoGenerator\Domain\Builder\VariantResolver;
use MSSTC4PHP\DtoGenerator\Domain\Builder\Variants;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;
use MSSTC4PHP\DtoGenerator\Domain\Model\EnumModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\Identifier;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Discriminator;
use MSSTC4PHP\DtoGenerator\Domain\Schema\ResolvedSchema;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Schema;

/**
 * Turns the schema graph into the IR: one class per object schema and one enum per enum schema, including schemas
 * only reached by $ref, in the namespace of the source that owns them. Inline objects and enums of class
 * properties become `<Parent><Property>` declarations (spec §5.3).
 */
final class Action
{
    private NameResolver $names;

    public function __construct(NameResolver $names)
    {
        $this->names = $names;
    }

    public function __invoke(Input $input): Output
    {
        $diagnostics = new Diagnostics();
        $config = $input->config();
        $registry = new Registry();
        $enums = new EnumBuilder($this->names);

        foreach ($input->graph()->all() as $resolved) {
            $source = $resolved->source();
            $schema = $resolved->schema();
            $isClass = SchemaShape::isClass($schema);
            $isEnum = SchemaShape::isEnum($schema);
            if ($isClass || $isEnum) {
                ExtensionVocabulary::checkClass($schema, $diagnostics);
            } else {
                ExtensionVocabulary::checkAlias($schema, $diagnostics);
            }

            if ($source === null) {
                continue;
            }

            if (ClassBuilder::isSkipped($schema, $diagnostics)) {
                $registry->skip($schema);

                continue;
            }

            if ($schema->allOf() !== [] && SchemaShape::hasUnion($schema)) {
                $diagnostics->error('"allOf" together with "oneOf" or "anyOf" is not supported.', $schema->location());

                continue;
            }

            if (!$isClass && !$isEnum) {
                continue;
            }

            if ($schema->allOf() === [] && SchemaShape::hasUnion($schema) && !SchemaShape::isDiscriminated($schema)) {
                $diagnostics->warning('"oneOf" and "anyOf" beside "properties" are not represented; the class keeps only its properties.', $schema->location());
            }

            $short = $this->shortName($schema, $resolved->name(), $diagnostics);
            $name = $short === null ? null : ClassName::fromFqcn($config->sources()[$source]->namespace() . '\\' . $short);
            if ($name instanceof ClassName && $registry->claim($name, $schema, $diagnostics)) {
                $this->declare($schema, $name, $source, $isEnum, $registry, $enums, $diagnostics);
            }
        }

        // Every named class is declared by now, so `allOf` can tell which members it may extend.
        $allOf = new AllOfResolver(new ClassLookup($input->graph(), $registry->declarations()), $config->dto()->allOfStrategy());
        $compositions = [];
        // Inline classes are planned while walking, so the walk reaches inline objects nested in inline objects.
        for ($index = 0; ($planned = $registry->plannedAt($index)) !== null; $index++) {
            $compositions[$index] = $allOf->compose($planned[0], $diagnostics);
            $this->hoist($planned[0], $compositions[$index], $planned[1], $planned[2], $registry, $enums, $diagnostics);
        }

        $declarations = $registry->declarations();
        $builder = new ClassBuilder(
            $this->names,
            new TypeMapper($input->graph(), $declarations, $input->target(), $config->formats()),
            $input->target(),
        );
        $models = [];
        $subclasses = [];
        foreach ($registry->planned() as $index => [$schema, $name]) {
            $models[] = $builder->build($name, $schema, $diagnostics, $compositions[$index]);
            $parent = $compositions[$index]->parent();
            $resolved = $input->graph()->get($schema->location());
            if ($parent instanceof ClassName && $resolved instanceof ResolvedSchema) {
                $subclasses[$parent->fqcn()][] = [$resolved, $name];
            }
        }

        $unions = $this->unions($input, $registry, $subclasses, $diagnostics);
        $built = [];
        foreach (Hierarchy::link($models, $unions, $diagnostics) as $index => $model) {
            $built[] = new BuiltClass($model, $registry->planned()[$index][2]);
        }

        $output = new Output($built, $diagnostics, $registry->enums());
        $models = [];
        $inherited = [];
        foreach ($output->classes() as $class) {
            $models[] = $class->model();
            $inherited[$class->model()->name()->fqcn()] = $output->inheritedProperties($class->model());
        }

        RequiredCycles::check($models, $diagnostics, $inherited);

        return $output;
    }

    /**
     * The discriminated bases: a `oneOf`/`anyOf` lists its variants, a plain class is extended by them through `allOf`.
     *
     * @param array<string, list<array{ResolvedSchema, ClassName}>> $subclasses parent FQCN → named classes extending it
     *
     * @return array<string, Variants>
     */
    private function unions(Input $input, Registry $registry, array $subclasses, Diagnostics $diagnostics): array
    {
        $variants = new VariantResolver($input->graph(), new ClassLookup($input->graph(), $registry->declarations()));
        $unions = [];
        foreach ($registry->planned() as [$schema, $name]) {
            $listed = SchemaShape::discriminatorOf($schema);
            $discriminator = $schema->discriminator();
            if ($listed instanceof Discriminator) {
                $unions[$name->fqcn()] = $variants->listed($schema, $listed, $diagnostics);
            } elseif ($discriminator instanceof Discriminator && isset($subclasses[$name->fqcn()])) {
                $unions[$name->fqcn()] = $variants->subclasses($schema, $discriminator, $subclasses[$name->fqcn()], $diagnostics);
            } elseif ($discriminator instanceof Discriminator) {
                $diagnostics->warning(
                    'The discriminator is ignored: no oneOf or anyOf lists variants and no schema extends this one through allOf.',
                    $schema->location()->child('discriminator'),
                );
            }
        }

        return $unions;
    }

    /**
     * False when the enum could not be built (the reason was reported).
     */
    private function declare(Schema $schema, ClassName $name, int $source, bool $isEnum, Registry $registry, EnumBuilder $enums, Diagnostics $diagnostics): bool
    {
        if (!$isEnum) {
            $registry->planClass($schema, $name, $source);

            return true;
        }

        $enum = $enums->build($name, $schema, $diagnostics);
        if (!$enum instanceof EnumModel) {
            return false;
        }

        $registry->addEnum($schema, $enum, $source);

        return true;
    }

    /**
     * Declares the inline objects and enums of a class's properties, looking through arrays (`…Item`).
     */
    private function hoist(Schema $owner, Composition $composition, ClassName $ownerName, int $source, Registry $registry, EnumBuilder $enums, Diagnostics $diagnostics): void
    {
        /** @var list<array{Schema, ?string, string}> $candidates schema, `<Parent><Property>` (null without usable characters), wire name */
        $candidates = [];
        foreach ($composition->ownParts() as $part) {
            foreach ($part->propertyNames() as $wireName) {
                $property = $part->requireProperty($wireName);
                // The class builder reports a malformed x-php-skip; here it only decides whether to look further.
                if (!ClassBuilder::isSkipped($property, new Diagnostics())) {
                    $base = $this->names->className($wireName);
                    $candidates[] = [$property, $base === null ? null : $ownerName->shortName() . $base, $wireName];
                }
            }
        }

        $additional = $owner->additionalProperties();
        if ($additional instanceof Schema) {
            $candidates[] = [$additional, $ownerName->shortName() . 'AdditionalProperty', 'additionalProperties'];
        }

        foreach ($candidates as [$schema, $baseName, $wireName]) {
            $inline = $this->inline($schema, '');
            // A schema reached by $ref from elsewhere may already carry its own name.
            if ($inline === null || $registry->isDeclared($inline[0])) {
                continue;
            }

            [$candidate, $suffix] = $inline;
            $short = $this->inlineName($candidate, $baseName === null ? null : $baseName . $suffix, $wireName, $diagnostics);
            $name = $short === null ? null : ClassName::fromFqcn(($ownerName->namespace() === '' ? '' : $ownerName->namespace() . '\\') . $short);
            if (!$name instanceof ClassName || !$registry->claim($name, $candidate, $diagnostics) || !$this->declare($candidate, $name, $source, SchemaShape::isEnum($candidate), $registry, $enums, $diagnostics)) {
                $registry->abandon($candidate);
            }
        }
    }

    /**
     * x-php-class-name, or `<Parent><Property>…`; a property name with no usable characters needs the override.
     */
    private function inlineName(Schema $schema, ?string $derived, string $wireName, Diagnostics $diagnostics): ?string
    {
        if ($derived !== null || $schema->extensions()->has('x-php-class-name')) {
            return $this->shortName($schema, (string) $derived, $diagnostics);
        }

        $diagnostics->error(
            sprintf('Property name "%s" gives no class name for its inline schema; set "x-php-class-name".', $wireName),
            $schema->location(),
        );

        return null;
    }

    /**
     * The inline object or enum a property holds, looking through arrays; each array level adds "Item" to the name.
     *
     * @return array{Schema, string}|null
     */
    private function inline(Schema $schema, string $suffix): ?array
    {
        // A discriminated union becomes a base class only as a named schema (spec §5.3); inline it is a union type.
        if ((SchemaShape::isClass($schema) && !SchemaShape::isDiscriminated($schema)) || SchemaShape::isEnum($schema)) {
            return [$schema, $suffix];
        }

        $items = $schema->items();
        if ($items instanceof Schema) {
            return $this->inline($items, $suffix . 'Item');
        }

        $values = $schema->additionalProperties();

        return $values instanceof Schema ? $this->inline($values, $suffix . 'Value') : null;
    }

    /**
     * x-php-class-name, or the PascalCase form of the schema name (an inline name is already PascalCase).
     */
    private function shortName(Schema $schema, string $schemaName, Diagnostics $diagnostics): ?string
    {
        if ($schema->extensions()->has('x-php-class-name')) {
            $override = $schema->extensions()->get('x-php-class-name');
            if (is_string($override) && Identifier::isValid($override) && !Identifier::isReserved($override)) {
                return $override;
            }

            $diagnostics->error(
                '"x-php-class-name" must be a PHP identifier that is not a reserved word.',
                $schema->location()->child('x-php-class-name'),
            );

            return null;
        }

        $name = $this->names->className($schemaName);
        if ($name === null) {
            $diagnostics->error(
                sprintf('Schema name "%s" has no usable characters; set "x-php-class-name".', $schemaName),
                $schema->location(),
            );
        }

        return $name;
    }
}
