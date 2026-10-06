<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Infrastructure\Emitter;

use LogicException;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassType;
use MSSTC4PHP\DtoGenerator\Domain\Model\EnumType;
use MSSTC4PHP\DtoGenerator\Domain\Model\Identifier as ModelIdentifier;
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
    private string $namespace;

    private TargetProfile $target;

    private GeneratedCodePrinter $printer;

    /** @var array<string, string> */
    private array $shortNames = [];

    public function __construct(string $namespace, TargetProfile $target)
    {
        $this->namespace = $namespace;
        $this->target = $target;
        $this->printer = new GeneratedCodePrinter();
    }

    public function native(TypeModel $type): ?string
    {
        $node = $this->nativeNode($type);

        return $node instanceof Node ? $this->printer->type($node) : null;
    }

    /**
     * The declaration the target can express, or null when it cannot (a union or mixed on 7.4).
     *
     * @return Identifier|Name|Node\NullableType|Node\UnionType|null
     */
    public function nativeNode(TypeModel $type): ?Node
    {
        if (!$type instanceof NullableType) {
            return $this->nonNullable($type);
        }

        $inner = $this->nonNullable($type->inner());
        if ($inner instanceof Node\UnionType) {
            return new Node\UnionType(array_merge($inner->types, [new Identifier('null')]));
        }

        return $inner instanceof Node ? new Node\NullableType($inner) : null;
    }

    /**
     * The refined PHPDoc type, as `@phpstan-` tags carry it.
     */
    public function doc(TypeModel $type): string
    {
        return $this->render($type, false);
    }

    /**
     * The doc tags a declaration needs: `@<tag>` with a type every PHPDoc reader understands, when it differs from the
     * native type, and `@phpstan-<tag>` with the refined type, when it differs from the portable one. Symfony 5.4's
     * PhpDocExtractor takes non-empty-string or int<0, 30> for class names.
     *
     * @param 'var'|'param'|'return' $tag
     *
     * @return list<string>
     */
    public function tags(TypeModel $type, string $tag, string $suffix = ''): array
    {
        $refined = $this->render($type, false);
        $portable = $this->render($type, true);
        $native = $this->native($type);
        $tags = [];
        if ($native === null || $native !== $portable) {
            $tags[] = '@' . $tag . ' ' . $portable . $suffix;
        }

        if ($refined !== $portable) {
            $tags[] = '@phpstan-' . $tag . ' ' . $refined . $suffix;
        }

        return $tags;
    }

    /**
     * @param bool $portable whether to leave out the refinements only PHPStan and Psalm read
     */
    private function render(TypeModel $type, bool $portable): string
    {
        if ($type instanceof ScalarType) {
            return $portable ? $type->kind() : $type->phpDoc() ?? $type->kind();
        }

        if ($type instanceof ClassType) {
            return $this->printer->type($this->className($type->className()));
        }

        if ($type instanceof EnumType) {
            if ($this->enums()) {
                return $this->printer->type($this->className($type->className()));
            }

            return $portable ? $type->backing()->value() : $this->printer->type($this->className($type->className())) . '::*';
        }

        if ($type instanceof ListType) {
            return 'list<' . $this->render($type->item(), $portable) . '>';
        }

        if ($type instanceof MapType) {
            return 'array<array-key, ' . $this->render($type->value(), $portable) . '>';
        }

        if ($type instanceof MixedType) {
            return 'mixed';
        }

        if ($type instanceof UnionType) {
            $members = [];
            foreach ($type->members() as $member) {
                $members[] = $this->render($member, $portable);
            }

            return implode('|', array_unique($members));
        }

        if ($type instanceof NullableType) {
            $inner = $type->inner();
            $doc = $this->render($inner, $portable);

            // "?" binds to one type only: a union, even nested ("?list<A::*>" read as "?list"), needs "|null". A union
            // whose members became one after erasure is one type again.
            return strpos($doc, '|') !== false || strpos($doc, '::') !== false ? $doc . '|null' : '?' . $doc;
        }

        throw $this->unsupported($type);
    }

    /**
     * @return Identifier|Name|Node\UnionType|null
     */
    private function nonNullable(TypeModel $type): ?Node
    {
        if ($type instanceof UnionType) {
            return $this->nativeUnion($type);
        }

        if ($type instanceof MixedType) {
            return $this->target->supports(Capability::from(Capability::MIXED_TYPE)) ? new Identifier('mixed') : null;
        }

        return $this->single($type);
    }

    /**
     * @return Identifier|Name|Node\UnionType|null
     */
    private function nativeUnion(UnionType $type): ?Node
    {
        if (!$this->target->supports(Capability::from(Capability::UNION_TYPES))) {
            return null;
        }

        // A list and a map are both "array"; the union keeps one.
        $members = [];
        $printed = [];
        foreach ($type->members() as $member) {
            $node = $this->single($member);
            $key = $this->printer->type($node);
            if (!in_array($key, $printed, true)) {
                $printed[] = $key;
                $members[] = $node;
            }
        }

        return count($members) === 1 ? $members[0] : new Node\UnionType($members);
    }

    /**
     * A type that is neither nullable, a union nor mixed: exactly what a union member may be.
     *
     * @return Identifier|Name
     */
    private function single(TypeModel $type): Node
    {
        if ($type instanceof EnumType) {
            // Before 8.1 an enum is a class of constants, so the property holds the backing value.
            return $this->enums() ? $this->className($type->className()) : new Identifier($type->backing()->value());
        }

        if ($type instanceof ScalarType) {
            return new Identifier($type->kind());
        }

        if ($type instanceof ClassType) {
            return $this->className($type->className());
        }

        if ($type instanceof ListType || $type instanceof MapType) {
            return new Identifier('array');
        }

        throw $this->unsupported($type);
    }

    private function enums(): bool
    {
        return $this->target->supports(Capability::from(Capability::ENUMS));
    }

    /**
     * Short inside the namespace, fully qualified outside it.
     */
    public function nameOf(ClassName $name): Name
    {
        return $this->className($name);
    }

    /**
     * Every short name this renderer wrote, which an import alias of the same file must not take.
     *
     * @return array<string, string> lower-cased name → name
     */
    public function shortNames(): array
    {
        return $this->shortNames;
    }

    private function className(ClassName $name): Name
    {
        if ($name->namespace() !== $this->namespace) {
            return new FullyQualified($name->fqcn());
        }

        $this->shortNames[ModelIdentifier::asciiLower($name->shortName())] = $name->shortName();

        return new Name($name->shortName());
    }

    private function unsupported(TypeModel $type): LogicException
    {
        return new LogicException(sprintf('Type %s cannot be emitted yet.', $type->describe()));
    }
}
