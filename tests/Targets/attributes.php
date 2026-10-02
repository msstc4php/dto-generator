<?php

declare(strict_types=1);

// The attribute classes the golden Sample uses, so PHPStan resolves them and the smoke run instantiates them.

namespace App\Attr;

#[\Attribute(\Attribute::TARGET_CLASS)]
final class Table
{
    public string $name;

    public function __construct(string $name)
    {
        $this->name = $name;
    }
}

#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_PARAMETER)]
final class Choice
{
    /** @var list<int|string> */
    public array $choices;

    public string $mode;

    /**
     * @param list<int|string> $choices
     */
    public function __construct(array $choices, string $mode)
    {
        $this->choices = $choices;
        $this->mode = $mode;
    }
}

#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_PARAMETER)]
final class Meta
{
    /** @var class-string */
    public string $type;

    /**
     * @param class-string $type
     */
    public function __construct(string $type)
    {
        $this->type = $type;
    }
}

#[\Attribute(\Attribute::TARGET_ALL)]
final class Audited
{
    public string $level;

    public function __construct(string $level)
    {
        $this->level = $level;
    }
}

#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_PARAMETER)]
final class Sensitive
{
    public string $mask;

    public int $keep;

    public function __construct(string $mask, int $keep)
    {
        $this->mask = $mask;
        $this->keep = $keep;
    }
}

#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_PARAMETER)]
final class Guard
{
    public Limit $limit;

    public function __construct(Limit $limit)
    {
        $this->limit = $limit;
    }
}

final class Limit
{
    public int $max;

    public function __construct(int $max)
    {
        $this->max = $max;
    }
}

final class Mode
{
    public const STRICT = 'strict';
}

namespace App\Attr\Constraints;

#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_PARAMETER)]
final class Positive
{
}

#[\Attribute(\Attribute::TARGET_CLASS)]
final class Valid
{
}
