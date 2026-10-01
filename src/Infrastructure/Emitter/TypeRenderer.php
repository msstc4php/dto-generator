<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Infrastructure\Emitter;

use LogicException;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassType;
use MSSTC4PHP\DtoGenerator\Domain\Model\ListType;
use MSSTC4PHP\DtoGenerator\Domain\Model\MapType;
use MSSTC4PHP\DtoGenerator\Domain\Model\MixedType;
use MSSTC4PHP\DtoGenerator\Domain\Model\NullableType;
use MSSTC4PHP\DtoGenerator\Domain\Model\ScalarType;
use MSSTC4PHP\DtoGenerator\Domain\Model\TypeModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\UnionType;
use MSSTC4PHP\DtoGenerator\Domain\Target\Capability;
use MSSTC4PHP\DtoGenerator\Domain\Target\TargetProfile;
use PhpParser\Node;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;

/**
 * Native and PHPDoc forms of an IR type inside one namespace (spec §5.1). Classes outside the namespace are
 * fully qualified, so neither form resolves them against the file's namespace.
 */
final class TypeRenderer
{
    private const BUILTIN = ['int', 'float', 'string', 'bool', 'array', 'mixed', 'null'];

    private string $namespace;

    private TargetProfile $target;

    public function __construct(string $namespace, TargetProfile $target)
    {
        $this->namespace = $namespace;
        $this->target = $target;
    }

    public function native(TypeModel $type): ?string
    {
        if ($type instanceof ScalarType) {
            return $type->kind();
        }

        if ($type instanceof ClassType) {
            return $this->qualify($type->className());
        }

        if ($type instanceof ListType || $type instanceof MapType) {
            return 'array';
        }

        if ($type instanceof MixedType) {
            return $this->target->supports(Capability::from(Capability::MIXED_TYPE)) ? 'mixed' : null;
        }

        if ($type instanceof UnionType) {
            return $this->nativeUnion($type);
        }

        if ($type instanceof NullableType) {
            $inner = $this->native($type->inner());
            if ($inner === null) {
                return null;
            }

            return strpos($inner, '|') === false ? '?' . $inner : $inner . '|null';
        }

        throw new LogicException(sprintf('Type %s cannot be emitted yet.', $type->describe()));
    }

    /**
     * @return Identifier|Name|Node\NullableType|Node\UnionType|null
     */
    public function nativeNode(TypeModel $type): ?Node
    {
        $native = $this->native($type);
        if ($native === null) {
            return null;
        }

        if (strpos($native, '|') !== false) {
            return new Node\UnionType(array_map([self::class, 'single'], explode('|', $native)));
        }

        return strncmp($native, '?', 1) === 0 ? new Node\NullableType($this->single(substr($native, 1))) : $this->single($native);
    }

    public function doc(TypeModel $type): string
    {
        if ($type instanceof ScalarType) {
            return $type->phpDoc() ?? $type->kind();
        }

        if ($type instanceof ClassType) {
            return $this->qualify($type->className());
        }

        if ($type instanceof ListType) {
            return 'list<' . $this->doc($type->item()) . '>';
        }

        if ($type instanceof MapType) {
            return 'array<array-key, ' . $this->doc($type->value()) . '>';
        }

        if ($type instanceof MixedType) {
            return 'mixed';
        }

        if ($type instanceof UnionType) {
            return implode('|', array_map([$this, 'doc'], $type->members()));
        }

        if ($type instanceof NullableType) {
            $inner = $type->inner();

            return $inner instanceof UnionType ? $this->doc($inner) . '|null' : '?' . $this->doc($inner);
        }

        throw new LogicException(sprintf('Type %s cannot be emitted yet.', $type->describe()));
    }

    public function needsDoc(TypeModel $type): bool
    {
        $native = $this->native($type);

        return $native === null || $native !== $this->doc($type);
    }

    private function nativeUnion(UnionType $type): ?string
    {
        if (!$this->target->supports(Capability::from(Capability::UNION_TYPES))) {
            return null;
        }

        $names = [];
        foreach ($type->members() as $member) {
            $name = (string) $this->native($member);
            $names[$name] = $name;
        }

        return implode('|', $names);
    }

    private function qualify(ClassName $name): string
    {
        return $name->namespace() === $this->namespace ? $name->shortName() : '\\' . $name->fqcn();
    }

    /**
     * @return Identifier|Name
     */
    private function single(string $type): Node
    {
        if (strncmp($type, '\\', 1) === 0) {
            return new FullyQualified(substr($type, 1));
        }

        return in_array($type, self::BUILTIN, true) ? new Identifier($type) : new Name($type);
    }
}
