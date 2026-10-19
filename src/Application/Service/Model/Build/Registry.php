<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Service\Model\Build;

use MSSTC4PHP\DtoGenerator\Domain\Builder\ClassBuilder;
use MSSTC4PHP\DtoGenerator\Domain\Builder\ClassLookup;
use MSSTC4PHP\DtoGenerator\Domain\Builder\Composition;
use MSSTC4PHP\DtoGenerator\Domain\Builder\Declarations;
use MSSTC4PHP\DtoGenerator\Domain\Builder\PropertyView;
use MSSTC4PHP\DtoGenerator\Domain\Builder\SchemaShape;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;
use MSSTC4PHP\DtoGenerator\Domain\Model\EnumModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\EnumType;
use MSSTC4PHP\DtoGenerator\Domain\Model\Identifier;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Discriminator;
use MSSTC4PHP\DtoGenerator\Domain\Schema\ResolvedSchema;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Schema;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaGraph;
use MSSTC4PHP\DtoGenerator\Domain\Schema\VariantOrigin;

/**
 * The names claimed during one build, the classes still to build and the enums already built.
 */
final class Registry
{
    /** @var array<string, ClassName> */
    private array $classes = [];

    /** @var array<string, EnumType> */
    private array $enums = [];

    /** @var array<string, true> */
    private array $skipped = [];

    /** @var array<string, true> */
    private array $abandoned = [];

    /** @var array<string, string> lower-cased FQCN → location that claimed it */
    private array $taken = [];

    /** @var array<string, bool> lower-cased FQCN of the views' names claimed */
    private array $views = [];

    /** @var list<array{Schema, ClassName, int}> */
    private array $planned = [];

    /** @var list<BuiltEnum> */
    private array $builtEnums = [];

    private ?View $view;

    private ?PropertyView $properties;

    /** @var array<string, string> FQCN of a class → its short name without the view's suffix */
    private array $bases = [];

    /** @var array<string, string> FQCN of a name not claimed yet → its short name without the view's suffix */
    private array $unclaimed = [];

    /** @var list<array{Schema, string}> */
    private array $rejected = [];

    /** @var array<string, list<Schema>> by the location of the declared schema */
    private array $companions = [];

    public function __construct(?View $view = null, ?PropertyView $properties = null)
    {
        $this->view = $view;
        $this->properties = $properties;
    }

    public function properties(): ?PropertyView
    {
        return $this->properties;
    }

    /**
     * Whether a property schema of the owner's class belongs to this build's view; every property does without a view
     * or in a class kept as one model.
     */
    public function admits(Schema $owner, Schema $property): bool
    {
        return !$this->properties instanceof PropertyView || !$this->properties->appliesTo($owner) || $this->properties->admits($property);
    }

    /**
     * The properties of the owner's composition that belong to the other view.
     *
     * @return array<array-key, string>
     */
    public function excluded(Schema $owner, Composition $composition): array
    {
        return $this->properties instanceof PropertyView && $this->properties->appliesTo($owner)
            ? $this->properties->excluded(ClassBuilder::kept($composition->propertySources()))
            : [];
    }

    /**
     * The class a schema gives in this build's view. Once claimed, the short name without the suffix is remembered, so
     * the classes of its inline schemas are named after the schema, not the view (`PetOwnerRead`, not `PetReadOwner`).
     */
    public function className(string $namespace, string $short, Schema $schema): ClassName
    {
        $name = ClassName::fromFqcn(($namespace === '' ? '' : $namespace . '\\') . ($this->view instanceof View ? $this->view->name($short, $schema) : $short));
        $this->unclaimed[$name->fqcn()] = $short;

        return $name;
    }

    /**
     * The short name of a class without the view's suffix.
     */
    public function baseOf(ClassName $class): string
    {
        return $this->bases[$class->fqcn()] ?? $class->shortName();
    }

    /**
     * The object schemas whose class name another schema took, with the location of that schema.
     *
     * @return list<array{Schema, string}>
     */
    public function rejected(): array
    {
        return $this->rejected;
    }

    /**
     * Where the schema that holds the name is, letter case ignored; null while nothing holds it.
     */
    public function holder(ClassName $name): ?string
    {
        return $this->taken[Identifier::asciiLower($name->fqcn())] ?? null;
    }

    /**
     * Whether a schema has claimed the name, letter case ignored.
     */
    public function isTaken(ClassName $name): bool
    {
        return isset($this->taken[Identifier::asciiLower($name->fqcn())]);
    }

    /**
     * Claims a class or enum name; a second schema claiming it (letter case ignored) is an error.
     */
    public function claim(ClassName $name, Schema $schema, Diagnostics $diagnostics): bool
    {
        $lower = Identifier::asciiLower($name->fqcn());
        $base = $this->unclaimed[$name->fqcn()] ?? $name->shortName();
        $suffixed = $base !== $name->shortName();
        if (isset($this->taken[$lower])) {
            if (SchemaShape::isClass($schema)) {
                $this->rejected[] = [$schema, $this->taken[$lower]];
            }

            $diagnostics->error(
                sprintf(
                    'Class %s is already generated from %s; set "x-php-class-name" on one of them%s%s.',
                    $name->fqcn(),
                    $this->taken[$lower],
                    VariantOrigin::isVariant($schema->location()) ? ' (on the schema this one is distributed from or on a referenced variant, or a "title" on an inline one)' : '',
                    $suffixed || ($this->views[$lower] ?? false) ? ', or change dto.readWriteSuffixes' : '',
                ),
                $schema->location(),
            );

            return false;
        }

        $this->taken[$lower] = $schema->location()->toString();
        $this->bases[$name->fqcn()] = $base;
        if ($suffixed) {
            $this->views[$lower] = true;
        }

        return true;
    }

