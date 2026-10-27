<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Builder;

use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassKind;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;
use MSSTC4PHP\DtoGenerator\Domain\Model\DiscriminatorModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\EnumType;
use MSSTC4PHP\DtoGenerator\Domain\Model\Identifier;
use MSSTC4PHP\DtoGenerator\Domain\Model\NullableType;
use MSSTC4PHP\DtoGenerator\Domain\Model\PropertyModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\ScalarType;
use MSSTC4PHP\DtoGenerator\Domain\Model\TypeModel;
use MSSTC4PHP\DtoGenerator\Domain\Shared\DefaultValue;

/**
 * Links the built classes into one inheritance forest (spec §5.3): variants extend their discriminated base, which
 * takes the properties all of them share; every parent stops being final. Records the discriminator values each
 * concrete class accepts.
 */
final class Hierarchy
{
    /** @var array<string, ClassModel> */
    private array $models = [];

    /** @var array<string, ClassName|null> */
    private array $parents = [];

    private TypeMeet $meet;

    /** @var array<string, Variants> the union bases that are interfaces, by FQCN */
    private array $interfaces = [];

    private Superseded $superseded;

    private Merges $merges;

    private CollectionDeclines $declines;

    /** @var array<string, string> the variants of the unions, by FQCN */
    private array $variants = [];

    private Diagnostics $diagnostics;

    /**
     * @param list<ClassModel> $classes
     */
    private function __construct(array $classes, Diagnostics $diagnostics, TypeMeet $meet, Superseded $superseded, Merges $merges, CollectionDeclines $declines)
    {
        $this->declines = $declines;
        $this->meet = $meet;
        $this->superseded = $superseded;
        $this->merges = $merges;
        foreach ($classes as $class) {
            $fqcn = $class->name()->fqcn();
            $this->models[$fqcn] = $class;
            $this->parents[$fqcn] = $class->parent();
        }

        $this->diagnostics = $diagnostics;
    }

    /**
     * @param list<ClassModel> $classes
     * @param array<string, Variants> $unions discriminated base FQCN → its variants
     * @param Merges $merges takes the classes a next build may merge, as they redeclare a property PHP cannot
     *
     * @return list<ClassModel> the classes in the given order
     */
    public static function link(array $classes, array $unions, Diagnostics $diagnostics, TypeMeet $meet, Superseded $superseded, Merges $merges, ?CollectionDeclines $declines = null): array
    {
        $hierarchy = new self($classes, $diagnostics, $meet, $superseded, $merges, $declines ?? new CollectionDeclines());
        foreach ($unions as $variants) {
            foreach ($variants->classes() as $variant) {
                $hierarchy->variants[$variant->fqcn()] = $variant->fqcn();
            }
        }

        $hierarchy->adoptVariants($unions);
        $hierarchy->breakCycles();
        foreach (self::leavesFirst($unions) as $base) {
            if ($unions[$base]->isListed() && !isset($hierarchy->interfaces[$base])) {
                $hierarchy->shareCommonProperties($base, $unions[$base]);
            }
        }

        $hierarchy->settle($unions);
        $hierarchy->check();
        $hierarchy->models = InheritedRequirements::settle($hierarchy->models);
        $hierarchy->checkDiscriminators($unions);
        $hierarchy->selectVariants();

        return array_map(static fn (ClassModel $class): ClassModel => $hierarchy->models[$class->name()->fqcn()], $classes);
    }

    /**
     * A base that is itself a variant shares first, so what it keeps is final when its own base compares variants.
     *
     * @param array<string, Variants> $unions
     *
     * @return list<string>
     */
    private static function leavesFirst(array $unions): array
    {
        $order = [];
        $pending = $unions;
        while ($pending !== []) {
            $ready = array_filter($pending, static function (Variants $variants) use ($pending): bool {
                foreach ($variants->classes() as $variant) {
                    if (isset($pending[$variant->fqcn()])) {
                        return false;
                    }
                }

                return true;
            });
            // Bases that are variants of each other form a loop, which adoption reports; take them as they come.
            $next = $ready === [] ? $pending : $ready;
            foreach (array_keys($next) as $base) {
                $order[] = $base;
                unset($pending[$base]);
            }
        }

        return $order;
    }

