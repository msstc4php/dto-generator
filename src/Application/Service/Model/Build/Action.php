<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Service\Model\Build;

use MSSTC4PHP\DtoGenerator\Domain\Builder\ClassBuilder;
use MSSTC4PHP\DtoGenerator\Domain\Builder\EnumBuilder;
use MSSTC4PHP\DtoGenerator\Domain\Builder\ExtensionVocabulary;
use MSSTC4PHP\DtoGenerator\Domain\Builder\NameResolver;
use MSSTC4PHP\DtoGenerator\Domain\Builder\RequiredCycles;
use MSSTC4PHP\DtoGenerator\Domain\Builder\SchemaShape;
use MSSTC4PHP\DtoGenerator\Domain\Builder\TypeMapper;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;
use MSSTC4PHP\DtoGenerator\Domain\Model\EnumModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\Identifier;
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
            $unsupported = SchemaShape::unsupportedKeyword($schema);
            if ($isClass || $isEnum || $unsupported !== null) {
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

            if (!$isClass && !$isEnum) {
                if ($unsupported !== null && $resolved->isSelected()) {
                    $diagnostics->warning(
                        sprintf('"%s" is not supported yet, so no class is generated for "%s".', $unsupported, $resolved->name()),
                        $schema->location(),
                    );
                }

                continue;
            }

            $short = $this->shortName($schema, $resolved->name(), $diagnostics);
            $name = $short === null ? null : ClassName::fromFqcn($config->sources()[$source]->namespace() . '\\' . $short);
            if ($name instanceof ClassName && $registry->claim($name, $schema, $diagnostics)) {
                $this->declare($schema, $name, $source, $isEnum, $registry, $enums, $diagnostics);
            }
        }

        // Inline classes are planned while walking, so the walk reaches inline objects nested in inline objects.
        for ($index = 0; ($planned = $registry->plannedAt($index)) !== null; $index++) {
            $this->hoist($planned[0], $planned[1], $planned[2], $registry, $enums, $diagnostics);
        }

        $builder = new ClassBuilder(
            $this->names,
            new TypeMapper($input->graph(), $registry->declarations(), $input->target(), $config->formats()),
            $input->target(),
        );
        $built = [];
        foreach ($registry->planned() as [$schema, $name, $source]) {
            $built[] = new BuiltClass($builder->build($name, $schema, $diagnostics), $source);
        }

        RequiredCycles::check(array_map(static fn (BuiltClass $class): ClassModel => $class->model(), $built), $diagnostics);

        return new Output($built, $diagnostics, $registry->enums());
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
    private function hoist(Schema $owner, ClassName $ownerName, int $source, Registry $registry, EnumBuilder $enums, Diagnostics $diagnostics): void
    {
        $candidates = [];
        foreach ($owner->propertyNames() as $wireName) {
            $property = $owner->requireProperty($wireName);
            // The class builder reports a malformed x-php-skip; here it only decides whether to look further.
            if (!ClassBuilder::isSkipped($property, new Diagnostics())) {
                $base = $this->names->className($wireName);
                $candidates[] = [$property, $base === null ? null : $ownerName->shortName() . $base, $wireName];
            }
        }

        $additional = $owner->additionalProperties();
        if ($additional instanceof Schema) {
            $candidates[] = [$additional, $ownerName->shortName() . 'AdditionalProperty', null];
        }

        foreach ($candidates as [$schema, $baseName, $wireName]) {
            $inline = $this->inline($schema, '');
            // A schema reached by $ref from elsewhere may already carry its own name.
            if ($inline === null || $registry->isDeclared($inline[0])) {
                continue;
            }

            [$candidate, $suffix] = $inline;
            $short = $this->inlineName($candidate, $baseName === null ? null : $baseName . $suffix, (string) $wireName, $diagnostics);
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
        if (SchemaShape::isClass($schema) || SchemaShape::isEnum($schema)) {
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