    public function isDeclared(Schema $schema): bool
    {
        $key = $schema->location()->toString();

        return isset($this->classes[$key]) || isset($this->enums[$key]);
    }

    public function planClass(Schema $schema, ClassName $name, int $source): void
    {
        $this->classes[$schema->location()->toString()] = $name;
        $this->planned[] = [$schema, $name, $source];
    }

    public function addEnum(Schema $schema, EnumModel $enum, int $source): void
    {
        $this->enums[$schema->location()->toString()] = EnumType::of($enum);
        $this->builtEnums[] = new BuiltEnum($enum, $source);
    }

    /**
     * Another inline object of the same property gets the class of the declared one, which takes its properties too.
     */
    public function join(Schema $declared, Schema $companion): void
    {
        $name = $this->classes[$declared->location()->toString()] ?? null;
        if ($name instanceof ClassName) {
            $this->classes[$companion->location()->toString()] = $name;
            $this->companions[$declared->location()->toString()][] = $companion;
        }
    }

    /**
     * @return list<Schema>
     */
    public function companions(Schema $declared): array
    {
        return $this->companions[$declared->location()->toString()] ?? [];
    }

    /**
     * An inline schema whose declaration failed with a reported error; its property becomes mixed silently.
     */
    public function abandon(Schema $schema): void
    {
        $this->abandoned[$schema->location()->toString()] = true;
    }

    public function skip(Schema $schema): void
    {
        $this->skipped[$schema->location()->toString()] = true;
    }

    /**
     * The class planned at a position; the list grows while inline classes are found.
     *
     * @return array{Schema, ClassName, int}|null
     */
    public function plannedAt(int $index): ?array
    {
        return $this->planned[$index] ?? null;
    }

    /**
     * @return list<array{Schema, ClassName, int}>
     */
    public function planned(): array
    {
        return $this->planned;
    }

    /**
     * @return list<BuiltEnum>
     */
    public function enums(): array
    {
        return $this->builtEnums;
    }

    public function declarations(): Declarations
    {
        return new Declarations($this->classes, $this->enums, $this->skipped, $this->abandoned);
    }

    /**
     * The declarations once every class is composed, the only ones under which two classes meet: the schemas of the
     * classes that hold what they say, the classes each class extends, whose objects it holds too, and the variants of
     * discriminated unions, which a union gives a value of the discriminator their schemas do not tell.
     *
     * @param array<string, ClassName> $parents FQCN → the class it extends
     */
    public function declarationsWithHierarchy(SchemaGraph $graph, array $parents): Declarations
    {
        $declarations = $this->declarations();
        $schemas = $this->schemas();
        $ancestors = [];
        // Only classes whose schemas tell what they hold meet, also as subclasses: views and companions stay out.
        foreach (array_keys($schemas) as $fqcn) {
            $ancestors[$fqcn] = array_intersect_key($this->ancestors($fqcn, $parents), $schemas);
        }

        return $declarations->withHierarchy($schemas, $ancestors, $this->variants(new ClassLookup($graph, $declarations)));
    }

    /**
     * @param array<string, ClassName> $parents
     *
     * @return array<string, string> FQCNs by themselves
     */
    private function ancestors(string $fqcn, array $parents): array
    {
        $ancestors = [];
        $parent = $parents[$fqcn] ?? null;
        while ($parent instanceof ClassName && !isset($ancestors[$parent->fqcn()])) {
            $ancestors[$parent->fqcn()] = $parent->fqcn();
            $parent = $parents[$parent->fqcn()] ?? null;
        }

        return $ancestors;
    }

    /**
     * The classes a discriminated schema lists, by `$ref` or inline; its mapping may only name them.
     *
     * @return array<string, string> FQCNs by themselves
     */
    private function variants(ClassLookup $lookup): array
    {
        $variants = [];
        foreach ($this->planned as [$schema]) {
            foreach (SchemaShape::discriminatorOf($schema) instanceof Discriminator ? array_merge($schema->oneOf(), $schema->anyOf()) : [] as $member) {
                $target = $lookup->target($member);
                $class = $target instanceof ResolvedSchema ? $lookup->classBehind($target) : $lookup->classAt($member);
                if ($class instanceof ClassName) {
                    $variants[$class->fqcn()] = $class->fqcn();
                }
            }
        }

        return $variants;
    }

    /**
     * The schema of each class that holds what it says, by FQCN: a view leaves out properties, a companion adds them,
     * and the schema no longer tells which objects the class holds.
     *
     * @return array<string, Schema>
     */
    private function schemas(): array
    {
        $schemas = [];
        foreach (!$this->view instanceof View && !$this->properties instanceof PropertyView ? $this->planned : [] as [$schema, $name]) {
            if ($this->companions($schema) === []) {
                $schemas[$name->fqcn()] = $schema;
            }
        }

        return $schemas;
    }
}
