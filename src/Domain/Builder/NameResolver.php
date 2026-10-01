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

    public function className(string $schemaName): ?string
    {
        $words = $this->words($schemaName);
        if ($words === []) {
            return null;
        }

        $name = $this->guardDigit(implode('', array_map(
            static fn (string $word): string => Identifier::asciiUpperFirst($word),
            $words,
        )));

        return Identifier::isReserved($name) ? $name . '_' : $name;
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
