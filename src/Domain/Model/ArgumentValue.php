<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Model;

use LogicException;
use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Json;

/**
 * A value inside an attribute's argument list, renderable both as a PHP attribute and as an annotation.
 *
 * @phpstan-import-type JsonScalar from Json
 * @phpstan-import-type JsonValue from Json
 */
final class ArgumentValue
{
    public const KIND_LITERAL = 'literal';

    public const KIND_LIST = 'list';

    public const KIND_MAP = 'map';

    public const KIND_CONSTANT = 'constant';

    public const KIND_CLASS_REFERENCE = 'class-reference';

    public const KIND_NEW_INSTANCE = 'new-instance';

    /** @var self::KIND_* */
    private string $kind;

    /** @var JsonScalar */
    private $literal;

    /** @var array<int|string, ArgumentValue> */
    private array $items;

    private ?ClassName $class;

    private ?string $constant;

    /** @var list<AttributeArgument> */
    private array $arguments;

    /**
     * @param self::KIND_* $kind
     * @param JsonScalar $literal
     * @param array<int|string, ArgumentValue> $items
     * @param list<AttributeArgument> $arguments
     */
    private function __construct(string $kind, $literal, array $items, ?ClassName $class, ?string $constant, array $arguments)
    {
        $this->kind = $kind;
        $this->literal = $literal;
        $this->items = $items;
        $this->class = $class;
        $this->constant = $constant;
        $this->arguments = $arguments;
    }

    /**
     * Accepts any JSON value because callers feed decoded `x-` extensions; non-scalars are rejected.
     *
     * @param JsonValue $value
     */
    public static function literal($value): self
    {
        if ($value !== null && !is_scalar($value)) {
            throw new InvalidModel(sprintf('A literal argument must be a scalar or null, %s given.', gettype($value)));
        }

        if (is_float($value) && !is_finite($value)) {
            throw new InvalidModel('A float argument must be finite; INF and NAN have no PHP literal.');
        }

        return new self(self::KIND_LITERAL, $value, [], null, null, []);
    }

    public static function listOf(self ...$items): self
    {
        return new self(self::KIND_LIST, null, $items, null, null, []);
    }

    /**
     * @param array<int|string, self> $items
     */
    public static function mapOf(array $items): self
    {
        return new self(self::KIND_MAP, null, $items, null, null, []);
    }

    public static function constant(string $name, ?ClassName $class = null): self
    {
        if (!Identifier::isValid($name) || Identifier::asciiLower($name) === 'class') {
            throw new InvalidModel(sprintf('"%s" is not a valid constant name; use classReference() for ::class.', $name));
        }

        return new self(self::KIND_CONSTANT, null, [], $class, $name, []);
    }

    public static function classReference(ClassName $class): self
    {
        return new self(self::KIND_CLASS_REFERENCE, null, [], $class, null, []);
    }

    public static function newInstance(ClassName $class, AttributeArgument ...$arguments): self
    {
        AttributeArgument::assertWellFormed($arguments);

        return new self(self::KIND_NEW_INSTANCE, null, [], $class, null, $arguments);
    }

    /**
     * @return self::KIND_*
     */
    public function kind(): string
    {
        return $this->kind;
    }

    /**
     * @return JsonScalar
     */
    public function literalValue()
    {
        $this->assertKind(self::KIND_LITERAL);

        return $this->literal;
    }

    /**
     * @return list<ArgumentValue>
     */
    public function listItems(): array
    {
        $this->assertKind(self::KIND_LIST);

        return array_values($this->items);
    }

    /**
     * Keys may be ints for numeric map keys.
     *
     * @return array<int|string, ArgumentValue>
     */
    public function mapItems(): array
    {
        $this->assertKind(self::KIND_MAP);

        return $this->items;
    }

    public function constantName(): string
    {
        $this->assertKind(self::KIND_CONSTANT);
        if ($this->constant === null) {
            throw new LogicException('A constant argument without a name.');
        }

        return $this->constant;
    }

    /**
     * @return ClassName|null null for a global constant
     */
    public function constantClass(): ?ClassName
    {
        $this->assertKind(self::KIND_CONSTANT);

        return $this->class;
    }

    public function className(): ClassName
    {
        $this->assertKind(self::KIND_CLASS_REFERENCE, self::KIND_NEW_INSTANCE);
        if (!$this->class instanceof ClassName) {
            throw new LogicException(sprintf('A %s argument without a class.', $this->kind));
        }

        return $this->class;
    }

    /**
     * @return list<AttributeArgument>
     */
    public function arguments(): array
    {
        $this->assertKind(self::KIND_NEW_INSTANCE);

        return $this->arguments;
    }

    private function assertKind(string ...$kinds): void
    {
        if (!in_array($this->kind, $kinds, true)) {
            throw new LogicException(sprintf('Argument of kind "%s" is not one of: %s.', $this->kind, implode(', ', $kinds)));
        }
    }
}
