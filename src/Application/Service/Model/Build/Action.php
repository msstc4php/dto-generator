<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Service\Model\Build;

use MSSTC4PHP\DtoGenerator\Domain\Builder\ClassBuilder;
use MSSTC4PHP\DtoGenerator\Domain\Builder\ExtensionVocabulary;
use MSSTC4PHP\DtoGenerator\Domain\Builder\NameResolver;
use MSSTC4PHP\DtoGenerator\Domain\Builder\RequiredCycles;
use MSSTC4PHP\DtoGenerator\Domain\Builder\SchemaShape;
use MSSTC4PHP\DtoGenerator\Domain\Builder\TypeMapper;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;
use MSSTC4PHP\DtoGenerator\Domain\Model\Identifier;
use MSSTC4PHP\DtoGenerator\Domain\Schema\ResolvedSchema;

/**
 * Turns the schema graph into the IR: one class per object schema, including schemas only reached by $ref,
 * in the namespace of the source that owns them.
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
        /** @var array<string, ClassName> $classes */
        $classes = [];
        /** @var array<string, true> $skipped */
        $skipped = [];
        /** @var array<string, string> $taken lower-cased FQCN → location that claimed it */
        $taken = [];
        /** @var list<array{ResolvedSchema, ClassName, int}> $planned */
        $planned = [];

        foreach ($input->graph()->all() as $resolved) {
            $source = $resolved->source();
            $schema = $resolved->schema();
            $key = $resolved->location()->toString();
            $isClass = SchemaShape::isClass($schema);
            $unsupported = SchemaShape::unsupportedKeyword($schema);
            ExtensionVocabulary::check(
                $schema,
                $isClass || $unsupported !== null ? ExtensionVocabulary::CLASS_SCHEMA : ExtensionVocabulary::ALIAS_SCHEMA,
                $diagnostics,
            );
            if ($source === null) {
                continue;
            }

            if (ClassBuilder::isSkipped($schema, $diagnostics)) {
                $skipped[$key] = true;

                continue;
            }

            if (!$isClass) {
                if ($unsupported !== null && $resolved->isSelected()) {
                    $diagnostics->warning(
                        sprintf('"%s" is not supported yet, so no class is generated for "%s".', $unsupported, $resolved->name()),
                        $schema->location(),
                    );
                }

                continue;
            }

            $short = $this->shortName($resolved, $diagnostics);
            if ($short === null) {
                continue;
            }

            $name = ClassName::fromFqcn($config->sources()[$source]->namespace() . '\\' . $short);
            $lower = Identifier::asciiLower($name->fqcn());
            if (isset($taken[$lower])) {
                $diagnostics->error(
                    sprintf('Class %s is already generated from %s; set "x-php-class-name" on one of them.', $name->fqcn(), $taken[$lower]),
                    $schema->location(),
                );

                continue;
            }

            $taken[$lower] = $key;
            $classes[$key] = $name;
            $planned[] = [$resolved, $name, $source];
        }

        $builder = new ClassBuilder(
            $this->names,
            new TypeMapper($input->graph(), $classes, $skipped, $input->target(), $config->formats()),
            $input->target(),
        );
        $built = [];
        foreach ($planned as [$resolved, $name, $source]) {
            $built[] = new BuiltClass($builder->build($name, $resolved, $diagnostics), $source);
        }

        RequiredCycles::check(array_map(static fn (BuiltClass $class): ClassModel => $class->model(), $built), $diagnostics);

        return new Output($built, $diagnostics);
    }

    private function shortName(ResolvedSchema $resolved, Diagnostics $diagnostics): ?string
    {
        $schema = $resolved->schema();
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

        $name = $this->names->className($resolved->name());
        if ($name === null) {
            $diagnostics->error(
                sprintf('Schema name "%s" has no usable characters; set "x-php-class-name".', $resolved->name()),
                $schema->location(),
            );
        }

        return $name;
    }
}
