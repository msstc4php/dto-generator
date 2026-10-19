<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Builder;

use MSSTC4PHP\DtoGenerator\Domain\Model\Identifier;

/**
 * Derives PHP identifiers from schema and property names (spec §5.4).
 */
final class NameResolver
{
    // "_" separates words too, so snake_case becomes camelCase.
    private const SEPARATORS = '/[^\p{L}\p{M}\p{N}]+/u';

    private const ASCII_SEPARATORS = '/[^A-Za-z0-9]+/';

    /** Words for the signs that alone tell names apart, such as "+1" and "-1". */
    private const SIGNS = [
        '+' => 'plus', '-' => 'minus', '*' => 'asterisk', '@' => 'at', '#' => 'hash', '$' => 'dollar', '%' => 'percent',
        '&' => 'and', '=' => 'equals', '~' => 'tilde', '^' => 'caret', '<' => 'less', '>' => 'greater', '!' => 'not',
        '?' => 'question', '/' => 'slash', '.' => 'dot',
    ];

    public function className(string $schemaName): ?string
    {
        $words = $this->pascalCase($schemaName);
        if ($words === null) {
            return null;
        }

        $name = $this->guardDigit($words);

        return Identifier::isReserved($name) ? $name . '_' : $name;
    }

    /**
     * The words of a name joined in PascalCase, as a part of a class name: no guard for a leading digit or a reserved
     * word, which only a whole name needs.
     */
    public function pascalCase(string $name): ?string
    {
        $words = $this->words($name);

        return $words === [] ? null : implode('', array_map(static fn (string $word): string => Identifier::asciiUpperFirst($word), $words));
    }

    public function propertyName(string $wireName): ?string
    {
        $words = $this->words($wireName);
        if ($words === []) {
            return null;
        }

        $first = $this->lowerFirstWord(array_shift($words));

        $name = $this->guardDigit($first . implode('', array_map(
            static fn (string $word): string => Identifier::asciiUpperFirst($word),
            $words,
        )));

        // `$this` is the only property name PHP forbids.
        return $name === 'this' ? 'this_' : $name;
    }

    /**
     * UPPER_SNAKE, usable as an enum case and as a class constant on PHP 7.4.
     *
     * @param int|string $value
     */
    public function enumCaseName($value): ?string
    {
        if (is_int($value)) {
            // The digits of the string form: abs(PHP_INT_MIN) overflows to a float.
            return $value < 0 ? 'VALUE_MINUS_' . substr((string) $value, 1) : 'VALUE_' . $value;
        }

        if ($value === '') {
            return 'EMPTY';
        }

        // "inProgress" is two words, like "in-progress".
        $words = $this->words((string) preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', ' ', $value));
        if ($words === []) {
            return null;
        }

        $name = $this->guardDigit(implode('_', array_map([Identifier::class, 'asciiUpper'], $words)));

        // A constant named "class" would clash with Name::class.
        return Identifier::asciiLower($name) === 'class' ? $name . '_' : $name;
    }

    private function lowerFirstWord(string $word): string
    {
        // "URL", "ID" and "IDs" are one word each.
        if (preg_match('/^[A-Z0-9]+s?\z/', $word) === 1) {
            return Identifier::asciiLower($word);
        }

        // "HTTP2Status": the acronym ends at the capital that starts the next word.
        if (preg_match('/^([A-Z][A-Z0-9]*)([A-Z][a-z].*)\z/', $word, $acronym) === 1) {
            return Identifier::asciiLower($acronym[1]) . $acronym[2];
        }

        return Identifier::asciiLowerFirst($word);
    }

    /**
     * A name that starts with a sign, with its signs written as words, for when it has no other characters or only its
     * signs tell it from another: "+1" becomes "plus 1". A sign within a name ("in-progress") separates words.
     */
    public function spelled(string $name): string
    {
        return isset(self::SIGNS[$name[0] ?? '']) ? strtr($name, array_map(static fn (string $word): string => ' ' . $word . ' ', self::SIGNS)) : $name;
    }

    /**
     * The spelled names no other name meets, letter case ignored; the others stay as they were, and their collision is
     * reported.
     *
     * @template TKey of array-key
     *
     * @param array<TKey, string> $spelled
     * @param list<string> $kept the names left as they are
     *
     * @return array<TKey, string>
     */
    public function undisputed(array $spelled, array $kept): array
    {
        $counts = array_count_values(array_map(static fn (string $name): string => Identifier::asciiLower($name), array_merge(array_values($spelled), $kept)));

        return array_filter($spelled, static fn (string $name): bool => $counts[Identifier::asciiLower($name)] === 1);
    }

    /**
     * @return list<string>
     */
    private function words(string $name): array
    {
        // Invalid UTF-8 cannot be split by Unicode class, so only its ASCII letters and digits survive.
        $separators = preg_match('//u', $name) === 1 ? self::SEPARATORS : self::ASCII_SEPARATORS;
        $words = preg_split($separators, $name, -1, PREG_SPLIT_NO_EMPTY);

        return $words === false ? [] : $words;
    }

    private function guardDigit(string $name): string
    {
        return preg_match('/^[0-9]/', $name) === 1 ? '_' . $name : $name;
    }
}
