<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Service\Model\Build;

use MSSTC4PHP\DtoGenerator\Domain\Builder\AllOfResolver;
use MSSTC4PHP\DtoGenerator\Domain\Builder\ClassBuilder;
use MSSTC4PHP\DtoGenerator\Domain\Builder\ClassLookup;
use MSSTC4PHP\DtoGenerator\Domain\Builder\Composition;
use MSSTC4PHP\DtoGenerator\Domain\Builder\Directions;
use MSSTC4PHP\DtoGenerator\Domain\Builder\EnumBuilder;
use MSSTC4PHP\DtoGenerator\Domain\Builder\ExtensionVocabulary;
use MSSTC4PHP\DtoGenerator\Domain\Builder\Hierarchy;
use MSSTC4PHP\DtoGenerator\Domain\Builder\NamedClass;
use MSSTC4PHP\DtoGenerator\Domain\Builder\NameResolver;
use MSSTC4PHP\DtoGenerator\Domain\Builder\PropertyView;
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
 * properties, including the members of their unions, become `<Parent><Property>…` declarations (spec §5.3).
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
        $dto = $input->config()->dto();
        if (!$dto->splitsReadAndWrite()) {
            return $this->build($input, null)->output();
        }

        return (new Views($input->graph(), $dto->viewSuffixes()))->build(fn (?View $view): Run => $this->build($input, $view));
    }

    private function build(Input $input, ?View $view): Run
    {
        $diagnostics = new Diagnostics();
        $config = $input->config();
        $registry = new Registry($view, $view instanceof View ? new PropertyView($view->direction(), new Directions($input->graph(), $diagnostics)) : null);
        $enums = new EnumBuilder($this->names);
        /** @var list<array{Schema, string, int}> $aliases named non-object schemas with their name and source */
        $aliases = [];

        foreach ($input->graph()->all() as $resolved) {
            $source = $resolved->source();
            $schema = $resolved->schema();
            $isClass = SchemaShape::isClass($schema);
            $isEnum = SchemaShape::isEnum($schema);
            if ($isClass) {
                ExtensionVocabulary::checkClass($schema, $diagnostics, $input->aliases());
            } elseif ($isEnum) {
                ExtensionVocabulary::checkEnum($schema, $diagnostics, $input->aliases());
            } else {
                ExtensionVocabulary::checkAlias($schema, $diagnostics, $input->aliases());
            }

            if ($source === null) {
                continue;
            }

            if (ClassBuilder::isSkipped($schema, $diagnostics)) {
                $registry->skip($schema);

                continue;
            }

            if (SchemaShape::mayBeObject($schema) && $schema->allOf() !== [] && SchemaShape::hasUnion($schema)) {
                $diagnostics->error('"allOf" together with "oneOf" or "anyOf" is not supported.', $schema->location());

                continue;
            }

            if (!$isClass && !$isEnum) {
                $aliases[] = [$schema, $resolved->name(), $source];

                continue;
            }

            if ($schema->allOf() === [] && SchemaShape::hasUnion($schema) && !SchemaShape::isDiscriminated($schema)) {
                $diagnostics->warning('"oneOf" and "anyOf" beside "properties" are not represented; the class keeps only its properties.', $schema->location());
            }

            $short = $this->shortName($schema, $resolved->name(), $diagnostics);
            $name = $short === null ? null : $registry->className($config->sources()[$source]->namespace(), $short, $schema);
            if ($name instanceof ClassName && $registry->claim($name, $schema, $diagnostics)) {
                $this->declare($schema, $name, $source, $isEnum, $registry, $enums, $diagnostics);
            }
        }

        // Only once every named schema holds its name, so a derived or titled inline name never takes one.
        foreach ($aliases as [$alias, $aliasName, $source]) {
            $this->hoistFromAlias($alias, $aliasName, $config->sources()[$source]->namespace(), $source, $registry, $enums, $diagnostics);
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
            new TypeMapper($input->graph(), $declarations, $input->target(), $input->formats()),
            $input->target(),
            $input->aliases(),
            $registry->properties(),
        );
        $models = [];
        $children = [];
        foreach ($registry->planned() as $index => [$schema, $name]) {
            $models[] = $builder->build($name, $schema, $diagnostics, $compositions[$index]);
            $parent = $compositions[$index]->parent();
            $resolved = $input->graph()->get($schema->location());
            if ($parent instanceof ClassName && $resolved instanceof ResolvedSchema) {
                $children[$parent->fqcn()][] = new NamedClass($resolved, $name);
            }
        }

        $subclasses = [];
        foreach (array_keys($children) as $parent) {
            $subclasses[$parent] = $this->descendants($parent, $children);
        }

        $unions = $this->unions($input, $registry, $children, $subclasses, $diagnostics);
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
        $classSchemas = [];
        foreach ($registry->planned() as $index => [$schema]) {
            $classSchemas[] = new ClassSchema($schema, $compositions[$index]);
        }

        foreach ($registry->rejected() as [$schema, $taker]) {
            // Reported where its name was refused; a split still needs to know whether it depends on the direction.
            $classSchemas[] = new ClassSchema($schema, $allOf->compose($schema, new Diagnostics()), $taker);
        }

        return new Run($output, $classSchemas);
    }

    /**
     * The discriminated bases: a `oneOf`/`anyOf` lists its variants, a plain class is extended by them through `allOf`.
     *
     * @param array<string, list<NamedClass>> $children parent FQCN → named classes extending it directly
     * @param array<string, list<NamedClass>> $subclasses parent FQCN → every named class below it
     *
     * @return array<string, Variants>
     */
    private function unions(Input $input, Registry $registry, array $children, array $subclasses, Diagnostics $diagnostics): array
    {
        $variants = new VariantResolver($input->graph(), new ClassLookup($input->graph(), $registry->declarations()));
        $unions = [];
        foreach ($registry->planned() as [$schema, $name]) {
            $listed = SchemaShape::discriminatorOf($schema);
            $discriminator = $schema->discriminator();
            if ($listed instanceof Discriminator) {
                $unions[$name->fqcn()] = $variants->listed($schema, $listed, $diagnostics);
                // A deeper subclass descends from one of these children, listed or reported.
                $this->warnAboutUnlistedSubclasses($name, $unions[$name->fqcn()], $children[$name->fqcn()] ?? [], $diagnostics);
            } elseif ($discriminator instanceof Discriminator && isset($subclasses[$name->fqcn()])) {
                $unions[$name->fqcn()] = $variants->subclasses($schema, $discriminator, $subclasses[$name->fqcn()], $diagnostics);
            } elseif ($discriminator instanceof Discriminator) {
                $diagnostics->warning(
                    'The discriminator is ignored: no oneOf or anyOf lists variants and no named schema extends this one through allOf.',
                    $schema->location()->child('discriminator'),
                );
            }
        }

        return $unions;
    }

    /**
     * Every named class below a parent, nearest first, so a whole Swagger-2 hierarchy shares one discriminator.
     *
     * @param array<string, list<NamedClass>> $children parent FQCN → named classes extending it directly
     *
     * @return list<NamedClass>
     */
    private function descendants(string $parent, array $children): array
    {
        $found = [];
        $seen = [$parent => $parent];
        for ($pending = $children[$parent]; $pending !== []; $pending = $next) {
            $next = [];
            foreach ($pending as $child) {
                $fqcn = $child->name()->fqcn();
                if (!isset($seen[$fqcn])) {
                    $seen[$fqcn] = $fqcn;
                    $found[] = $child;
                    $next = array_merge($next, $children[$fqcn] ?? []);
                }
            }
        }

        return $found;
    }

    /**
     * @param list<NamedClass> $subclasses
     */
    private function warnAboutUnlistedSubclasses(ClassName $base, Variants $variants, array $subclasses, Diagnostics $diagnostics): void
    {
        $listed = [];
        foreach ($variants->classes() as $variant) {
            $listed[$variant->fqcn()] = $variant;
        }

        foreach ($subclasses as $subclass) {
            $fqcn = $subclass->name()->fqcn();
            if (!isset($listed[$fqcn])) {
                $diagnostics->warning(
                    sprintf(
                        '%s extends the discriminated base %s but is not one of its variants, so it gets no discriminator value.',
                        $fqcn,
                        $base->fqcn(),
                    ),
                    $subclass->schema()->location(),
                );
            }
        }
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
     * Declares the inline objects and enums of a class's properties, looking through arrays (`…Item`), maps (`…Value`)
     * and the members of unions (`…Option<N>`).
     */
    private function hoist(Schema $owner, Composition $composition, ClassName $ownerName, int $source, Registry $registry, EnumBuilder $enums, Diagnostics $diagnostics): void
    {
        /** @var list<array{Schema, ?string, string}> $candidates schema, `<Parent><Property>` (null without usable characters), wire name */
        $candidates = [];
        // The other view's properties hold no class of this view.
        $excluded = $registry->excluded($composition);
        foreach ($composition->ownParts() as $part) {
            foreach ($part->propertyNames() as $wireName) {
                $property = $part->requireProperty($wireName);
                // The class builder reports a malformed x-php-skip; here it only decides whether to look further.
                if (!isset($excluded[$wireName]) && !ClassBuilder::isSkipped($property, new Diagnostics())) {
                    $base = $this->names->className($wireName);
                    $candidates[] = [$property, $base === null ? null : $registry->baseOf($ownerName) . $base, $wireName];
                }
            }
        }

        $additional = $owner->additionalProperties();
        if ($additional instanceof Schema && $registry->admits($additional)) {
            $candidates[] = [$additional, $registry->baseOf($ownerName) . 'AdditionalProperty', 'additionalProperties'];
        }

        $namespace = $ownerName->namespace();
        foreach ($candidates as [$schema, $baseName, $wireName]) {
            foreach ($this->inlines($schema, '', $diagnostics, $registry) as [$candidate, $suffix, $title]) {
                $this->declareInline($candidate, $title ?? ($baseName === null ? null : $baseName . $suffix), $wireName, $namespace, $source, $registry, $enums, $diagnostics);
            }
        }
    }

    /**
     * A named non-object schema is inlined where it is used, but its inline objects and enums need a name of their own:
     * `<Alias>Item`, `<Alias>Value`, `<Alias>Option<N>`.
     */
    private function hoistFromAlias(Schema $alias, string $aliasName, string $namespace, int $source, Registry $registry, EnumBuilder $enums, Diagnostics $diagnostics): void
    {
        $base = $this->names->className($aliasName);
        foreach ($this->inlines($alias, '', $diagnostics, $registry) as [$candidate, $suffix, $title]) {
            $this->declareInline($candidate, $title ?? ($base === null ? null : $base . $suffix), $aliasName, $namespace, $source, $registry, $enums, $diagnostics);
        }
    }

    /**
     * @param ?string $derived `<Parent><Property>…` or the member's title; null when the name has no usable characters
     */
    private function declareInline(Schema $candidate, ?string $derived, string $wireName, string $namespace, int $source, Registry $registry, EnumBuilder $enums, Diagnostics $diagnostics): void
    {
        // A schema reached by $ref from elsewhere may already carry its own name.
        if ($registry->isDeclared($candidate)) {
            return;
        }

        $short = $this->inlineName($candidate, $derived, $wireName, $diagnostics);
        $name = $short === null ? null : $registry->className($namespace, $short, $candidate);
        if (!$name instanceof ClassName || !$registry->claim($name, $candidate, $diagnostics) || !$this->declare($candidate, $name, $source, SchemaShape::isEnum($candidate), $registry, $enums, $diagnostics)) {
            $registry->abandon($candidate);
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
     * The inline objects and enums a property holds, looking through arrays (`…Item`), maps (`…Value`) and the members
     * of a union (`…Option<N>`, or the member's title).
     *
     * @return list<array{Schema, string, ?string}> schema, suffix of the derived name, name from the title
     */
    private function inlines(Schema $schema, string $suffix, Diagnostics $diagnostics, Registry $registry): array
    {
        // x-php-type maps the whole value to an existing class, so nothing under it is generated.
        if ($schema->extensions()->has('x-php-type')) {
            return [];
        }

        // A discriminated union becomes a base class only as a named schema (spec §5.3); inline it is a union type.
        if ((SchemaShape::isClass($schema) && !SchemaShape::isDiscriminated($schema)) || SchemaShape::isEnum($schema)) {
            return [[$schema, $suffix, null]];
        }

        if (SchemaShape::hasUnion($schema) && $schema->allOf() === [] && ($schema->propertyNames() === [] || SchemaShape::isDiscriminated($schema))) {
            return $this->unionMembers($schema, $suffix, $diagnostics, $registry);
        }

        $items = $schema->items();
        if ($items instanceof Schema) {
            return $this->inlines($items, $suffix . 'Item', $diagnostics, $registry);
        }

        $values = $schema->additionalProperties();

        return $values instanceof Schema ? $this->inlines($values, $suffix . 'Value', $diagnostics, $registry) : [];
    }

    /**
     * Members are numbered across `oneOf` and then `anyOf`, as the type mapper joins them into one union.
     *
     * @return list<array{Schema, string, ?string}>
     */
    private function unionMembers(Schema $schema, string $suffix, Diagnostics $diagnostics, Registry $registry): array
    {
        $found = [];
        foreach (array_merge($schema->oneOf(), $schema->anyOf()) as $index => $member) {
            if (SchemaShape::isDiscriminated($schema) && SchemaShape::isClass($member)) {
                $diagnostics->error(
                    'An inline object in a oneOf or anyOf with a discriminator is not generated: the discriminator mapping needs a $ref. Move it to components/schemas.',
                    $member->location(),
                );
                // Abandoned, so the type mapper does not report it a second time.
                $registry->abandon($member);

                continue;
            }

            foreach ($this->inlines($member, $suffix . 'Option' . ($index + 1), $diagnostics, $registry) as [$candidate, $candidateSuffix, $title]) {
                $found[] = [$candidate, $candidateSuffix, $candidate === $member ? $this->title($member) : $title];
            }
        }

        return $found;
    }

    private function title(Schema $member): ?string
    {
        $title = $member->hasKeyword('title') ? $member->keyword('title') : null;

        return is_string($title) ? $this->names->className($title) : null;
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
