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
 * Each key it writes carries a mark, which RewrittenYaml::restore() checks, so a line it took for a pair but was part
 * of a scalar never passes unnoticed. A "?" it leaves outside a block scalar, which symfony/yaml may read wrong without
 * failing (in a flow collection), leaves the whole text as it is.
 */
final class ExplicitYamlKeys
{
    /**
     * A "?" indicator at the start of a line, after the dashes of sequence items, in a flow collection or where a value
     * goes, or a ":" indicator of an empty key, which no pair consumed, at the start of a line, after sequence dashes, in
     * a flow collection or after node properties; it reads no comment, a "#" may be quoted.
     */
    private const QUESTION = '/(?:^|[\[{,]|:[ \t]|[!&]\S*[ \t])[ \t]*(?:-[ \t]+)*\?(?:[ \t,\]}]|$)'
        . '|(?:^|[\[{,]|[!&]\S*[ \t])[ \t]*(?:-[ \t]+)*:(?:[ \t,\]}]|$)/';

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
     * The text with its pairs rewritten and their keys marked; null when it rewrites none or leaves a "?".
     */
    public static function rewrite(string $content): ?RewrittenYaml
    {
        $nonce = bin2hex(random_bytes(4));
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
                if (preg_match(self::QUESTION, $line) === 1) {
                    return null;
                }

                $blockColumn = self::blockColumn($line);
                $written[] = $line;
                // A line continuing a multi-line scalar is no node: the lines after it continue the same scalar.
                $previous = self::isSignificant($line) && self::startsANode(str_repeat(' ', $indent), $previous) ? $line : $previous;

                continue;
            }

            [$key, $value, $keyIndent] = $pair;
            $prefix = $keyIndent . self::marked($key, RewrittenYaml::mark($nonce, $marks++)) . ':';
            if (self::staysOnTheKeyLine($value)) {
                // A comment, unlike an empty line, joins no block scalar before it and keeps the numbers of the lines.
                $written[] = $keyIndent . '#';
                $written[] = $value === '' ? $prefix : $prefix . ' ' . $value;
                $blockColumn = preg_match('/^' . self::BLOCK_HEADER . '$/D', $value) === 1 ? strlen($keyIndent) : null;
                $previous = $written[count($written) - 1];
                $index++;

                continue;
            }

            // A collection stays in its column in place of the ":", where the next round reads it as any line.
            $written[] = $prefix;
            $previous = $prefix;
            $lines[$index + 1] = substr_replace($lines[$index + 1], ' ', strlen($keyIndent), 1);
        }

        return $marks === 0 ? null : new RewrittenYaml(implode("\n", $written), $nonce, $marks);
    }

    /**
     * A quoted key with the mark inside its quotes, a plain one quoted with it.
     */
    private static function marked(string $key, string $mark): string
    {
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
