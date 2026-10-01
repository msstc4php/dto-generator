<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Model;

use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;

final class EnumModel
{
    private ClassName $name;

    private EnumBacking $backing;

    /** @var non-empty-list<EnumCase> */
    private array $cases;

    private DocModel $doc;

    private SchemaLocation $source;

    /**
     * @param list<EnumCase> $cases
     */
    public function __construct(ClassName $name, EnumBacking $backing, array $cases, DocModel $doc, SchemaLocation $source)
    {
        if ($cases === []) {
            throw new InvalidModel(sprintf('Enum %s has no cases.', $name->fqcn()));
        }

        $isInt = $backing->equals(EnumBacking::from(EnumBacking::INT));
        $names = [];
        $values = [];
        foreach ($cases as $case) {
            if (is_int($case->value()) !== $isInt) {
                throw new InvalidModel(sprintf('Enum %s case "%s" has value %s, which does not match the %s backing.', $name->fqcn(), $case->name(), var_export($case->value(), true), $backing->value()));
            }

            if (isset($names[$case->name()])) {
                throw new InvalidModel(sprintf('Enum %s repeats case "%s".', $name->fqcn(), $case->name()));
            }

            if (isset($values[$case->value()])) {
                throw new InvalidModel(sprintf('Enum %s repeats value "%s".', $name->fqcn(), $case->value()));
            }

            $names[$case->name()] = true;
            $values[$case->value()] = true;
        }

        $this->name = $name;
        $this->backing = $backing;
        $this->cases = $cases;
        $this->doc = $doc;
        $this->source = $source;
    }

    public function name(): ClassName
    {
        return $this->name;
    }

    public function backing(): EnumBacking
    {
        return $this->backing;
    }

    /**
     * @return non-empty-list<EnumCase>
     */
    public function cases(): array
    {
        return $this->cases;
    }

    public function doc(): DocModel
    {
        return $this->doc;
    }

    public function source(): SchemaLocation
    {
        return $this->source;
    }
}
