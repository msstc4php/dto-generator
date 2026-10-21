<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Builder;

use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassType;
use MSSTC4PHP\DtoGenerator\Domain\Model\NullableType;
use MSSTC4PHP\DtoGenerator\Domain\Model\PropertyModel;
use MSSTC4PHP\DtoGenerator\Domain\Shared\DefaultValue;

/**
 * Keeps an empty object default of a class-typed property, emitted as `new X()`, only where X is a generated class
 * whose constructor takes no arguments and whose defaults do not lead back to it; any other falls back to null.
 */
final class ObjectDefaults
{
    /** @var array<string, ClassModel> */
    private array $classes = [];

    /** @var array<string, list<PropertyModel>> */
    private array $inherited;

    /** @var array<string, string> property location → the warning that refuses its default */
    private array $refused = [];

    /** @var array<string, bool> FQCN → whether evaluating its constructor is done (true) or under way (false) */
    private array $visited = [];

    /**
     * @param list<ClassModel> $classes
     * @param array<string, list<PropertyModel>> $inherited
     */
    private function __construct(array $classes, array $inherited)
    {
        foreach ($classes as $class) {
            $this->classes[$class->name()->fqcn()] = $class;
        }

        $this->inherited = $inherited;
    }

    /**
     * @param list<ClassModel> $classes
     * @param array<string, list<PropertyModel>> $inherited FQCN → the properties it inherits
     *
     * @return list<ClassModel> in the given order
     */
    public static function resolve(array $classes, array $inherited, Diagnostics $diagnostics): array
    {
        $defaults = new self($classes, $inherited);
        foreach (array_keys($defaults->classes) as $fqcn) {
            if (!isset($defaults->visited[$fqcn])) {
                $defaults->visit($fqcn);
            }
        }

        return array_map(static fn (ClassModel $class): ClassModel => $defaults->withoutRefusedDefaults($class, $diagnostics), $classes);
    }

    /**
     * Follows the defaults that `new X()` evaluates: one reaching a constructor still under way would never end.
     */
    private function visit(string $fqcn): void
    {
        $this->visited[$fqcn] = false;
        foreach ($this->constructorProperties($fqcn) as $property) {
            $target = $this->created($property);
            $at = $property->source()->toString();
            if ($target === null || isset($this->refused[$at])) {
                continue;
            }

            $refusal = $this->refusalFor($target);
            if ($refusal !== null) {
                $this->refused[$at] = $refusal;
            } elseif (!isset($this->visited[$target])) {
                $this->visit($target);
            }
        }

        $this->visited[$fqcn] = true;
    }

    private function refusalFor(string $target): ?string
    {
        $class = $this->classes[$target] ?? null;
        if (!$class instanceof ClassModel) {
            return sprintf('A default for %s cannot be a PHP constant expression; null is used instead.', $target);
        }

        $reason = $this->unconstructible($class);
        if ($reason === null && ($this->visited[$target] ?? true) === false) {
            $reason = sprintf('the defaults of %s lead back to it', $target);
        }

        return $reason === null ? null : sprintf('The default {} cannot be `new %s()`: %s; null is used instead.', $target, $reason);
    }

    private function unconstructible(ClassModel $class): ?string
    {
        if ($class->kind()->isAbstract() || $class->kind()->isInterface()) {
            return 'the class is abstract';
        }

        foreach ($this->constructorProperties($class->name()->fqcn()) as $property) {
            if (!$class->constructorDefaultOf($property) instanceof DefaultValue) {
                return sprintf('{} lacks "%s", which its constructor requires', $property->wireName());
            }
        }

        return null;
    }

    /**
     * A property copied into another class reports its refusal once, as Diagnostics drops repeats.
     */
    private function withoutRefusedDefaults(ClassModel $class, Diagnostics $diagnostics): ClassModel
    {
        $changed = false;
        $properties = [];
        foreach ($class->properties() as $property) {
            $at = $property->source()->toString();
            $refusal = $this->refused[$at] ?? null;
            if ($refusal !== null) {
                $diagnostics->warning($refusal, $property->source()->child('default'));
                $property = $this->defaultingToNull($property);
                $changed = true;
            }

            $properties[] = $property;
        }

        return $changed ? $class->withProperties(...$properties) : $class;
    }

    /**
     * The class an empty object default creates, if the property has one.
     */
    private function created(PropertyModel $property): ?string
    {
        $default = $property->default();
        $type = $property->type();
        $type = $type instanceof NullableType ? $type->inner() : $type;

        return $default instanceof DefaultValue && $default->value() === [] && $type instanceof ClassType ? $type->className()->fqcn() : null;
    }

    /**
     * @return list<PropertyModel>
     */
    private function constructorProperties(string $fqcn): array
    {
        $class = $this->classes[$fqcn] ?? null;

        return $class instanceof ClassModel ? array_merge($this->inherited[$fqcn] ?? [], $class->properties()) : [];
    }

    /**
     * A narrowed type may hold no null: the default {} alone kept the property optional.
     */
    private function defaultingToNull(PropertyModel $property): PropertyModel
    {
        return new PropertyModel(
            $property->name(),
            $property->wireName(),
            NullableType::of($property->type()),
            false,
            new DefaultValue(null),
            $property->doc(),
            $property->source(),
            $property->attributes(),
            $property->isAdditionalProperties(),
        );
    }
}
