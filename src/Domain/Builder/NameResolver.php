<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Builder;

use MSSTC4PHP\DtoGenerator\Domain\Model\Identifier;

/**
 * Derives PHP identifiers from schema and property names (spec §5.4).
 */
final class NameResolver
{
    // "_" separates words too, so snake_case becomes camelCase; bytes 0x80-0xff keep UTF-8 letters intact.
    private const SEPARATORS = '/[^A-Za-z0-9\x80-\xff]+/';

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

        $first = array_shift($words);
        // An all-caps first word ("URL", "ID") is one word, so it is lower-cased whole.
        $first = preg_match('/^[A-Z0-9]+\z/', $first) === 1 ? Identifier::asciiLower($first) : Identifier::asciiLowerFirst($first);

        $name = $this->guardDigit($first . implode('', array_map(
            static fn (string $word): string => Identifier::asciiUpperFirst($word),
            $words,
        )));

        // `$this` is the only property name PHP forbids.
        return $name === 'this' ? 'this_' : $name;
    }

    /**
     * @return list<string>
     */
    private function words(string $name): array
    {
        $words = preg_split(self::SEPARATORS, $name, -1, PREG_SPLIT_NO_EMPTY);

        return $words === false ? [] : $words;
    }

    private function guardDigit(string $name): string
    {
        return preg_match('/^[0-9]/', $name) === 1 ? '_' . $name : $name;
    }
}
