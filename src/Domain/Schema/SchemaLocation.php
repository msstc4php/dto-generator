<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Schema;

use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;

final class SchemaLocation
{
    private string $file;

    private string $pointer;

    public function __construct(string $file, string $pointer = '')
    {
        if ($file === '') {
            throw new InvalidModel('Schema location file must not be empty.');
        }

        if ($pointer !== '' && $pointer[0] !== '/') {
            throw new InvalidModel(sprintf('JSON pointer "%s" must be empty or start with "/".', $pointer));
        }

        $this->file = $file;
        $this->pointer = $pointer;
    }

    public function file(): string
    {
        return $this->file;
    }

    public function pointer(): string
    {
        return $this->pointer;
    }

    public function child(string ...$segments): self
    {
        $pointer = $this->pointer;
        foreach ($segments as $segment) {
            // RFC 6901: "~" must be escaped before "/".
            $pointer .= '/' . str_replace(['~', '/'], ['~0', '~1'], $segment);
        }

        return new self($this->file, $pointer);
    }

    public function toString(): string
    {
        return $this->file . '#' . $this->pointer;
    }

    public function equals(self $other): bool
    {
        return $this->file === $other->file && $this->pointer === $other->pointer;
    }
}
