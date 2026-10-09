<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Infrastructure\Emitter;

use MSSTC4PHP\DtoGenerator\Domain\Model\ClassKind;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;
use MSSTC4PHP\DtoGenerator\Domain\Model\DiscriminatorValues;
use MSSTC4PHP\DtoGenerator\Domain\Model\NullableType;
use MSSTC4PHP\DtoGenerator\Domain\Model\PropertyModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\TypeModel;
use MSSTC4PHP\DtoGenerator\Domain\Shared\DefaultValue;
use MSSTC4PHP\DtoGenerator\Domain\Target\Capability;
use MSSTC4PHP\DtoGenerator\Domain\Target\ClassForm;
use MSSTC4PHP\DtoGenerator\Domain\Target\TargetProfile;
use MSSTC4PHP\DtoGenerator\Domain\Target\WitherStyle;
use PhpParser\Modifiers;

/**
 * One class's place in its hierarchy as the emitter sees it: a base keeps its properties reachable by subclasses,
 * a subclass passes the inherited ones to the parent constructor.
 */
final class ClassShape
{
    private ClassModel $class;

    /** @var list<PropertyModel> */
    private array $inherited;

    private ClassForm $form;

    private TargetProfile $target;

    private ?ClassModel $parent;

    /**
     * @param list<PropertyModel> $inherited
     * @param ClassModel|null $parent the class it extends, whose own properties end $inherited
     */
    public function __construct(ClassModel $class, array $inherited, ClassForm $form, TargetProfile $target, ?ClassModel $parent = null)
    {
        $this->class = $class;
        $this->inherited = $inherited;
        $this->form = $form;
        $this->target = $target;
        $this->parent = $parent;
    }

    /**
     * The parent as it emits itself: its parameter order, by its own defaults, is what the parent call must follow.
     */
    public function parentShape(): ?self
    {
        if (!$this->parent instanceof ClassModel) {
            return null;
        }

        $grandInherited = array_slice($this->inherited, 0, count($this->inherited) - count($this->parent->properties()));

        return new self($this->parent, $grandInherited, $this->target->classFormFor($this->parent->mutability()), $this->target);
    }

    public function form(): ClassForm
    {
        return $this->form;
    }

    public function className(): ClassName
    {
        return $this->class->name();
    }

    private function selection(PropertyModel $property): ?DiscriminatorValues
    {
        return $this->class->discriminatorValuesOf($property->name());
    }

    /**
     * A discriminator selected by one value defaults to it; selected by several, it keeps its own default only when
     * that is one of them.
     */
    public function defaultOf(PropertyModel $property): ?DefaultValue
    {
        $selection = $this->selection($property);
        $default = $property->default();
        if (!$selection instanceof DiscriminatorValues) {
            return $default;
        }

        $values = $selection->values();
        if (count($values) === 1) {
            return new DefaultValue($values[0]);
        }

        return $default instanceof DefaultValue && in_array($default->value(), $values, true) ? $default : null;
    }

    /**
     * The constructor's check rejects null before a checked discriminator is assigned, and PHPStan reports a
     * declared property that never holds a value its type admits; a promoted one shares the parameter's type.
     */
    public function declaredType(PropertyModel $property): TypeModel
    {
        $type = $property->type();

        return $type instanceof NullableType && !$this->form->isPromoted() && $this->selection($property) instanceof DiscriminatorValues ? $type->inner() : $type;
    }

    /**
     * A copy with another discriminator would skip the constructor's check, or select another class when read back.
     */
    public function hasMutators(PropertyModel $property): bool
    {
        return !$this->selection($property) instanceof DiscriminatorValues
            && !in_array($property->wireName(), $this->class->discriminatedProperties(), true);
    }

    /**
     * An inherited property whose parent left its mutators out for a discriminated chain this class is not in.
     */
    public function restoresMutators(PropertyModel $property): bool
    {
        return in_array($property->wireName(), $this->class->restoredMutators(), true);
    }

    /**
     * @return list<array{PropertyModel, DiscriminatorValues}> root discriminator first
     */
    public function checks(): array
    {
        $byName = [];
        foreach ($this->all() as $property) {
            $byName[$property->name()] = $property;
        }

        $checks = [];
        foreach ($this->class->discriminatorValues() as $values) {
            $property = $byName[$values->property()] ?? null;
            if ($values->isChecked() && $property instanceof PropertyModel) {
                $checks[] = [$property, $values];
            }
        }

        return $checks;
    }

    /**
     * @return list<PropertyModel>
     */
    public function inherited(): array
    {
        return $this->inherited;
    }

    /**
     * @return list<PropertyModel> inherited first, as the constructor takes them
     */
    public function all(): array
    {
        return array_merge($this->inherited, $this->class->properties());
    }

    public function owns(PropertyModel $property): bool
    {
        return in_array($property, $this->class->properties(), true);
    }

    public function isBase(): bool
    {
        return !$this->class->kind()->equals(ClassKind::from(ClassKind::FINAL));
    }

    public function isAbstract(): bool
    {
        return $this->class->kind()->isAbstract();
    }

    /**
     * Of a property that is not public: subclasses reach the properties of a base.
     */
    public function visibility(): int
    {
        return $this->isBase() ? Modifiers::PROTECTED : Modifiers::PRIVATE;
    }

    public function witherStyle(): WitherStyle
    {
        return $this->form->withers();
    }

    /**
     * A wither of a base must return the subclass it is called on: cloning does, `new self` would build the base.
     * So with `new self` a base has no withers, and a final class rebuilds itself for the inherited properties too.
     */
    public function hasWithers(): bool
    {
        return !$this->form->withers()->isNone() && (!$this->isBase() || !$this->isNewSelf());
    }

    public function declaresInheritedWithers(): bool
    {
        return !$this->isBase() && $this->isNewSelf();
    }

    private function isNewSelf(): bool
    {
        return $this->form->withers()->equals(WitherStyle::from(WitherStyle::NEW_SELF));
    }

    /**
     * Mutators of a base return the subclass they are called on.
     */
    public function returnType(): string
    {
        return $this->isBase() && $this->target->supports(Capability::from(Capability::STATIC_RETURN_TYPE)) ? 'static' : 'self';
    }

    /**
     * @return list<string>
     */
    public function returnTag(): array
    {
        return $this->isBase() && $this->returnType() === 'self' ? ['@return static'] : [];
    }
}
