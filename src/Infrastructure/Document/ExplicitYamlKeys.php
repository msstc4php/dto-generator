<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Infrastructure\Document;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Rewrites the `? key` / `: value` pairs of a YAML text whose key is a scalar into `"key": value`, which symfony/yaml
 * reads: it rejects every key written after "?". Only a pair it reads alike is rewritten: anything else stays, and
 * symfony/yaml rejects it as before. Block scalars stay untouched, and every line keeps its number.
 *
 * Each key it writes carries a mark; restore() takes the marks off what symfony/yaml read and refuses it unless every
 * mark is a key once and in no string, so a line it took for a pair but was part of a scalar never passes unnoticed.
 */
final class ExplicitYamlKeys
{
    /** A character of the Unicode private use area, which no document of the build holds. */
    private const MARK = "\u{E000}";

    /** Node properties, then a block scalar indicator with its chomping and indentation, then a comment. */
    private const BLOCK_HEADER = '(?:[!&]\S*[ \t]+)*[|>][-+0-9]*[ \t]*(?:#.*)?';

    /** Node properties alone, which belong to the value on the next lines. */
    private const PROPERTIES = '[!&]\S*(?:[ \t]+[!&]\S*)*[ \t]*(?:#.*)?';

    /** A plain key starting so, or holding ": ", is a collection or an indicator, not a string. */
    private const NOT_A_PLAIN_STRING = '/^(?:[][{}&*!|>@`\'"%]|[-?:](?:[ \t]|$))|:(?:[ \t]|$)/D';

    private function __construct()
    {
    }

