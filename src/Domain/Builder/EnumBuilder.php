<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Builder;

use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;
use MSSTC4PHP\DtoGenerator\Domain\Model\DocModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\EnumBacking;
use MSSTC4PHP\DtoGenerator\Domain\Model\EnumCase;
use MSSTC4PHP\DtoGenerator\Domain\Model\EnumModel;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Schema;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaType;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Json;

/**
 * Builds the enum of an `enum` schema (spec §5.3): string or integer values, one backing type, case names derived
 * from the values, and `x-enum-descriptions` as case documentation.
 *
 * @phpstan-import-type JsonValue from Json
 */
final class EnumBuilder
{
    private NameResolver $names;

    public function __construct(NameResolver $names)
    {
        $this->names = $names;
    }

    public function build(ClassName $name, Schema $schema, Diagnostics $diagnostics): ?EnumModel
    {
        $at = $schema->location()->child('enum');
        $values = $this->values($schema, $diagnostics);
        if ($values === null) {
            return null;
        }

        if ($values === []) {
            $diagnostics->error('The enum has no value besides null.', $at);

            return null;
        }

        $backing = $this->backing($values, $schema, $diagnostics);
        if (!$backing instanceof EnumBacking) {
            return null;
        }

        $varnames = $this->varnames($schema, $diagnostics);
        if ($varnames === null) {
            return null;
        }

        $descriptions = $this->descriptions($schema, $values, $diagnostics);
        $cases = [];
        $taken = [];
        $failed = false;
        foreach ($values as $index => $value) {
            $varname = $varnames[$index] ?? null;
            $case = $this->names->enumCaseName($varname === null ? $value : $varname[0]);
            if ($case === null && $varname !== null) {
                $diagnostics->error(
                    sprintf('Name "%s" has no characters usable in a case name.', $varname[0]),
                    $schema->location()->child('x-enum-varnames', (string) $varname[1]),
                );
                $failed = true;

                continue;
            }

            if ($case === null) {
                $diagnostics->error(sprintf('Enum value %s has no characters usable in a case name.', $this->show($value)), $at->child((string) $index));
                $failed = true;

                continue;
            }

            if (isset($taken[$case])) {
                $diagnostics->error(
                    sprintf('Enum values %s and %s both become case %s.', $this->show($taken[$case]), $this->show($value), $case),
                    // The name the user wrote is where to fix it.
                    $varname === null ? $at->child((string) $index) : $schema->location()->child('x-enum-varnames', (string) $varname[1]),
                );
                $failed = true;

                continue;
            }

            $taken[$case] = $value;
            $description = $descriptions[$value] ?? null;
            $cases[] = new EnumCase($case, $value, new DocModel($description));
        }

        if ($failed) {
            return null;
        }

        return new EnumModel($name, $backing, $cases, new DocModel($schema->description(), $schema->isDeprecated()), $schema->location());
    }

    /**
     * The non-null values by their index in the schema, or null when one cannot back an enum.
     *
     * @return array<int, int|string>|null
     */
    private function values(Schema $schema, Diagnostics $diagnostics): ?array
    {
        $values = [];
        $seen = [];
        $valid = true;
        foreach ($schema->enum() ?? [] as $index => $value) {
            $value = Json::value($value);
            if ($value === null) {
                continue;
            }

            if (!is_int($value) && !is_string($value)) {
                $diagnostics->error(
                    sprintf('Enum value %s cannot back a PHP enum; use strings or integers.', $this->show($value)),
                    $schema->location()->child('enum', (string) $index),
                );
                $valid = false;

                continue;
            }

            // JSON keeps "1" and 1 apart.
            $key = $this->show($value);
            if (isset($seen[$key])) {
                $diagnostics->warning(sprintf('Enum value %s is listed twice.', $this->show($value)), $schema->location()->child('enum', (string) $index));

                continue;
            }

            $seen[$key] = $index;
            $values[$index] = $value;
        }

        return $valid ? $values : null;
    }

