<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Config;

use InvalidArgumentException;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;

/**
 * A class or property name the configuration gives a schema of a document it cannot change: the extension keyword it
 * sets there, over what the document says.
 */
final class NameOverride
{
    public const CLASS_NAME = 'x-php-class-name';

    public const PROPERTY_NAME = 'x-php-name';

    /** @var self::CLASS_NAME|self::PROPERTY_NAME */
    private string $keyword;

    private SchemaLocation $target;

    /** @var non-empty-string */
    private string $name;

    private SchemaLocation $declaredAt;

    /**
     * @param SchemaLocation $declaredAt where the configuration names it
     */
    public function __construct(string $keyword, SchemaLocation $target, string $name, SchemaLocation $declaredAt)
    {
        if ($keyword !== self::CLASS_NAME && $keyword !== self::PROPERTY_NAME) {
            throw new InvalidArgumentException(sprintf('A name override sets x-php-class-name or x-php-name, not "%s".', $keyword));
        }

        if ($name === '') {
            throw new InvalidArgumentException('A name override needs a name.');
        }

        $this->keyword = $keyword;
        $this->target = $target;
        $this->name = $name;
        $this->declaredAt = $declaredAt;
    }

    /**
     * @return self::CLASS_NAME|self::PROPERTY_NAME
     */
    public function keyword(): string
    {
        return $this->keyword;
    }

    public function target(): SchemaLocation
    {
        return $this->target;
    }

    /**
     * @return non-empty-string
     */
    public function name(): string
    {
        return $this->name;
    }

    public function declaredAt(): SchemaLocation
    {
        return $this->declaredAt;
    }
}