    /**
     * @param array<string, Variants> $unions
     */
    private function adoptVariants(array $unions): void
    {
        // A union of nothing but its variants, one of which extends another class, is an interface instead: PHP gives
        // a class one parent, but any number of interfaces. One that a class extends through allOf stays a class.
        $extended = [];
        foreach (array_filter($this->parents) as $parent) {
            $extended[$parent->fqcn()] = $parent;
        }

        $mayBeInterface = [];
        foreach ($unions as $base => $variants) {
            if ($variants->isListed() && $this->models[$base]->properties() === [] && ($this->parents[$base] ?? null) === null && !isset($extended[$base])) {
                $mayBeInterface[$base] = $variants;
            }
        }

        foreach ($this->adoptionOrder($unions, $mayBeInterface) as $base) {
            $variants = $unions[$base];
            // The variants without a parent become the base's children, so one that descends from them reaches it.
            $adopted = [$base];
            foreach ($variants->classes() as $variant) {
                if ($variant->fqcn() !== $base && ($this->parents[$variant->fqcn()] ?? null) === null && !isset($this->interfaces[$variant->fqcn()])) {
                    $adopted[] = $variant->fqcn();
                }
            }

            $foreign = false;
            foreach ($variants->classes() as $variant) {
                $fqcn = $variant->fqcn();
                if ($fqcn === $base) {
                    $this->diagnostics->error(sprintf('%s lists itself among its variants.', $base), $this->models[$base]->source());
                } else {
                    $ancestors = $this->ancestors($fqcn);
                    $foreign = $foreign || isset($this->interfaces[$fqcn]) || $ancestors !== [] && array_intersect($ancestors, $adopted) === [];
                }
            }

            if ($foreign && isset($mayBeInterface[$base]) && ($this->parents[$base] ?? null) === null) {
                $this->interfaces[$base] = $variants;

                continue;
            }

            foreach (array_slice($adopted, 1) as $fqcn) {
                $this->parents[$fqcn] = $this->models[$base]->name();
            }
        }

        // Only now are all variants adopted, so one may descend from the base through another.
        foreach (array_diff_key($unions, $this->interfaces) as $base => $variants) {
            foreach ($variants->classes() as $variant) {
                $fqcn = $variant->fqcn();
                $parent = $this->parents[$fqcn] ?? null;
                if (isset($this->interfaces[$fqcn])) {
                    $this->diagnostics->error(
                        sprintf('%s is an interface, as a variant of it extends another class, so it cannot also be a variant of %s.', $fqcn, $base),
                        $this->models[$fqcn]->source(),
                    );
                } elseif ($fqcn !== $base && $parent instanceof ClassName && !in_array($base, $this->ancestors($fqcn), true)) {
                    $this->diagnostics->error(
                        sprintf('%s already extends %s, so it cannot also be a variant of %s.', $fqcn, $parent->fqcn(), $base),
                        $this->models[$fqcn]->source(),
                    );
                }
            }
        }
    }

    /**
     * Inner unions before the unions that list them; of those ready, unions that stay classes first, as they take a
     * variant they share first, then those a pending class lists, so they are decided before what shares with them, then
     * the rest; each by name. The order of the schemas plays no part, so the same unions always give the same classes
     * and interfaces.
     *
     * @param array<string, Variants> $unions
     * @param array<string, Variants> $mayBeInterface
     *
     * @return list<string>
     */
    private function adoptionOrder(array $unions, array $mayBeInterface): array
    {
        ksort($unions, SORT_STRING);
        $variantsOf = [];
        foreach ($unions as $base => $variants) {
            $variantsOf[$base] = [];
            foreach ($variants->classes() as $variant) {
                $variantsOf[$base][$variant->fqcn()] = $variant;
            }
        }

        $order = [];
        $pending = $variantsOf;
        while ($pending !== []) {
            $inner = array_filter($pending, static fn (array $own): bool => array_intersect_key($own, $pending) === []);
            $next = array_key_first(array_diff_key($inner, $mayBeInterface)) ?? $this->listedByAClass($inner, $pending, $mayBeInterface);
            // Unions that list each other, which adoption reports, are taken by name.
            $next ??= array_key_first($inner !== [] ? $inner : $pending);
            $order[] = $next;
            unset($pending[$next]);
        }

        return $order;
    }

