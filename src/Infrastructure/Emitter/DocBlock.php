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
            foreach ((array) preg_split('/\r\n|\r|\n/', $text) as $line) {
                // "*/" inside the text would close the comment early.
                $lines[] = rtrim(str_replace('*/', '*\/', (string) $line));
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
