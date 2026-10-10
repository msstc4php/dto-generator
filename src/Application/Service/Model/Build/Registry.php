<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Service\Model\Build;

use MSSTC4PHP\DtoGenerator\Domain\Builder\Declarations;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;
use MSSTC4PHP\DtoGenerator\Domain\Model\EnumModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\EnumType;
use MSSTC4PHP\DtoGenerator\Domain\Model\Identifier;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Schema;

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

    /** @var array<string, true> lower-cased FQCN of the views' names claimed */
    private array $views = [];

    /** @var list<array{Schema, ClassName, int}> */
    private array $planned = [];

    /** @var list<BuiltEnum> */
    private array $builtEnums = [];

    private ?View $view;

    /** @var array<string, string> FQCN of a class → its short name without the view's suffix */
    private array $bases = [];

    public function __construct(?View $view = null)
    {
        $this->view = $view;
    }

    /**
     * The class a schema gives in this build's view. The short name without the suffix is remembered, so the classes
     * of its inline schemas are named after the schema, not the view (`PetOwnerRead`, not `PetReadOwner`).
     */
    public function className(string $namespace, string $short, Schema $schema): ClassName
    {
        $name = ClassName::fromFqcn(($namespace === '' ? '' : $namespace . '\\') . ($this->view instanceof View ? $this->view->name($short, $schema) : $short));
        $this->bases[$name->fqcn()] = $short;

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
     * Claims a class or enum name; a second schema claiming it (letter case ignored) is an error.
     */
    public function claim(ClassName $name, Schema $schema, Diagnostics $diagnostics): bool
    {
        $lower = Identifier::asciiLower($name->fqcn());
        $suffixed = $this->baseOf($name) !== $name->shortName();
        if (isset($this->taken[$lower])) {
            $diagnostics->error(
                sprintf(
                    'Class %s is already generated from %s; set "x-php-class-name" on one of them%s.',
                    $name->fqcn(),
                    $this->taken[$lower],
                    $suffixed || isset($this->views[$lower]) ? ', or change dto.readWriteSuffixes' : '',
                ),
                $schema->location(),
            );

            return false;
        }

        $this->taken[$lower] = $schema->location()->toString();
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
}
