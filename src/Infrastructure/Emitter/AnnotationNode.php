<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Infrastructure\Emitter;

/**
 * One annotation value as text: a leaf, or a bracketed group (`@Name(...)`, `{...}`) whose items break onto their own
 * lines when the group does not fit on one.
 */
final class AnnotationNode
{
    private const INDENT = '    ';

    private string $open;

    /** @var list<self> */
    private array $items;

    private string $close;

    /**
     * @param list<self> $items
     */
    private function __construct(string $open, array $items, string $close)
    {
        $this->open = $open;
        $this->items = $items;
        $this->close = $close;
    }

    public static function leaf(string $text): self
    {
        return new self($text, [], '');
    }

    /**
     * @param list<self> $items
     */
    public static function group(string $open, array $items, string $close): self
    {
        return new self($open, $items, $close);
    }

    /**
     * The same value after a key, like `name=` or `"k"=`.
     */
    public function after(string $prefix): self
    {
        return new self($prefix . $this->open, $this->items, $this->close);
    }

    private function inline(): string
    {
        return $this->open . implode(', ', array_map(static fn (self $item): string => $item->inline(), $this->items)) . $this->close;
    }

    /**
     * @param int<0, max> $limit the longest line of annotation text, its own indentation included
     * @param int<0, max> $depth
     *
     * @return non-empty-list<string>
     */
    public function lines(int $limit, int $depth = 0): array
    {
        $indent = str_repeat(self::INDENT, $depth);
        $inline = $indent . $this->inline();
        if ($this->items === [] || strlen($inline) <= $limit) {
            return [$inline];
        }

        $lines = [$indent . $this->open];
        $last = count($this->items) - 1;
        foreach ($this->items as $index => $item) {
            $itemLines = $item->lines($limit, $depth + 1);
            if ($index !== $last) {
                $itemLines[count($itemLines) - 1] .= ',';
            }

            array_push($lines, ...$itemLines);
        }

        $lines[] = $indent . $this->close;

        return $lines;
    }
}
