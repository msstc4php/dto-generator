<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Infrastructure\Emitter;

use MSSTC4PHP\DtoGenerator\Domain\Model\ClassKind;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;
use MSSTC4PHP\DtoGenerator\Domain\Model\PropertyModel;
use MSSTC4PHP\DtoGenerator\Domain\Target\ClassForm;
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

    /**
     * @param list<PropertyModel> $inherited
     */
    public function __construct(ClassModel $class, array $inherited, ClassForm $form)
    {
        $this->class = $class;
        $this->inherited = $inherited;
        $this->form = $form;
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

    /**
     * A subclass rebuilds itself with `new self`: inherited readonly properties belong to the parent's scope, which
     * neither a clone assignment nor `clone with` may write from the subclass.
     */
    public function witherStyle(): WitherStyle
    {
        return $this->class->parent() instanceof ClassName ? WitherStyle::from(WitherStyle::NEW_SELF) : $this->form->withers();
    }
}
