<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Service\Model\Build;

use MSSTC4PHP\DtoGenerator\Domain\Builder\Variants;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassKind;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;
use MSSTC4PHP\DtoGenerator\Domain\Model\Identifier;
use MSSTC4PHP\DtoGenerator\Domain\Model\PropertyModel;
use MSSTC4PHP\DtoGenerator\Domain\Shared\DefaultValue;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Json;

/**
 * Links the built classes into one inheritance forest (spec §5.3): variants extend their discriminated base, which
 * takes the properties all of them share; every parent stops being final.
 *
 * @phpstan-import-type JsonValue from Json
 */
final class Hierarchy
{
    /** @var array<string, ClassModel> */
    private array $models = [];

    /** @var array<string, ClassName|null> */
    private array $parents = [];

    private Diagnostics $diagnostics;

    /**
     * @param list<BuiltClass> $classes
     */
    private function __construct(array $classes, Diagnostics $diagnostics)
    {
        foreach ($classes as $class) {
            $fqcn = $class->model()->name()->fqcn();
            $this->models[$fqcn] = $class->model();
            $this->parents[$fqcn] = $class->model()->parent();
        }

        $this->diagnostics = $diagnostics;
    }

    /**
     * @param list<BuiltClass> $classes
     * @param array<string, Variants> $unions discriminated base FQCN → its variants
     *
     * @return list<BuiltClass>
     */
    public static function link(array $classes, array $unions, Diagnostics $diagnostics): array
    {
        $hierarchy = new self($classes, $diagnostics);
        $hierarchy->adoptVariants($unions);
        $hierarchy->breakCycles();
        foreach ($unions as $base => $variants) {
            $hierarchy->shareCommonProperties($base, $variants);
        }

        $hierarchy->settle($unions);
        $hierarchy->check();

        return array_map(
            static fn (BuiltClass $class): BuiltClass => new BuiltClass($hierarchy->models[$class->model()->name()->fqcn()], $class->source()),
            $classes,
        );
    }

    /**
     * @param array<string, Variants> $unions
     */
    private function adoptVariants(array $unions): void
    {
        foreach ($unions as $base => $variants) {
            $baseName = $this->models[$base]->name();
            foreach ($variants->classes() as $variant) {
                $fqcn = $variant->fqcn();
                $current = $this->parents[$fqcn] ?? null;
                if ($fqcn === $base) {
                    $this->diagnostics->error(sprintf('%s lists itself among its variants.', $base), $this->models[$base]->source());
                } elseif ($current === null) {
                    $this->parents[$fqcn] = $baseName;
                } elseif (!$current->equals($baseName)) {
                    $this->diagnostics->error(
                        sprintf('%s already extends %s, so it cannot also be a variant of %s.', $fqcn, $current->fqcn(), $base),
                        $this->models[$fqcn]->source(),
                    );
                }
            }
        }
    }

    /**
     * A class whose parent chain returns to it loses its parent, so later steps never loop.
     */
    private function breakCycles(): void
    {
        foreach (array_keys($this->models) as $fqcn) {
            $seen = [];
            for ($parent = $this->parents[$fqcn]; $parent instanceof ClassName && !isset($seen[$parent->fqcn()]); $parent = $this->parents[$parent->fqcn()] ?? null) {
                $seen[$parent->fqcn()] = $parent;
                if ($parent->fqcn() === $fqcn) {
                    $through = $seen[array_key_first($seen)];
                    $this->diagnostics->error(sprintf('Class %s extends itself through %s.', $fqcn, $through->fqcn()), $this->models[$fqcn]->source());
                    $this->parents[$fqcn] = null;
                }
            }
        }
    }

    /**
     * Properties every variant declares alike move to the base; one variant alone shares nothing.
     */
    private function shareCommonProperties(string $base, Variants $variants): void
    {
        $members = [];
        foreach ($variants->classes() as $variant) {
            $parent = $this->parents[$variant->fqcn()] ?? null;
            if ($parent instanceof ClassName && $parent->fqcn() === $base) {
                $members[] = $variant->fqcn();
            }
        }

        $own = $this->models[$base]->properties();
        foreach ($members as $member) {
            $this->models[$member] = $this->models[$member]->withProperties(...$this->matching($this->models[$member]->properties(), $own, false));
        }

        if (count($members) < 2) {
            return;
        }

        $common = $this->models[$members[0]]->properties();
        foreach ($members as $member) {
            $common = $this->matching($common, $this->models[$member]->properties(), true);
        }

        $this->models[$base] = $this->models[$base]->withProperties(...array_merge($own, $common));
        foreach ($members as $member) {
            $this->models[$member] = $this->models[$member]->withProperties(...$this->matching($this->models[$member]->properties(), $common, false));
        }
    }

