<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Infrastructure\Emitter;

use MSSTC4PHP\DtoGenerator\Domain\Model\ClassKind;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\PropertyModel;
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

    /**
     * @param list<PropertyModel> $inherited
     */
    public function __construct(ClassModel $class, array $inherited, ClassForm $form, TargetProfile $target)
    {
        $this->class = $class;
        $this->inherited = $inherited;
        $this->form = $form;
        $this->target = $target;
    }

    public function form(): ClassForm
    {
        return $this->form;
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