    /**
     * The first of the unions that a pending union that stays a class lists, directly or through other pending unions.
     *
     * @param array<string, array<string, ClassName>> $inner
     * @param array<string, array<string, ClassName>> $pending variants by union, by FQCN
     * @param array<string, Variants> $mayBeInterface
     */
    private function listedByAClass(array $inner, array $pending, array $mayBeInterface): ?string
    {
        $listed = [];
        $queue = array_keys(array_diff_key($pending, $mayBeInterface));
        while ($queue !== []) {
            foreach (array_keys(array_intersect_key($pending[array_shift($queue)], $pending)) as $union) {
                if (!isset($listed[$union])) {
                    $listed[$union] = $union;
                    $queue[] = $union;
                }
            }
        }

        return array_key_first(array_intersect_key($inner, $listed));
    }

    /**
     * The chain of parents of a class, nearest first, as far as it goes before returning to a class it has passed.
     *
     * @return list<string>
     */
    private function ancestors(string $fqcn): array
    {
        $ancestors = [];
        for ($parent = $this->parents[$fqcn] ?? null; $parent instanceof ClassName && !in_array($parent->fqcn(), $ancestors, true); $parent = $this->parents[$parent->fqcn()] ?? null) {
            $ancestors[] = $parent->fqcn();
        }

        return $ancestors;
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
            $this->models[$member] = $this->models[$member]->withProperties(...$this->unshared($this->models[$member]->properties(), $own));
        }

        if (count($members) < 2) {
            return;
        }

        $common = [];
        $taken = [];
        $requirements = [];
        foreach ($this->models[$members[0]]->properties() as $candidate) {
            $shared = $this->shared($candidate, $members, in_array($candidate->wireName(), $variants->required(), true));
            if (!$shared instanceof SharedProperty) {
                continue;
            }

            $common[] = $shared->base();
            foreach ($shared->declarations() as $member => $match) {
                $taken[$member][] = $match;
            }

            foreach ($shared->requiring() as $member) {
                $requirements[$member][] = $candidate->wireName();
            }
        }

