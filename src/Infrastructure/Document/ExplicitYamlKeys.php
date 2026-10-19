<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Infrastructure\Document;

/**
 * Rewrites the `? key` / `: value` pairs of a YAML text whose key is a scalar into `"key": value`, which symfony/yaml
 * reads: it rejects every key written after "?". Block scalars stay untouched, and every line keeps its number.
 */
final class ExplicitYamlKeys
{
    private function __construct()
    {
    }

    public static function rewrite(string $content): string
    {
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $content));
        $count = count($lines);
        $written = [];
        $blockIndent = null;
        for ($index = 0; $index < $count; $index++) {
            $line = $lines[$index];
            $indent = strlen($line) - strlen(ltrim($line, ' '));
            if ($blockIndent !== null && (trim($line) === '' || $indent > $blockIndent)) {
                $written[] = $line;

                continue;
            }

            $blockIndent = null;
            $key = preg_match('/^( *)\? +(.*)$/D', $line, $question) === 1 ? self::key($question[2]) : null;
            if ($key === null) {
                $blockIndent = self::opensBlock($line) ? $indent : null;
                $written[] = $line;

                continue;
            }

            $prefix = $question[1] . $key . ':';
            $next = $lines[$index + 1] ?? '';
            if (preg_match('/^' . $question[1] . ':(?: +(.*))?$/D', $next, $colon) !== 1) {
                // A key without a value line holds null.
                $written[] = $prefix;

                continue;
            }

            $value = $colon[1] ?? '';
            // An empty line before the key keeps the numbers of the lines after it.
            if ($value === '' || preg_match('/^[|>][-+0-9]* *(?:#.*)?$/D', $value) === 1) {
                $index++;
                $written[] = '';
                $written[] = $value === '' ? $prefix : $prefix . ' ' . $value;
                $blockIndent = $value === '' ? null : strlen($question[1]);

                continue;
            }

            // The value goes below the key, where the next round reads it as any line, a key after "?" too.
            $written[] = $prefix;
            $lines[$index + 1] = $question[1] . '  ' . $value;
        }

        return implode("\n", $written);
    }

    /**
     * The key as written before a ":", for a quoted or plain scalar with a comment at most after it; null otherwise.
     */
    private static function key(string $written): ?string
    {
        if (preg_match('/^(\'(?:[^\']|\'\')*\'|"(?:[^"\\\\]|\\\\.)*")(?: +#.*)? *$/D', $written, $quoted) === 1) {
            return $quoted[1];
        }

        $plain = trim((string) preg_replace('/(?:^| +)#.*$/D', '', $written));
        // Anchors, aliases, tags, flow collections and block or reserved indicators mean more than a plain string.
        if ($plain === '' || strpbrk($plain[0], '[]{}&*!|>@`\'"%') !== false) {
            return null;
        }

        $encoded = json_encode($plain, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return is_string($encoded) ? $encoded : null;
    }

    private static function opensBlock(string $line): bool
    {
        return preg_match('/(?:^ *-|:) +[|>][-+0-9]* *(?:#.*)?$/D', $line) === 1;
    }
}
