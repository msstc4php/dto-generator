<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Infrastructure\Emitter;

/**
 * A PHPDoc block from a schema description and tags (spec §5.5).
 */
final class DocBlock
{
    private function __construct()
    {
    }

    /**
     * @param list<string> $tags
     */
    public static function render(?string $description, array $tags): ?string
    {
        $lines = [];
        $text = trim((string) $description);
        if ($text !== '') {
            foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", $text)) as $line) {
                // "*/" would close the comment early, and a leading "@" would turn the text into a tag.
                $line = rtrim(str_replace('*/', '*\/', $line));
                $indent = strlen($line) - strlen(ltrim($line));
                $lines[] = substr($line, $indent, 1) === '@' ? substr_replace($line, '\\', $indent, 0) : $line;
            }
        }

        if ($tags !== []) {
            if ($lines !== []) {
                $lines[] = '';
            }

            array_push($lines, ...$tags);
        }

        if ($lines === []) {
            return null;
        }

        return "/**\n" . implode("\n", array_map(static fn (string $line): string => $line === '' ? ' *' : ' * ' . $line, $lines)) . "\n */";
    }
}