        $this->models[$base] = $this->models[$base]->withProperties(...array_merge($own, $common));
        foreach ($members as $member) {
            $model = $this->models[$member];
            $kept = array_filter($model->properties(), static fn (PropertyModel $property): bool => !in_array($property, $taken[$member] ?? [], true));
            $this->models[$member] = $model->withProperties(...$kept)->withRequiredInherited(...array_merge($model->requiredInherited(), $requirements[$member] ?? []));
        }
    }

    /**
     * The property every variant declares alike, for the base, with each variant's declaration and the variants that
     * go on requiring it. A property the base's `required` names ($required) is required of a variant that extends the
     * base through allOf, not of one only listed: the variants may differ in requiring it, so the base takes the
     * optional declaration, which they then must share, and those requiring it keep doing so.
     *
     * @param non-empty-list<string> $members
     */
    private function shared(PropertyModel $candidate, array $members, bool $required): ?SharedProperty
    {
        $found = [];
        foreach ($members as $member) {
            foreach ($this->models[$member]->properties() as $other) {
                if ($required ? $this->alikeButForRequirement($candidate, $other) : $this->declaredAlike($candidate, [$other])) {
                    $found[$member] = $other;

                    continue 2;
                }
            }

            return null;
        }

        $optional = array_filter($found, static fn (PropertyModel $match): bool => !$match->isRequired());
        if ($optional === []) {
            return new SharedProperty($candidate, $found, []);
        }

        $declaration = reset($optional);
        foreach ($optional as $match) {
            if (!$this->declaredAlike($declaration, [$match])) {
                return null;
            }
        }

        // The type and default of the optional declarations; where it was written, as for any other shared property,
        // that of the first variant.
        $base = new PropertyModel(
            $candidate->name(),
            $candidate->wireName(),
            $declaration->type(),
            false,
            $declaration->default(),
            $candidate->doc(),
            $candidate->source(),
            $candidate->attributes(),
            $candidate->isAdditionalProperties(),
        );

        return new SharedProperty($base, $found, array_keys(array_diff_key($found, $optional)));
    }

    /**
     * The same names and type but for null, which an optional property admits as a missing value.
     */
    private function alikeButForRequirement(PropertyModel $property, PropertyModel $other): bool
    {
        return $other->name() === $property->name()
            && $other->wireName() === $property->wireName()
            && NullableType::of($other->type())->describe() === NullableType::of($property->type())->describe();
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
            $kind = isset($this->interfaces[$fqcn]) ? ClassKind::INTERFACE : (isset($unions[$fqcn]) ? ClassKind::ABSTRACT : (isset($parents[$fqcn]) ? ClassKind::OPEN : ClassKind::FINAL));
            $discriminator = isset($unions[$fqcn]) ? $unions[$fqcn]->discriminator() : null;
            $this->models[$fqcn] = $model->withHierarchy(ClassKind::from($kind), $this->parents[$fqcn], $discriminator);
        }

        foreach ($this->interfaces as $base => $variants) {
            foreach ($variants->classes() as $variant) {
                $fqcn = $variant->fqcn();
                // A union that lists itself was reported while adopting.
                if ($fqcn === $base) {
                    continue;
                }

                // Unions that list each other would give interfaces that extend each other.
                if (in_array($fqcn, $this->interfacesOf($base), true)) {
                    $this->diagnostics->error(sprintf('Interface %s would extend itself through %s.', $fqcn, $base), $this->models[$fqcn]->source());

                    continue;
                }

                $this->models[$fqcn] = $this->models[$fqcn]->withInterfaces(...array_merge($this->models[$fqcn]->interfaces(), [$this->models[$base]->name()]));
            }
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

            $this->models[$fqcn] = $this->checkRedeclarations($model);
        }
    }

    /**
     * @param array<string, Variants> $unions
     */
    private function checkDiscriminators(array $unions): void
    {
        foreach ($unions as $base => $variants) {
            $discriminator = $variants->discriminator();
            if ($discriminator === null) {
                continue;
            }

            // A base its subclasses extend through allOf declares the discriminator property for all of them.
            if (!$variants->isListed()) {
                $model = $this->models[$base];
                if (!in_array($discriminator->propertyName(), $this->lineage($model)[1], true)) {
                    $this->diagnostics->warning(
                        sprintf('%s has no property "%s", which its discriminator reads.', $base, $discriminator->propertyName()),
                        $model->source(),
                    );
                }

                continue;
            }

            foreach ($variants->classes() as $variant) {
                // An interface variant stands for the classes that implement it.
                $members = $this->models[$variant->fqcn()]->kind()->isInterface() ? $this->implementers($variant->fqcn()) : [$variant->fqcn()];
                foreach ($members as $member) {
                    $model = $this->models[$member];
                    [$ancestors, $wireNames] = $this->lineage($model);
                    // A variant that could not be adopted was reported already.
                    $joined = isset($ancestors[$base]) || in_array($base, $this->interfacesOf($member), true);
                    if ($joined && !in_array($discriminator->propertyName(), $wireNames, true)) {
                        $this->diagnostics->warning(
                            sprintf('Variant %s has no property "%s", which the discriminator reads.', $member, $discriminator->propertyName()),
                            $model->source(),
                        );
                    }
                }
            }
        }
    }

    /**
     * The ancestors of a class, and the wire names it declares or inherits.
     *
     * @return array{array<string, ClassModel>, list<string>}
     */
    private function lineage(ClassModel $model): array
    {
        $wireNames = [];
        $seen = [];
        for ($class = $model; $class instanceof ClassModel && !isset($seen[$class->name()->fqcn()]); $class = $this->parentOf($class)) {
            $seen[$class->name()->fqcn()] = $class;
            foreach ($class->properties() as $property) {
                $wireNames[] = $property->wireName();
            }
        }

        unset($seen[$model->name()->fqcn()]);

        return [$seen, $wireNames];
    }

    private function parentOf(ClassModel $model): ?ClassModel
    {
        $parent = $model->parent();

        return $parent instanceof ClassName ? $this->models[$parent->fqcn()] ?? null : null;
    }

    /**
     * Whether the child's declaration only narrows the values of the parent's property, which constraints on the
     * parent's type can check: the same name and default, and a type that differs by null, by a refinement of the
     * same scalar or by the cases of an enum. Every schema applies, so an enum over an enum of some values in common
     * leaves those. A class, a date or a list over a scalar or a map is no such narrowing.
     */
    private function narrowsValues(PropertyModel $parent, PropertyModel $child): bool
    {
        $wide = $parent->type() instanceof NullableType ? $parent->type()->inner() : $parent->type();
        $narrow = $child->type() instanceof NullableType ? $child->type()->inner() : $child->type();
        $sameKind = $wide->describe() === $narrow->describe()
            || ($wide instanceof ScalarType && $narrow instanceof ScalarType && $wide->kind() === $narrow->kind())
            || $narrow instanceof EnumType;
        $overlap = $wide instanceof EnumType && $narrow instanceof EnumType && $wide->backing()->value() === $narrow->backing()->value()
            && array_intersect_key($wide->cases(), $narrow->cases()) !== [];

        return $sameKind && $this->declaresAlike($parent, $child)
            && ($overlap || $this->meet->admits($parent->type(), $child->type()));
    }

    private function isOrExtendsAVariant(ClassModel $model): bool
    {
        for ($class = $model->name(); $class instanceof ClassName; $class = $this->parents[$class->fqcn()] ?? null) {
            if (isset($this->variants[$class->fqcn()])) {
                return true;
            }
        }

        return false;
    }

    /**
     * A child type whose meet is the parent's says nothing PHP holds beyond it, as a narrowing does.
     */
    private function keepsTheParents(PropertyModel $inherited, PropertyModel $child, ?TypeModel $meet): bool
    {
        return $this->narrowsValues($inherited, $child) || ($meet instanceof TypeModel && $meet->describe() === $inherited->type()->describe());
    }

    /**
     * Whether a merge could join the two: the same PHP name and default.
     */
    private function declaresAlike(PropertyModel $parent, PropertyModel $child): bool
    {
        return $parent->name() === $child->name() && $this->setDefault($parent) === $this->setDefault($child);
    }

    /**
     * The default a schema sets; a null one only stands for a missing value.
     */
    private function setDefault(PropertyModel $property): string
    {
        $default = $property->default();

        return $default instanceof DefaultValue && $default->value() !== null ? $default->toJson() : '';
    }

    /**
     * Whether the class or any ancestor declines a collection for the property: an ancestor that keeps its parent's
     * declaration still declined.
     */
    private function declinesAlong(ClassModel $model, string $wireName): bool
    {
        for ($class = $model->name(); $class instanceof ClassName; $class = $this->parents[$class->fqcn()] ?? null) {
            if ($this->declines->declinedBy($class, $wireName)) {
                return true;
            }
        }

        return false;
    }

    /**
     * PHP lets no subclass declare its parent's property again: one the schema only narrows stays the parent's.
     */
    private function checkRedeclarations(ClassModel $model): ClassModel
    {
        $names = [];
        $wireNames = [];
        for ($parent = $model->parent(); $parent instanceof ClassName && isset($this->models[$parent->fqcn()]); $parent = $this->parents[$parent->fqcn()]) {
            foreach ($this->models[$parent->fqcn()]->properties() as $property) {
                $names[Identifier::asciiLower($property->name())] = $parent->fqcn();
                $wireNames[$property->wireName()] = [$parent->fqcn(), $property];
            }
        }

        $fqcn = $model->name()->fqcn();
        $kept = [];
        $required = $model->requiredInherited();
        foreach ($model->properties() as $property) {
            [$byWireName, $inherited] = $wireNames[$property->wireName()] ?? [null, null];
            $byName = $names[Identifier::asciiLower($property->name())] ?? null;
            $kept[] = $property;
            $meet = null;
            if ($inherited instanceof PropertyModel && $this->declaresAlike($inherited, $property)) {
                $meet = $this->meet->of($inherited->type(), $property->type());
            }

            $declined = is_string($byWireName) && $this->declinesAlong($model, $property->wireName());
            if ($meet instanceof TypeModel && $declined && CollectionDeclines::isCollection($meet)) {
                $this->diagnostics->error(ClassBuilder::declined($property->wireName(), $model->name(), $meet), $property->source());

                continue;
            }

            if ($inherited instanceof PropertyModel && $this->keepsTheParents($inherited, $property, $meet)) {
                array_pop($kept);
                $this->superseded->add($property->type());
                if ($property->isRequired()) {
                    $required[] = $property->wireName();
                }
                $this->diagnostics->warning(
                    sprintf(
                        'Property "%s" of %s %s the one %s declares, whose type %s the class keeps.',
                        $property->wireName(),
                        $fqcn,
                        $this->meet->admits($inherited->type(), $property->type()) ? 'narrows' : 'shares values with',
                        $byWireName,
                        $inherited->type()->describe(),
                    ),
                    $property->source(),
                );
            } elseif ($inherited instanceof PropertyModel && $this->declaresAlike($inherited, $property) && $this->isOrExtendsAVariant($model)) {
                // A parent that selects a variant must stay, so the variant cannot merge its members instead; whether the
                // types meet is not asked, as a read or write model of the narrower class no longer extends the parent's.
                array_pop($kept);
                $this->superseded->add($property->type());
                if ($property->isRequired()) {
                    $required[] = $property->wireName();
                }
                $this->diagnostics->warning(
                    sprintf(
                        'Property "%s" of %s is %s, which %s; the class is a variant, which keeps its parent, so it keeps %s.',
                        $property->wireName(),
                        $fqcn,
                        $property->type()->describe(),
                        $meet instanceof TypeModel
                            ? sprintf('PHP lets no subclass of %s declare', $byWireName)
                            : sprintf('has no value in common with the type %s declares, unless the two are classes of different read or write models', $byWireName),
                        $inherited->type()->describe(),
                    ),
                    $property->source(),
                );
            } elseif ($byWireName !== null) {
                $this->diagnostics->error(sprintf('Property "%s" of %s is already declared by %s.', $property->wireName(), $fqcn, $byWireName), $property->source());
                if ($meet instanceof TypeModel) {
                    $this->merges->add($model->source(), $property->wireName(), $property->source());
                }
            } elseif ($byName !== null) {
                $this->diagnostics->error(
                    sprintf('Property "%s" of %s becomes $%s, which %s already declares; set "x-php-name", in the document or "propertyNames" of the source.', $property->wireName(), $fqcn, $property->name(), $byName),
                    $property->source(),
                );
            }
        }

        // The class's own requirement of a property it keeps from the parent stays, as one written beside the parent's $ref.
        return count($kept) === count($model->properties()) ? $model : $model->withProperties(...$kept)->withRequiredInherited(...$required);
    }

    /**
     * @param list<PropertyModel> $properties
     * @param list<PropertyModel> $others
     *
     * @return list<PropertyModel> the properties the others do not declare alike
     */
    private function unshared(array $properties, array $others): array
    {
        $result = [];
        foreach ($properties as $property) {
            if (!$this->declaredAlike($property, $others)) {
                $result[] = $property;
            }
        }

        return $result;
    }

    /**
     * Same names, type, requirement and default.
     *
     * @param list<PropertyModel> $others
     */
    private function declaredAlike(PropertyModel $property, array $others): bool
    {
        foreach ($others as $other) {
            if ($other->name() === $property->name()
                && $other->wireName() === $property->wireName()
                && $other->type()->describe() === $property->type()->describe()
                && $other->isRequired() === $property->isRequired()
                && $this->defaultOf($other) === $this->defaultOf($property)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The default as JSON writes it: `1` and `1.0` of a number are the same default.
     */
    private function defaultOf(PropertyModel $property): string
    {
        $default = $property->default();

        return $default instanceof DefaultValue ? $default->toJson() : '';
    }

    /**
     * Only now are kinds and parents final, so an abstract ancestor can be told from an open one.
     */
    private function selectVariants(): void
    {
        foreach (SelectingValues::of($this->models, $this->diagnostics) as $fqcn => $values) {
            $this->models[$fqcn] = $this->models[$fqcn]->withDiscriminatorValues(...$values);
        }

        // Every class in a chain shares its discriminated properties: a mutator inherited from an ancestor, or
        // declared on an intermediate base, would bypass the check as much as one on the variant.
        $discriminated = [];
        foreach (array_keys($this->models) as $fqcn) {
            $chain = $this->chain($fqcn);
            $names = [];
            foreach ($chain as $member) {
                foreach (array_merge([$member], $this->interfacesOf($member)) as $reader) {
                    $discriminator = $this->models[$reader]->discriminator();
                    if ($discriminator instanceof DiscriminatorModel) {
                        $names[] = $discriminator->propertyName();
                    }
                }
            }

            foreach ($chain as $member) {
                $discriminated[$member] = array_merge($discriminated[$member] ?? [], $names);
            }
        }

        foreach ($discriminated as $fqcn => $names) {
            $this->models[$fqcn] = $this->models[$fqcn]->withDiscriminatedProperties(...$names);
        }

        // A class outside every discriminated chain still changes what its parent, inside one, may not.
        foreach ($this->models as $fqcn => $model) {
            $parent = $model->parent();
            if ($parent instanceof ClassName && isset($this->models[$parent->fqcn()])) {
                $inherited = [];
                foreach (array_slice($this->chain($fqcn), 0, -1) as $ancestor) {
                    foreach ($this->models[$ancestor]->properties() as $property) {
                        $inherited[] = $property->wireName();
                    }
                }

                $restored = array_diff($this->models[$parent->fqcn()]->discriminatedProperties(), $model->discriminatedProperties());
                $this->models[$fqcn] = $model->withRestoredMutators(...array_intersect($restored, $inherited));
            }
        }
    }

    /**
     * @return list<string> the classes that implement an interface, directly or through one that extends it
     */
    private function implementers(string $interface): array
    {
        $found = [];
        foreach ($this->models as $fqcn => $model) {
            if (!$model->kind()->isInterface() && in_array($interface, $this->interfacesOf($fqcn), true)) {
                $found[] = $fqcn;
            }
        }

        return $found;
    }

    /**
     * @return list<string> the interfaces a class implements and those they extend
     */
    private function interfacesOf(string $fqcn): array
    {
        // settle() never lets an interface extend itself, so the walk ends.
        $found = [];
        foreach ($this->models[$fqcn]->interfaces() as $interface) {
            array_push($found, $interface->fqcn(), ...$this->interfacesOf($interface->fqcn()));
        }

        return $found;
    }

    /**
     * @return list<string> the class and its ancestors, root first
     */
    private function chain(string $fqcn): array
    {
        $chain = [$fqcn];
        // breakCycles() has run; the bound keeps a broken invariant from hanging the run.
        for ($parent = $this->models[$fqcn]->parent(); $parent instanceof ClassName && isset($this->models[$parent->fqcn()]) && count($chain) < count($this->models); $parent = $this->models[$parent->fqcn()]->parent()) {
            $chain[] = $parent->fqcn();
        }

        return array_reverse($chain);
    }
}
