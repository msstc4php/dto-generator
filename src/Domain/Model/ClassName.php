<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Model;

use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;

final class ClassName
{
    private string $namespace;

    private string $shortName;

    private function __construct(string $namespace, string $shortName)
    {
        $this->namespace = $namespace;
        $this->shortName = $shortName;
    }

    public static function fromFqcn(string $fqcn): self
    {
        if ($fqcn === '' || $fqcn === '\\') {
            throw new InvalidModel(sprintf('Class name "%s" must not be empty.', $fqcn));
        }

        $normalized = Identifier::normalizeQualifiedName($fqcn, 'class name');

        $position = strrpos($normalized, '\\');
        $namespace = $position === false ? '' : (string) substr($normalized, 0, $position);
        $shortName = $position === false ? $normalized : (string) substr($normalized, $position + 1);

        // Reserved namespace segments are legal from PHP 8.0, so only the target-aware config may reject them.
        if (Identifier::isReserved($shortName)) {
            throw new InvalidModel(sprintf('"%s" is not a valid class name: "%s" is a reserved word.', $fqcn, $shortName));
        }

        return new self($namespace, $shortName);
    }

    public function fqcn(): string
    {
        return $this->namespace === '' ? $this->shortName : $this->namespace . '\\' . $this->shortName;
    }

    public function namespace(): string
    {
        return $this->namespace;
    }

    public function shortName(): string
    {
        return $this->shortName;
    }

    /**
     * Segments PHP < 8.0 cannot parse in a namespace; the emitter must reject them for such targets.
     *
     * @return list<string>
     */
    public function reservedNamespaceSegments(): array
    {
        if ($this->namespace === '') {
            return [];
        }

        return array_values(array_filter(
            explode('\\', $this->namespace),
            static fn (string $segment): bool => Identifier::isPhp74Keyword($segment),
        ));
    }

    /**
     * Case-insensitive, as PHP resolves class names.
     */
    public function equals(self $other): bool
    {
        return Identifier::asciiLower($this->fqcn()) === Identifier::asciiLower($other->fqcn());
    }
}