    /**
     * @param array<string, Variants> $unions
     */
    private function settle(array $unions): void
    {
        $parents = [];
        foreach ($this->parents as $parent) {
            if ($parent instanceof ClassName) {
                $parents[$parent->fqcn()] = $parent;
            }
        }

        foreach ($this->models as $fqcn => $model) {
            $kind = isset($unions[$fqcn]) ? ClassKind::ABSTRACT : (isset($parents[$fqcn]) ? ClassKind::OPEN : ClassKind::FINAL);
            $discriminator = isset($unions[$fqcn]) ? $unions[$fqcn]->discriminator() : null;
            $this->models[$fqcn] = $model->withHierarchy(ClassKind::from($kind), $this->parents[$fqcn], $discriminator);
        }
    }

    private function check(): void
    {
        foreach ($this->models as $fqcn => $model) {
            $parent = $model->parent();
            if (!$parent instanceof ClassName || !isset($this->models[$parent->fqcn()])) {
                continue;
            }

            $base = $this->models[$parent->fqcn()];
            if (!$model->mutability()->equals($base->mutability())) {
                $this->diagnostics->error(
                    sprintf(
                        '%s is %s but its parent %s is %s; give both the same "x-dto-mutable".',
                        $fqcn,
                        $model->mutability()->value(),
                        $parent->fqcn(),
                        $base->mutability()->value(),
                    ),
                    $model->source(),
                );
            }

            $this->checkRedeclarations($model);
        }
    }

    private function checkRedeclarations(ClassModel $model): void
    {
        $names = [];
        $wireNames = [];
        for ($parent = $model->parent(); $parent instanceof ClassName && isset($this->models[$parent->fqcn()]); $parent = $this->parents[$parent->fqcn()]) {
            foreach ($this->models[$parent->fqcn()]->properties() as $property) {
                $names[Identifier::asciiLower($property->name())] = $parent->fqcn();
                $wireNames[$property->wireName()] = $parent->fqcn();
            }
        }

        $fqcn = $model->name()->fqcn();
        foreach ($model->properties() as $property) {
            $byWireName = $wireNames[$property->wireName()] ?? null;
            $byName = $names[Identifier::asciiLower($property->name())] ?? null;
            if ($byWireName !== null) {
                $this->diagnostics->error(sprintf('Property "%s" of %s is already declared by %s.', $property->wireName(), $fqcn, $byWireName), $property->source());
            } elseif ($byName !== null) {
                $this->diagnostics->error(
                    sprintf('Property "%s" of %s becomes $%s, which %s already declares; set "x-php-name".', $property->wireName(), $fqcn, $property->name(), $byName),
                    $property->source(),
                );
            }
        }
    }

    /**
     * The properties declared (or, with $declared false, not declared) exactly alike in the others: same names,
     * type, requirement and default.
     *
     * @param list<PropertyModel> $properties
     * @param list<PropertyModel> $others
     *
     * @return list<PropertyModel>
     */
    private function matching(array $properties, array $others, bool $declared): array
    {
        $result = [];
        foreach ($properties as $property) {
            $alike = false;
            foreach ($others as $other) {
                $alike = $alike || (
                    $other->name() === $property->name()
                    && $other->wireName() === $property->wireName()
                    && $other->type()->describe() === $property->type()->describe()
                    && $other->isRequired() === $property->isRequired()
                    && $this->defaultValue($other) === $this->defaultValue($property)
                );
            }

            if ($alike === $declared) {
                $result[] = $property;
            }
        }

        return $result;
    }

    /**
     * Only optional properties have a default, so properties alike in requirement differ at most in its value.
     *
     * @return JsonValue
     */
    private function defaultValue(PropertyModel $property)
    {
        $default = $property->default();

        return $default instanceof DefaultValue ? $default->value() : null;
    }
}