    /**
     * @param non-empty-array<int, int|string> $values
     */
    private function backing(array $values, Schema $schema, Diagnostics $diagnostics): ?EnumBacking
    {
        $strings = count(array_filter($values, 'is_string'));
        if ($strings !== 0 && $strings !== count($values)) {
            $diagnostics->error('The enum mixes strings and integers, which no PHP enum can back.', $schema->location()->child('enum'));

            return null;
        }

        $backing = EnumBacking::from($strings === 0 ? EnumBacking::INT : EnumBacking::STRING);
        $declared = $schema->nonNullTypes();
        // JSON Schema counts integers as numbers too.
        $accepted = $strings === 0 ? [[SchemaType::from(SchemaType::INTEGER)], [SchemaType::from(SchemaType::NUMBER)]] : [[SchemaType::from(SchemaType::STRING)]];
        if ($declared !== [] && !in_array($declared, $accepted, true)) {
            $diagnostics->error(
                sprintf('"type" does not match the enum values, which are %s.', $strings === 0 ? 'integers' : 'strings'),
                $schema->location()->child('type'),
            );

            return null;
        }

        return $backing;
    }

    /**
     * The x-enum-varnames name of each value, by the value's index in `enum`; positions count the non-null values,
     * duplicates included, as openapi-generator does. Null when the list is malformed (reported).
     *
     * @return array<int, array{string, int}>|null enum index → [name, position in x-enum-varnames]
     */
    private function varnames(Schema $schema, Diagnostics $diagnostics): ?array
    {
        if (!$schema->extensions()->has('x-enum-varnames')) {
            return [];
        }

        $raw = $schema->extensions()->get('x-enum-varnames');
        $indexes = array_keys(array_filter($schema->enum() ?? [], static fn ($value): bool => $value !== null));
        if (!is_array($raw) || !Json::isList($raw) || count($raw) !== count($indexes) || array_filter($raw, 'is_string') !== $raw) {
            $diagnostics->error(
                sprintf('"x-enum-varnames" must list one name per enum value (%d).', count($indexes)),
                $schema->location()->child('x-enum-varnames'),
            );

            return null;
        }

        $names = [];
        foreach ($indexes as $position => $index) {
            $names[$index] = [$raw[$position], $position];
        }

        return $names;
    }

    /**
     * @param array<int, int|string> $values
     *
     * @return array<int|string, string> value → description
     */
    private function descriptions(Schema $schema, array $values, Diagnostics $diagnostics): array
    {
        if (!$schema->extensions()->has('x-enum-descriptions')) {
            return [];
        }

        $at = $schema->location()->child('x-enum-descriptions');
        $raw = $schema->extensions()->get('x-enum-descriptions');
        if (!is_array($raw)) {
            $diagnostics->error('"x-enum-descriptions" must map enum values to descriptions.', $at);

            return [];
        }

        if ($this->isAmbiguousList($raw, $schema)) {
            $diagnostics->error(
                '"x-enum-descriptions" reads as a list (as does a map keyed "0", "1", … in order), which is ambiguous unless each position N holds enum value N; map each enum value to its description.',
                $at,
            );

            return [];
        }

        $known = array_map('strval', $values);
        $descriptions = [];
        foreach ($raw as $value => $description) {
            $value = (string) $value;
            if (!in_array($value, $known, true)) {
                $diagnostics->error(sprintf('There is no enum value "%s".', $value), $at->child($value));
            } elseif (!is_string($description)) {
                $diagnostics->error('An enum description must be a string.', $at->child($value));
            } else {
                $descriptions[$value] = $description;
            }
        }

        return $descriptions;
    }

    /**
     * JSON decodes a map keyed 0..n-1 as a list, and openapi-generator lists descriptions by position, so a list is read
     * only where both readings agree. Positions count every non-null value, duplicates included, as openapi-generator does.
     *
     * @param array<array-key, mixed> $raw
     */
    private function isAmbiguousList(array $raw, Schema $schema): bool
    {
        if (!Json::isList($raw)) {
            return false;
        }

        $positions = array_values(array_filter($schema->enum() ?? [], static fn ($value): bool => $value !== null));
        foreach (array_keys($raw) as $position) {
            if (($positions[$position] ?? null) !== $position) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param JsonValue $value
     */
    private function show($value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