    /**
     * The text with its pairs rewritten and their keys marked.
     */
    public static function rewrite(string $content): string
    {
        $marks = 0;
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $content));
        $count = count($lines);
        $written = [];
        $blockColumn = null;
        $previous = null;
        for ($index = 0; $index < $count; $index++) {
            $line = $lines[$index];
            $indent = self::indentOf($line);
            if ($blockColumn !== null && (trim($line) === '' || $indent > $blockColumn)) {
                $written[] = $line;
                $previous = trim($line) === '' ? $previous : $line;

                continue;
            }

            $pair = self::pairAt($lines, $index, $previous);
            if ($pair === null) {
                $blockColumn = self::blockColumn($line);
                $written[] = $line;
                $previous = self::isSignificant($line) ? $line : $previous;

                continue;
            }

            [$key, $value, $keyIndent] = $pair;
            $prefix = $keyIndent . self::marked($key, $marks++) . ':';
            if (self::staysOnTheKeyLine($value)) {
                // A comment, unlike an empty line, joins no block scalar before it and keeps the numbers of the lines.
                $written[] = $keyIndent . '#';
                $written[] = $value === '' ? $prefix : $prefix . ' ' . $value;
                $blockColumn = preg_match('/^' . self::BLOCK_HEADER . '$/D', $value) === 1 ? strlen($keyIndent) : null;
                $previous = $prefix;
                $index++;

                continue;
            }

            // A collection stays in its column in place of the ":", where the next round reads it as any line.
            $written[] = $prefix;
            $previous = $prefix;
            $lines[$index + 1] = substr_replace($lines[$index + 1], ' ', strlen($keyIndent), 1);
        }

        return implode("\n", $written);
    }

    /**
     * What symfony/yaml read of a rewritten text without the marks; null unless each mark is a key exactly once and
     * beside no key alike. The text holds each mark once, so a mark read anywhere else, in a string for one, leaves its
     * key unread.
     *
     * @param array<array-key, mixed> $decoded
     *
     * @return array<array-key, mixed>|null
     */
    public static function restore(array $decoded, string $rewritten): ?array
    {
        $found = [];
        $restored = self::unmarked($decoded, $found);

        return $restored !== null && count($found) * 2 === substr_count($rewritten, self::MARK) ? $restored : null;
    }

    /**
     * A text, such as a message about the rewritten document, without the marks.
     */
    public static function withoutMarks(string $text): string
    {
        return (string) preg_replace('/' . self::MARK . '\d+' . self::MARK . '/u', '', $text);
    }

    /**
     * @param array<array-key, mixed> $value
     * @param array<int|string, true> $found the marks met
     *
     * @return array<array-key, mixed>|null
     */
    private static function unmarked(array $value, array &$found): ?array
    {
        $restored = [];
        foreach ($value as $key => $item) {
            if (is_string($key) && preg_match('/^' . self::MARK . '(\d+)' . self::MARK . '(.*)$/Dsu', $key, $mark) === 1) {
                if (isset($found[$mark[1]]) || array_key_exists($mark[2], $value) || array_key_exists($mark[2], $restored)) {
                    return null;
                }

                $found[$mark[1]] = true;
                $key = $mark[2];
            }

            if (is_array($item)) {
                $item = self::unmarked($item, $found);
                if ($item === null) {
                    return null;
                }
            }

            $restored[$key] = $item;
        }

        return $restored;
    }

    /**
     * A quoted key with the mark inside its quotes, a plain one quoted with it.
     */
    private static function marked(string $key, int $number): string
    {
        $mark = self::MARK . $number . self::MARK;
        if (in_array($key[0], ['"', "'"], true)) {
            return $key[0] . $mark . substr($key, 1);
        }

        return (string) json_encode($mark . $key, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * The key, the value and the indentation of a `? key` line followed right away by its `: value` line; null when the
     * line holds no such pair or continues a scalar of the line before.
     *
     * @param list<string> $lines
     *
     * @return array{string, string, string}|null
     */
    private static function pairAt(array $lines, int $index, ?string $previous): ?array
    {
        if (preg_match('/^( *)\?[ \t]+(.*)$/D', $lines[$index], $question) !== 1 || !self::startsANode($question[1], $previous)) {
            return null;
        }

        $key = self::key($question[2]);
        $next = $lines[$index + 1] ?? '';
        if ($key === null || preg_match('/^' . $question[1] . ':(?:[ \t]+(.*))?$/D', $next, $colon) !== 1) {
            return null;
        }

        return [$key, $colon[1] ?? '', $question[1]];
    }

    /**
     * Whether a line at the indentation starts a node rather than continues a multi-line scalar of the line before:
     * the line before holds a node at least as deep (after the dashes of its sequence items), or opens a collection.
     */
    private static function startsANode(string $indent, ?string $previous): bool
    {
        if ($previous === null || preg_match('/^ *(?:-[ \t]+)*/', $previous, $column) !== 1 || strlen($column[0]) >= strlen($indent)) {
            return true;
        }

        $content = rtrim((string) preg_replace('/[ \t]+#.*$/D', '', $previous));

        return substr($content, -1) === ':' || preg_match('/^ *(?:-[ \t]*)+$/D', $content) === 1;
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
        if ($plain === '' || preg_match(self::NOT_A_PLAIN_STRING, $plain) === 1) {
            return null;
        }

        // A plain key symfony/yaml reads as another value (a number, true, null, a date) stays as written.
        try {
            $read = self::parse($plain . ': ~');
        } catch (ParseException $exception) {
            return null;
        }

        return is_array($read) && array_keys($read) === [$plain] ? $plain : null;
    }

    /**
     * Whether a value goes after the key on its line: none, a block header, node properties, or a scalar, which a line
     * of its own could read otherwise (symfony/yaml drops a trailing backslash of a multi-line plain scalar).
     */
    private static function staysOnTheKeyLine(string $value): bool
    {
        return $value === '' || preg_match('/^(?:' . self::BLOCK_HEADER . '|' . self::PROPERTIES . ')$/D', $value) === 1
            || preg_match('/^(?:[-?](?:[ \t]|$))|:(?:[ \t]|$)/D', (string) preg_replace('/[ \t]+#.*$/D', '', $value)) !== 1;
    }

    /**
     * The column below which the lines after a block scalar header are its content; null for a line that opens none.
     */
    private static function blockColumn(string $line): ?int
    {
        // A comment starts at a "#" after a space only; a quoted key is read whole.
        $key = '(?:\'(?:[^\']|\'\')*\'|"(?:[^"\\\\]|\\\\.)*"|[^#\s\'"](?:\S|[ \t]+(?!#))*?)[ \t]*:';
        if (preg_match('/^( *(?:-[ \t]+)*)(-|---|' . $key . ')[ \t]+' . self::BLOCK_HEADER . '$/D', $line, $header) !== 1) {
            return null;
        }

        // A document that is a block scalar holds every line after it, from the first column on.
        return $header[2] === '---' ? -1 : strlen($header[1]);
    }

    /**
     * @return mixed
     *
     * @throws ParseException
     */
    private static function parse(string $yaml)
    {
        return Yaml::parse($yaml);
    }

    private static function isSignificant(string $line): bool
    {
        return trim($line) !== '' && preg_match('/^[ \t]*#/', $line) !== 1;
    }

    private static function indentOf(string $line): int
    {
        return strlen($line) - strlen(ltrim($line, ' '));
    }
}
