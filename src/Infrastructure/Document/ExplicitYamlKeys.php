<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Infrastructure\Document;

/**
 * Rewrites the `? key` / `: value` pairs of a YAML text whose key is a scalar into `"key": value`, which symfony/yaml
 * reads: it rejects every key written after "?". Block scalars stay untouched, and every line keeps its number.
 */
final class ExplicitYamlKeys
{
    /** Node properties, then a block scalar indicator with its chomping and indentation, then a comment. */
    private const BLOCK_HEADER = '(?:[!&]\S* +)*[|>][-+0-9]* *(?:#.*)?';

    /** Node properties alone, which belong to the value on the next lines. */
    private const PROPERTIES = '(?:[!&]\S*)(?: +[!&]\S*)* *(?:#.*)?';

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
            if (preg_match('/^' . $question[1] . ':(?:[ \t]+(.*))?$/D', $next, $colon) !== 1) {
                // A key without a value line holds null.
                $written[] = $prefix;

                continue;
            }

            $value = $colon[1] ?? '';
            if ($value === '' || preg_match('/^(?:' . self::BLOCK_HEADER . '|' . self::PROPERTIES . ')$/D', $value) === 1) {
                // A comment, unlike an empty line, joins no block scalar before it and keeps the numbers of the lines.
                $index++;
                $written[] = $question[1] . '#';
                $written[] = $value === '' ? $prefix : $prefix . ' ' . $value;
                $blockIndent = preg_match('/^' . self::BLOCK_HEADER . '$/D', $value) === 1 ? strlen($question[1]) : null;

                continue;
            }

            // The value stays in its column in place of the ":", where the next round reads it as any line.
            $written[] = $prefix;
            $lines[$index + 1] = substr_replace($next, ' ', strlen($question[1]), 1);
        }

        return implode("\n", $written);
    }

    /**
     * The key as written before a ":", for a quoted or plain scalar with a comment at most after it; null otherwise.
     */
    private static function key(string $written): ?string
    {
        if (preg_match('/^(\'(?:[^\']|\'\')*\'|"(?:[^"\\\\]|\\\\.)*")(?:[ \t]+#.*)?[ \t]*$/D', $written, $quoted) === 1) {
            return $quoted[1];
        }

        $plain = trim((string) preg_replace('/(?:^|[ \t]+)#.*$/D', '', $written));
        // Anchors, aliases, tags, flow and block collections, and block or reserved indicators mean more than a string.
        if ($plain === '' || strpbrk($plain[0], '[]{}&*!|>@`\'"%') !== false || preg_match('/^[-?:](?:[ \t]|$)|:[ \t]|:$/D', $plain) === 1) {
            return null;
        }

        $encoded = json_encode($plain, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return is_string($encoded) ? $encoded : null;
    }

    private static function opensBlock(string $line): bool
    {
        return preg_match('/(?:^ *(?:- +)*-|:) +' . self::BLOCK_HEADER . '$/D', $line) === 1;
    }
}
