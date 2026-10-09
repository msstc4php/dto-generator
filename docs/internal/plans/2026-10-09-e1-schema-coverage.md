# E1 — покрытие схем: план реализации

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Генератор принимает инлайн-объекты в `oneOf`/`anyOf` и компонентных алиасах, `const`, смешанные `enum`,
`x-enum-varnames` и предупреждает о неподдерживаемых ключевых словах.

**Architecture:** Вынос инлайн-схем остаётся в `Application\Service\Model\Build\Action`. Обход `inline()` становится
`inlines()`, возвращает список и заходит в члены объединений; добавляется обход компонентных алиасов. `TypeMapper`
находит вынесенные схемы через `Declarations` по их location, поэтому его менять почти не нужно. `const` и смешанные
enum — новые ветки `TypeMapper` с PHPDoc-литералами из нового `Domain\Builder\LiteralType`. Предупреждения о
ключевых словах выдаёт `SchemaParser` по списку `Domain\Builder\UnsupportedKeywords`.

**Tech Stack:** PHP 7.4 (исходники), PHPUnit 9.6, PHPStan max, Infection 0.32, golden-проект на целях 7.4…8.5.

**Spec:** `docs/internal/specs/2026-10-09-e-features-design.md` §3 (решения владельца — §11).

## Global Constraints

- Исходники в `src/` — PHP 7.4: без promotion, `match`, union-типов, атрибутов, `str_contains()`.
- Комментарии в коде — английский, только неочевидное «почему».
- Публичный API не ломается (`make bc-check`): у `ScalarType::bool()` добавляется только необязательный параметр.
- Имена вынесенных членов: `title` члена в PascalCase, иначе `<Parent><Property>Option<N>` (N с 1, по объединённому
  списку `oneOf` + `anyOf`); у алиаса — `<Alias>Item`, `<Alias>Value`, `<Alias>Option<N>`.
- Значения `float`/`bool` в `enum` остаются ошибкой; `nullable: true` — только предупреждение, тип не меняется.
- После каждой задачи: `make fix`, `vendor/bin/phpunit`; в конце — `make verify`, `make infection` (MSI 100%),
  `make test-targets`, `make bc-check`.

## Review Focus

1. Схема, на которую ссылаются из двух мест (`$ref` дважды), — предупреждение о неподдерживаемом ключевом слове
   должно появиться ровно один раз (Task 5, тест `testWarnsOncePerSchemaReachedTwice`).
2. Строковый `const`/`enum` с кавычкой, `|`, `*/` или переводом строки — PHPDoc без уточнения, а не битый
   docblock или `InvalidModel` (Task 1, `testLeavesUnsafeStringsUnrefined`).
3. `default`, не совпадающий с `const` (`const: 5, default: 6`), — ошибка «Default 6 does not match 5» (Task 2,
   `testRejectsADefaultOtherThanTheConst`).
4. Инлайн-член `oneOf` с именем, совпавшим с существующим классом (`title: User`), — ошибка коллизии с подсказкой
   `x-php-class-name`, без падения (Task 6, `testReportsATitleThatCollides`).
5. `oneOf` из двух инлайн-объектов внутри `items` свойства — имена `<Parent><Property>ItemOption1/2` (Task 6,
   `testHoistsUnionMembersInsideItems`).

---

### Task 1: PHPDoc-литералы (`LiteralType`) и их проверка в `DefaultFit`

**Files:**
- Create: `src/Domain/Builder/LiteralType.php`
- Modify: `src/Domain/Model/ScalarType.php` (`bool(?string $phpDoc = null)`)
- Modify: `src/Domain/Builder/DefaultFit.php` (`fitsScalar`)
- Test: `tests/Unit/Domain/Builder/LiteralTypeTest.php`, `tests/Unit/Domain/Builder/DefaultFitTest.php`

**Interfaces:**
- Produces:
  - `LiteralType::of(int|string|bool $value): ?string` — `5`, `true`, `'a'`; `null` для небезопасной строки;
  - `LiteralType::union(list<int|string|bool> $values): ?string` — `'a'|'b'|1`, `null`, если хоть одно небезопасно;
  - `LiteralType::admits(string $refinement, mixed $value): ?bool` — `null`, если уточнение не литеральное;
  - `ScalarType::bool(?string $phpDoc = null)`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Domain\Builder;

use MSSTC4PHP\DtoGenerator\Domain\Builder\LiteralType;
use PHPUnit\Framework\TestCase;

final class LiteralTypeTest extends TestCase
{
    public function testWritesScalarsAsPhpDocLiterals(): void
    {
        self::assertSame('5', LiteralType::of(5));
        self::assertSame('-3', LiteralType::of(-3));
        self::assertSame('true', LiteralType::of(true));
        self::assertSame('false', LiteralType::of(false));
        self::assertSame("'in-progress'", LiteralType::of('in-progress'));
        self::assertSame("''", LiteralType::of(''));
    }

    public function testLeavesUnsafeStringsUnrefined(): void
    {
        foreach (["it's", 'a|b', 'x*/', "two\nlines", 'back\\slash', 'é'] as $value) {
            self::assertNull(LiteralType::of($value), $value);
        }
    }

    public function testJoinsValuesIntoAUnion(): void
    {
        self::assertSame("'a'|'b'|1", LiteralType::union(['a', 'b', 1]));
        self::assertNull(LiteralType::union(['a', "it's"]));
    }

    public function testTellsWhetherALiteralRefinementAdmitsAValue(): void
    {
        self::assertTrue(LiteralType::admits("'a'|'b'", 'b'));
        self::assertFalse(LiteralType::admits("'a'|'b'", 'c'));
        self::assertTrue(LiteralType::admits('5', 5));
        self::assertFalse(LiteralType::admits('5', '5'));
        self::assertTrue(LiteralType::admits('true', true));
        self::assertNull(LiteralType::admits('non-empty-string', 'x'));
        self::assertNull(LiteralType::admits('int<1, 5>', 3));
    }
}
```

В `DefaultFitTest` добавить:

```php
    public function testChecksLiteralRefinements(): void
    {
        self::assertTrue(DefaultFit::fits(5, ScalarType::int('5')));
        self::assertFalse(DefaultFit::fits(6, ScalarType::int('5')));
        self::assertTrue(DefaultFit::fits('a', ScalarType::string("'a'|'b'")));
        self::assertFalse(DefaultFit::fits('c', ScalarType::string("'a'|'b'")));
        self::assertTrue(DefaultFit::fits(true, ScalarType::bool('true')));
        self::assertFalse(DefaultFit::fits(false, ScalarType::bool('true')));
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter 'LiteralTypeTest|testChecksLiteralRefinements'`
Expected: FAIL — `Class "…\LiteralType" not found`, `ScalarType::bool()` с аргументом — `ArgumentCountError` не
возникает (PHP игнорирует лишний), но `fits(false, bool('true'))` возвращает `true`.

- [ ] **Step 3: Write minimal implementation**

`src/Domain/Builder/LiteralType.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Builder;

/**
 * PHPDoc literal types (`'a'`, `5`, `true`) for `const` and mixed enums.
 */
final class LiteralType
{
    // Left unrefined rather than escaped: quotes, backslashes, `|` and `*` would need escaping that not every PHPDoc
    // reader understands, and `*/` would end the docblock.
    private const SAFE_STRING = '/\A[A-Za-z0-9 _.,:;!?@#$%&+=<>()\[\]{}~^\/-]*\z/';

    private function __construct()
    {
    }

    /**
     * @param int|string|bool $value
     */
    public static function of($value): ?string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value)) {
            return (string) $value;
        }

        return preg_match(self::SAFE_STRING, $value) === 1 ? "'" . $value . "'" : null;
    }

    /**
     * @param non-empty-list<int|string|bool> $values
     */
    public static function union(array $values): ?string
    {
        $literals = [];
        foreach ($values as $value) {
            $literal = self::of($value);
            if ($literal === null) {
                return null;
            }

            $literals[] = $literal;
        }

        return implode('|', $literals);
    }

    /**
     * Whether a refinement built by union() admits the value; null for any other refinement.
     *
     * @param mixed $value
     */
    public static function admits(string $refinement, $value): ?bool
    {
        $literals = explode('|', $refinement);
        foreach ($literals as $literal) {
            if (preg_match("/\\A(?:'[^']*'|-?\\d+|true|false)\\z/", $literal) !== 1) {
                return null;
            }
        }

        $own = is_int($value) || is_string($value) || is_bool($value) ? self::of($value) : null;

        return $own !== null && in_array($own, $literals, true);
    }
}
```

`ScalarType::bool()`:

```php
    public static function bool(?string $phpDoc = null): self
    {
        return new self('bool', $phpDoc);
    }
```

`DefaultFit::fitsScalar()` — в начале метода:

```php
        $literal = $type->phpDoc() === null ? null : LiteralType::admits($type->phpDoc(), $value);
        if ($literal !== null) {
            return $literal;
        }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter 'LiteralTypeTest|DefaultFitTest'`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
make fix && vendor/bin/phpunit
git add src/Domain/Builder/LiteralType.php src/Domain/Model/ScalarType.php src/Domain/Builder/DefaultFit.php tests/Unit/Domain/Builder
git commit -m "feat(builder): PHPDoc literal types and their check in defaults"
```

### Task 2: `const` даёт точный тип

**Files:**
- Modify: `src/Domain/Builder/TypeMapper.php` (`bareType`, новый `constType`)
- Test: `tests/Unit/Domain/Builder/TypeMapperTest.php` (через `ModelFixture`)

**Interfaces:**
- Consumes: `LiteralType::of()`, `ScalarType::bool(?string)`.

- [ ] **Step 1: Write the failing test**

```php
    public function testGivesAConstItsLiteralType(): void
    {
        $output = ModelFixture::build(['C' => ['type' => 'object', 'required' => ['s', 'i', 'b'], 'properties' => [
            's' => ['const' => 'card'],
            'i' => ['const' => 5],
            'b' => ['type' => 'boolean', 'const' => true],
            'f' => ['const' => 1.5],
            'u' => ['const' => "it's"],
        ]]]);

        self::assertSame([], ModelFixture::messages($output));
        self::assertSame(["s: 'card'", 'i: 5', 'b: true', 'f: float|null', 'u: string|null'], ModelFixture::classes($output)['App\Dto\C']);
    }

    public function testRejectsATypeThatContradictsTheConst(): void
    {
        $output = ModelFixture::build(['C' => ['type' => 'object', 'properties' => ['s' => ['type' => 'string', 'const' => 5]]]]);

        self::assertSame(
            ['error /project/api/openapi.yaml#/components/schemas/C/properties/s/const: "const" is not of the declared type.'],
            ModelFixture::messages($output),
        );
    }

    public function testRejectsADefaultOtherThanTheConst(): void
    {
        $output = ModelFixture::build(['C' => ['type' => 'object', 'properties' => ['i' => ['const' => 5, 'default' => 6]]]]);

        self::assertSame(
            ['error /project/api/openapi.yaml#/components/schemas/C/properties/i/default: Default 6 does not match 5; null is used instead.'],
            ModelFixture::messages($output),
        );
    }
```

Если существующий тест ожидает `mixed` для `const`, обновить ожидание и указать это в коммите.

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter 'testGivesAConstItsLiteralType|testRejectsATypeThatContradictsTheConst|testRejectsADefaultOtherThanTheConst'`
Expected: FAIL — `s: mixed` вместо `s: 'card'`.

- [ ] **Step 3: Write minimal implementation**

В `TypeMapper::bareType()` перед блоком `$format = $schema->format();`:

```php
        if ($schema->hasKeyword('const')) {
            $const = $this->constType($schema, $diagnostics);
            if ($const instanceof TypeModel) {
                return $const;
            }
        }
```

Новый метод:

```php
    /**
     * The type of the one allowed value; null leaves the schema's own type (a null, array or object constant).
     */
    private function constType(Schema $schema, Diagnostics $diagnostics): ?TypeModel
    {
        $value = Json::value($schema->keyword('const'));
        if (is_string($value)) {
            $type = ScalarType::string(LiteralType::of($value));
            $kind = SchemaType::STRING;
        } elseif (is_int($value)) {
            $type = ScalarType::int(LiteralType::of($value));
            $kind = SchemaType::INTEGER;
        } elseif (is_bool($value)) {
            $type = ScalarType::bool(LiteralType::of($value));
            $kind = SchemaType::BOOLEAN;
        } elseif (is_float($value)) {
            $type = ScalarType::float();
            $kind = SchemaType::NUMBER;
        } else {
            return null;
        }

        $declared = array_map(static fn (SchemaType $type): string => $type->value(), $schema->nonNullTypes());
        // JSON Schema counts integers as numbers too.
        if ($declared !== [] && !in_array($kind, $declared, true) && !($kind === SchemaType::INTEGER && in_array(SchemaType::NUMBER, $declared, true))) {
            $diagnostics->error('"const" is not of the declared type.', $schema->location()->child('const'));

            return new MixedType();
        }

        return $type;
    }
```

Добавить `use MSSTC4PHP\DtoGenerator\Domain\Shared\Json;`. Сообщение о default берётся из существующего
`ClassBuilder` — он уже проверяет `DefaultFit::fits`, а Task 1 научил его литералам.

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter TypeMapperTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
make fix && vendor/bin/phpunit
git commit -am "feat(builder): a const property gets its literal type"
```

### Task 3: смешанные `enum` — union вместо ошибки

**Files:**
- Modify: `src/Domain/Builder/SchemaShape.php` (`isEnum`, новый `isMixedEnum`)
- Modify: `src/Domain/Builder/TypeMapper.php` (ветка смешанного enum)
- Modify: `src/Domain/Builder/EnumBuilder.php` (удалить недостижимую ветку «mixes strings and integers»)
- Test: `tests/Unit/Domain/Builder/TypeMapperTest.php`, `tests/Unit/Domain/Builder/EnumBuilderTest.php`

**Interfaces:**
- Produces: `SchemaShape::isMixedEnum(Schema $schema): bool` — все не-null значения — строки или целые, и есть оба вида.

- [ ] **Step 1: Write the failing test**

```php
    public function testGivesAMixedEnumAUnionOfItsLiterals(): void
    {
        $output = ModelFixture::build(['C' => ['type' => 'object', 'required' => ['m'], 'properties' => [
            'm' => ['enum' => ['active', 2, 'blocked']],
            'n' => ['enum' => ['a', 1, null]],
        ]]]);

        self::assertSame(
            [
                'warning /project/api/openapi.yaml#/components/schemas/C/properties/m/enum: The enum mixes strings and integers, which no PHP enum can back; the property takes either.',
                'warning /project/api/openapi.yaml#/components/schemas/C/properties/n/enum: The enum mixes strings and integers, which no PHP enum can back; the property takes either.',
            ],
            ModelFixture::messages($output),
        );
        self::assertSame(["m: 'active'|'blocked'|2", "n: 'a'|1|null"], ModelFixture::classes($output)['App\Dto\C']);
        self::assertSame([], ModelFixture::enums($output));
    }

    public function testRejectsAMixedEnumWhoseTypeLeavesOneKindOut(): void
    {
        $output = ModelFixture::build(['C' => ['type' => 'object', 'properties' => ['m' => ['type' => 'string', 'enum' => ['a', 1]]]]]);

        self::assertContains(
            'error /project/api/openapi.yaml#/components/schemas/C/properties/m/type: "type" does not match the enum values, which are strings and integers.',
            ModelFixture::messages($output),
        );
    }
```

В `EnumBuilderTest` тест на «mixes strings and integers» (если есть) переносится сюда: `EnumBuilder` больше не
получает смешанные enum.

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter 'testGivesAMixedEnumAUnionOfItsLiterals|testRejectsAMixedEnumWhoseTypeLeavesOneKindOut'`
Expected: FAIL — ошибка «The enum mixes strings and integers, which no PHP enum can back.».

- [ ] **Step 3: Write minimal implementation**

`SchemaShape`:

```php
    /**
     * Strings and integers together: no PHP enum can back them, so the property takes a union of both.
     */
    public static function isMixedEnum(Schema $schema): bool
    {
        $values = array_values(array_filter($schema->enum() ?? [], static fn ($value): bool => $value !== null));
        $strings = array_filter($values, 'is_string');
        $ints = array_filter($values, 'is_int');

        return $strings !== [] && $ints !== [] && count($strings) + count($ints) === count($values);
    }
```

В `isEnum()` добавить условие `&& !self::isMixedEnum($schema)`.

В `TypeMapper::bareType()` перед проверкой `$schema->enum() !== null && !SchemaShape::isEnum($schema)`:

```php
        if (SchemaShape::isMixedEnum($schema)) {
            return $this->mixedEnum($schema, $diagnostics);
        }
```

```php
    private function mixedEnum(Schema $schema, Diagnostics $diagnostics): TypeModel
    {
        $declared = array_map(static fn (SchemaType $type): string => $type->value(), $schema->nonNullTypes());
        $numeric = in_array(SchemaType::INTEGER, $declared, true) || in_array(SchemaType::NUMBER, $declared, true);
        if ($declared !== [] && (!in_array(SchemaType::STRING, $declared, true) || !$numeric)) {
            $diagnostics->error('"type" does not match the enum values, which are strings and integers.', $schema->location()->child('type'));

            return new MixedType();
        }

        $diagnostics->warning(
            'The enum mixes strings and integers, which no PHP enum can back; the property takes either.',
            $schema->location()->child('enum'),
        );
        $values = array_values(array_filter($schema->enum() ?? [], static fn ($value): bool => $value !== null));
        $strings = array_values(array_unique(array_filter($values, 'is_string')));
        $ints = array_values(array_unique(array_filter($values, 'is_int')));

        return new UnionType(ScalarType::string(LiteralType::union($strings)), ScalarType::int(LiteralType::union($ints)));
    }
```

`admitsNull()` уже делает тип nullable, если в `enum` есть `null`. В `EnumBuilder::backing()` удалить проверку
`$strings !== 0 && $strings !== count($values)` с ошибкой: после изменения `isEnum` она недостижима (Infection
отметит её как непокрытую).

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter 'TypeMapperTest|EnumBuilderTest|BuildTest'`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
make fix && vendor/bin/phpunit
git commit -am "feat(builder): a mixed enum becomes a union of its literals instead of an error"
```

### Task 4: `x-enum-varnames`

**Files:**
- Modify: `src/Domain/Builder/EnumBuilder.php` (`build`, новый `varnames`)
- Modify: `src/Domain/Builder/ExtensionVocabulary.php` (`KNOWN`, `CLASS_SCHEMA`, `ENUM_SCHEMA`, `DECLARATION`)
- Test: `tests/Unit/Domain/Builder/EnumBuilderTest.php`, `tests/Unit/Application/Config/ConfigFactoryTest.php`

**Interfaces:**
- Consumes: `NameResolver::enumCaseName(int|string $value): ?string` (имя из строки varname).

- [ ] **Step 1: Write the failing test**

В `EnumBuilderTest` (по образцу его тестов, через `ModelFixture::build` и модель enum из `Output::enums()`):

```php
    public function testNamesCasesAfterXEnumVarnames(): void
    {
        $output = ModelFixture::build(['Code' => ['type' => 'integer', 'enum' => [1, 2, null], 'x-enum-varnames' => ['Active', 'Blocked']]]);

        self::assertSame([], ModelFixture::messages($output));
        self::assertSame(['ACTIVE' => 1, 'BLOCKED' => 2], $this->cases($output, 'App\Dto\Code'));
    }

    public function testCountsVarnamesByPositionLikeOpenapiGenerator(): void
    {
        $output = ModelFixture::build(['Code' => ['type' => 'integer', 'enum' => [1, 1, 2], 'x-enum-varnames' => ['One', 'Again', 'Two']]]);

        self::assertSame(['ONE' => 1, 'TWO' => 2], $this->cases($output, 'App\Dto\Code'));
    }

    public function testRejectsVarnamesOfTheWrongLength(): void
    {
        $output = ModelFixture::build(['Code' => ['type' => 'integer', 'enum' => [1, 2], 'x-enum-varnames' => ['One']]]);

        self::assertSame(
            ['error /project/api/openapi.yaml#/components/schemas/Code/x-enum-varnames: "x-enum-varnames" must list one name per enum value (2).'],
            ModelFixture::messages($output),
        );
    }

    public function testRejectsAVarnameWithoutUsableCharacters(): void
    {
        $output = ModelFixture::build(['Code' => ['type' => 'integer', 'enum' => [1, 2], 'x-enum-varnames' => ['One', '%%']]]);

        self::assertSame(
            ['error /project/api/openapi.yaml#/components/schemas/Code/x-enum-varnames/1: Name "%%" has no characters usable in a case name.'],
            ModelFixture::messages($output),
        );
    }
```

Хелпер `cases()` — если в тесте его нет, добавить: `EnumModel::cases()` → `[name => value]`.

В `ConfigFactoryTest`: алиас `x-enum-varnames` отклоняется с сообщением «Alias "x-enum-varnames" must be an "x-" key
outside the reserved "x-php-" and "x-dto-" prefixes.».

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter 'Varname|varnames'`
Expected: FAIL — case `VALUE_1` вместо `ACTIVE`.

- [ ] **Step 3: Write minimal implementation**

`ExtensionVocabulary`: добавить `'x-enum-varnames'` в `KNOWN`, `CLASS_SCHEMA`, `ENUM_SCHEMA`, `DECLARATION` — рядом с
`'x-enum-descriptions'`.

`EnumBuilder::build()`: перед циклом по `$values` —
`$varnames = $this->varnames($schema, $diagnostics); if ($varnames === null) { return null; }`. Имя case'а в цикле:

```php
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
```

Существующая ветка `$case === null` (для значения) остаётся следом. Новый метод:

```php
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
```

Ошибка «both become case» остаётся прежней.

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter 'EnumBuilderTest|ConfigFactoryTest|ExtensionVocabulary'`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
make fix && vendor/bin/phpunit
git commit -am "feat(builder): x-enum-varnames names the enum cases"
```

### Task 5: предупреждения о неподдерживаемых ключевых словах

**Files:**
- Create: `src/Domain/Builder/UnsupportedKeywords.php`
- Modify: `src/Domain/Builder/SchemaParser.php` (`parse`)
- Test: `tests/Unit/Domain/Builder/SchemaParserTest.php`, `tests/Unit/Application/Service/Model/BuildTest.php`

**Interfaces:**
- Produces: `UnsupportedKeywords::warning(string $keyword): ?string` — текст предупреждения или `null`.

- [ ] **Step 1: Write the failing test**

`SchemaParserTest`:

```php
    public function testWarnsAboutKeywordsWithNoEffectOnTheType(): void
    {
        $diagnostics = new Diagnostics();
        (new SchemaParser())->parse(
            ['type' => 'object', 'patternProperties' => ['^x' => ['type' => 'string']], 'not' => ['type' => 'null'], 'pattern' => '^a'],
            new SchemaLocation('/s.yaml', '/S'),
            $diagnostics,
        );

        self::assertSame(
            [
                'warning /s.yaml#/S/patternProperties: "patternProperties" is not supported and has no effect on the generated type.',
                'warning /s.yaml#/S/not: "not" is not supported and has no effect on the generated type.',
            ],
            array_map(static fn (Diagnostic $d): string => $d->toString(), $diagnostics->all()),
        );
    }

    public function testPointsOpenApi30NullableAtTheTypeList(): void
    {
        $diagnostics = new Diagnostics();
        (new SchemaParser())->parse(['type' => 'string', 'nullable' => true], new SchemaLocation('/s.yaml', '/S'), $diagnostics);

        self::assertSame(
            ['warning /s.yaml#/S/nullable: "nullable" is OpenAPI 3.0 and has no effect in 3.1; write type: [T, \'null\'].'],
            array_map(static fn (Diagnostic $d): string => $d->toString(), $diagnostics->all()),
        );
    }
```

`BuildTest`:

```php
    public function testWarnsOncePerSchemaReachedTwice(): void
    {
        $output = ModelFixture::build([
            'Odd' => ['type' => 'object', 'properties' => ['a' => ['type' => 'string']], 'not' => ['required' => ['b']]],
            'X' => ['type' => 'object', 'properties' => ['one' => ['$ref' => '#/components/schemas/Odd'], 'two' => ['$ref' => '#/components/schemas/Odd']]],
        ]);

        self::assertSame(
            ['warning /project/api/openapi.yaml#/components/schemas/Odd/not: "not" is not supported and has no effect on the generated type.'],
            ModelFixture::messages($output),
        );
    }
```

Конструктор `SchemaLocation` и формат `toString()` — по существующим тестам парсера; подставить их фактическую форму.

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter 'testWarnsAboutKeywordsWithNoEffectOnTheType|testPointsOpenApi30NullableAtTheTypeList|testWarnsOncePerSchemaReachedTwice'`
Expected: FAIL — диагностик нет.

- [ ] **Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Builder;

/**
 * JSON Schema keywords that shape a value in ways no generated type expresses; silently dropping them would hide
 * that the DTO admits more than the schema. Keywords the Symfony bridge turns into constraints are not listed.
 */
final class UnsupportedKeywords
{
    private const NO_EFFECT = [
        'prefixItems', 'patternProperties', 'if', 'then', 'else', 'not', 'dependentSchemas', 'dependentRequired',
        'unevaluatedProperties', 'unevaluatedItems', 'contains', 'propertyNames',
    ];

    private function __construct()
    {
    }

    public static function warning(string $keyword): ?string
    {
        if ($keyword === 'nullable') {
            return '"nullable" is OpenAPI 3.0 and has no effect in 3.1; write type: [T, \'null\'].';
        }

        return in_array($keyword, self::NO_EFFECT, true) ? sprintf('"%s" is not supported and has no effect on the generated type.', $keyword) : null;
    }
}
```

`SchemaParser::parse()`, перед `$builder->keyword($keyword, $value);`:

```php
            $unsupported = UnsupportedKeywords::warning($keyword);
            if ($unsupported !== null) {
                $diagnostics->warning($unsupported, $location->child($keyword));
            }
```

Если `testWarnsOncePerSchemaReachedTwice` покажет два предупреждения, значит загрузчик разбирает цель `$ref`
повторно (`Application\Service\Schemas\Load\Action`, строка с `$this->parser->parse($node, $target, …)`). Тогда
причину устранить там (кэш разобранных схем по location), а не дедупликацией диагностик.

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit`
Expected: PASS. Если golden-проект или Petstore начнут выдавать новые предупреждения, проверить, что они верны, и
обновить ожидания.

- [ ] **Step 5: Commit**

```bash
make fix && vendor/bin/phpunit
git add src/Domain/Builder/UnsupportedKeywords.php src/Domain/Builder/SchemaParser.php tests
git commit -m "feat(builder): warn about keywords that have no effect on the generated type"
```

### Task 6: вынос инлайн-членов `oneOf`/`anyOf` у свойств

**Files:**
- Modify: `src/Application/Service/Model/Build/Action.php` (`hoist`, `inline` → `inlines`, новый `declareInline`)
- Modify: `src/Domain/Builder/ExtensionVocabulary.php` (`checkValues` — также члены `oneOf`/`anyOf`)
- Test: `tests/Unit/Application/Service/Model/BuildTest.php`

**Interfaces:**
- Produces (private, used by Task 7):
  - `inlines(Schema $schema, string $suffix, Diagnostics $diagnostics, Registry $registry): list<array{Schema, string, ?string}>` — схема, суффикс имени, имя из `title` (или `null`);
  - `declareInline(Schema $candidate, ?string $derived, string $wireName, string $namespace, int $source, Registry $registry, EnumBuilder $enums, Diagnostics $diagnostics): void`.

- [ ] **Step 1: Write the failing test**

```php
    public function testHoistsInlineMembersOfAPropertyUnion(): void
    {
        $output = ModelFixture::build(['Holder' => ['type' => 'object', 'properties' => ['either' => ['oneOf' => [
            ['type' => 'object', 'title' => 'by id', 'properties' => ['id' => ['type' => 'integer']]],
            ['type' => 'object', 'properties' => ['code' => ['type' => 'string']]],
            ['type' => 'string'],
        ]]]]]);

        self::assertSame([], ModelFixture::messages($output));
        self::assertSame(['either: App\Dto\ById|App\Dto\HolderEitherOption2|string|null'], ModelFixture::classes($output)['App\Dto\Holder']);
        self::assertSame(['id: int|null'], ModelFixture::classes($output)['App\Dto\ById']);
        self::assertSame(['code: string|null'], ModelFixture::classes($output)['App\Dto\HolderEitherOption2']);
    }

    public function testHoistsUnionMembersInsideItems(): void
    {
        $output = ModelFixture::build(['Holder' => ['type' => 'object', 'properties' => ['list' => ['type' => 'array', 'items' => ['anyOf' => [
            ['type' => 'object', 'properties' => ['a' => ['type' => 'string']]],
            ['type' => 'object', 'properties' => ['b' => ['type' => 'string']]],
        ]]]]]]);

        self::assertSame([], ModelFixture::messages($output));
        self::assertSame(['list: list<App\Dto\HolderListItemOption1|App\Dto\HolderListItemOption2>|null'], ModelFixture::classes($output)['App\Dto\Holder']);
    }

    public function testHoistsInlineEnumMembers(): void
    {
        $output = ModelFixture::build(['Holder' => ['type' => 'object', 'properties' => ['either' => ['oneOf' => [
            ['type' => 'string', 'enum' => ['a', 'b']],
            ['type' => 'integer'],
        ]]]]]);

        self::assertSame([], ModelFixture::messages($output));
        self::assertSame(['App\Dto\HolderEitherOption1'], ModelFixture::enums($output));
    }

    public function testNamesNestedInlineObjectsAfterTheHoistedMember(): void
    {
        $output = ModelFixture::build(['Holder' => ['type' => 'object', 'properties' => ['either' => ['oneOf' => [
            ['type' => 'object', 'properties' => ['address' => ['type' => 'object', 'properties' => ['city' => ['type' => 'string']]]]],
            ['type' => 'string'],
        ]]]]]);

        self::assertSame([], ModelFixture::messages($output));
        self::assertArrayHasKey('App\Dto\HolderEitherOption1Address', ModelFixture::classes($output));
    }

    public function testReportsATitleThatCollides(): void
    {
        $output = ModelFixture::build([
            'User' => ['type' => 'object', 'properties' => ['n' => ['type' => 'string']]],
            'Holder' => ['type' => 'object', 'properties' => ['either' => ['oneOf' => [
                ['type' => 'object', 'title' => 'User', 'properties' => ['id' => ['type' => 'integer']]],
                ['type' => 'string'],
            ]]]],
        ]);

        self::assertCount(1, array_filter(ModelFixture::messages($output), static fn (string $m): bool => strpos($m, 'x-php-class-name') !== false));
    }

    public function testRefusesInlineMembersOfAnInlineDiscriminatedUnion(): void
    {
        $output = ModelFixture::build(['Holder' => ['type' => 'object', 'properties' => ['pet' => [
            'oneOf' => [['type' => 'object', 'properties' => ['kind' => ['type' => 'string']]]],
            'discriminator' => ['propertyName' => 'kind'],
        ]]]]);

        self::assertSame(
            ['error /project/api/openapi.yaml#/components/schemas/Holder/properties/pet/oneOf/0: An inline object in a oneOf or anyOf with a discriminator is not generated: the discriminator mapping needs a $ref. Move it to components/schemas.'],
            ModelFixture::messages($output),
        );
    }
```

Точную строку коллизии взять из `Registry::claim()`. Порядок членов union в `describe()` — по `UnionType`; если он
другой, поправить ожидание, а не код.

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter 'testHoists|testNamesNested|testReportsATitleThatCollides|testRefusesInlineMembers'`
Expected: FAIL — «This inline object is not generated (only properties of generated classes get one)…».

- [ ] **Step 3: Write minimal implementation**

`inline()` заменить на:

```php
    /**
     * The inline objects and enums a property holds, looking through arrays (`…Item`), maps (`…Value`) and the members
     * of a union (`…Option<N>`, or the member's title).
     *
     * @return list<array{Schema, string, ?string}> schema, suffix of the derived name, name from the title
     */
    private function inlines(Schema $schema, string $suffix, Diagnostics $diagnostics, Registry $registry): array
    {
        // A discriminated union becomes a base class only as a named schema (spec §5.3); inline it is a union type.
        if ((SchemaShape::isClass($schema) && !SchemaShape::isDiscriminated($schema)) || SchemaShape::isEnum($schema)) {
            return [[$schema, $suffix, null]];
        }

        if (SchemaShape::hasUnion($schema) && $schema->allOf() === [] && $schema->propertyNames() === []) {
            return $this->unionMembers($schema, $suffix, $diagnostics, $registry);
        }

        $items = $schema->items();
        if ($items instanceof Schema) {
            return $this->inlines($items, $suffix . 'Item', $diagnostics, $registry);
        }

        $values = $schema->additionalProperties();

        return $values instanceof Schema ? $this->inlines($values, $suffix . 'Value', $diagnostics, $registry) : [];
    }

    /**
     * @return list<array{Schema, string, ?string}>
     */
    private function unionMembers(Schema $schema, string $suffix, Diagnostics $diagnostics, Registry $registry): array
    {
        $found = [];
        foreach (array_merge($schema->oneOf(), $schema->anyOf()) as $index => $member) {
            if (SchemaShape::isDiscriminated($schema) && SchemaShape::isClass($member)) {
                $diagnostics->error(
                    'An inline object in a oneOf or anyOf with a discriminator is not generated: the discriminator mapping needs a $ref. Move it to components/schemas.',
                    $member->location(),
                );
                // Abandoned, so the type mapper does not report it a second time.
                $registry->abandon($member);

                continue;
            }

            foreach ($this->inlines($member, $suffix . 'Option' . ($index + 1), $diagnostics, $registry) as [$candidate, $candidateSuffix, $title]) {
                $found[] = [$candidate, $candidateSuffix, $candidate === $member ? $this->title($member) : $title];
            }
        }

        return $found;
    }

    private function title(Schema $member): ?string
    {
        $title = $member->hasKeyword('title') ? $member->keyword('title') : null;

        return is_string($title) ? $this->names->className($title) : null;
    }
```

Тело цикла `foreach ($candidates …)` из `hoist()` вынести в `declareInline()`; производное имя —
`$title ?? ($baseName === null ? null : $baseName . $suffix)`. `ExtensionVocabulary::checkValues()`: в цикл по
`[$schema->items(), $schema->additionalProperties()]` добавить члены `oneOf`/`anyOf` без `$ref`, чтобы
`x-php-class-name` на инлайн-члене проверялся как ключ объявления.

Проверить, что `isDiscriminated()` верен для инлайн-свойства с `oneOf` и `discriminator` (свойство без `properties`);
если `isClass()` для такой схемы истинен, первая ветка `inlines()` его уже отсекает через `!isDiscriminated` — тогда
`unionMembers` получит её как union, как и задумано.

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
make fix && vendor/bin/phpunit
git commit -am "feat(build): hoist inline objects and enums from the members of a property's union"
```

### Task 7: вынос инлайн-схем из компонентных алиасов

**Files:**
- Modify: `src/Application/Service/Model/Build/Action.php` (`__invoke`: ветка `!$isClass && !$isEnum`)
- Test: `tests/Unit/Application/Service/Model/BuildTest.php`

**Interfaces:**
- Consumes: `inlines()`, `declareInline()` из Task 6.

- [ ] **Step 1: Write the failing test**

```php
    public function testHoistsInlineSchemasOfANamedAlias(): void
    {
        $output = ModelFixture::build([
            'Pets' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]]],
            'Tags' => ['type' => 'object', 'additionalProperties' => ['type' => 'string', 'enum' => ['a', 'b']]],
            'Shape' => ['oneOf' => [['type' => 'object', 'properties' => ['r' => ['type' => 'number']]], ['type' => 'object', 'properties' => ['w' => ['type' => 'number']]]]],
            'Holder' => ['type' => 'object', 'properties' => [
                'pets' => ['$ref' => '#/components/schemas/Pets'],
                'tags' => ['$ref' => '#/components/schemas/Tags'],
                'shape' => ['$ref' => '#/components/schemas/Shape'],
            ]],
        ]);

        self::assertSame([], ModelFixture::messages($output));
        self::assertSame(
            ['pets: list<App\Dto\PetsItem>|null', 'tags: array<array-key, App\Dto\TagsValue>|null', 'shape: App\Dto\ShapeOption1|App\Dto\ShapeOption2|null'],
            ModelFixture::classes($output)['App\Dto\Holder'],
        );
        self::assertSame(['App\Dto\TagsValue'], ModelFixture::enums($output));
    }

    public function testLeavesASkippedAliasAlone(): void
    {
        $output = ModelFixture::build(['Pets' => ['type' => 'array', 'x-php-skip' => true, 'items' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]]]]);

        self::assertArrayNotHasKey('App\Dto\PetsItem', ModelFixture::classes($output));
    }
```

Форму `describe()` для map сверить с `MapType::describe()`.

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter 'testHoistsInlineSchemasOfANamedAlias|testLeavesASkippedAliasAlone'`
Expected: FAIL — ошибка «This inline object is not generated…» на `Pets/items`.

- [ ] **Step 3: Write minimal implementation**

В `__invoke()` заменить

```php
            if (!$isClass && !$isEnum) {
                continue;
            }
```

на

```php
            if (!$isClass && !$isEnum) {
                $this->hoistFromAlias($schema, $resolved->name(), $config->sources()[$source]->namespace(), $source, $registry, $enums, $diagnostics);

                continue;
            }
```

```php
    /**
     * A named non-object schema is inlined where it is used, but its inline objects and enums need a name of their own:
     * `<Alias>Item`, `<Alias>Value`, `<Alias>Option<N>`.
     */
    private function hoistFromAlias(Schema $alias, string $aliasName, string $namespace, int $source, Registry $registry, EnumBuilder $enums, Diagnostics $diagnostics): void
    {
        $base = $this->names->className($aliasName);
        foreach ($this->inlines($alias, '', $diagnostics, $registry) as [$candidate, $suffix, $title]) {
            $this->declareInline($candidate, $title ?? ($base === null ? null : $base . $suffix), $aliasName, $namespace, $source, $registry, $enums, $diagnostics);
        }
    }
```

`$resolved->name()` у компонентной схемы — её имя в `components/schemas` (проверить тип: если `?string`, для `null`
не выносить). `x-php-skip` на алиасе уже обработан выше (`continue` до этой ветки).

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
make fix && vendor/bin/phpunit
git commit -am "feat(build): hoist inline objects and enums from named aliases"
```

### Task 8: golden-проект и проверка на целях

**Files:**
- Modify: `tests/Fixtures/Projects/golden/api/openapi.yaml`
- Create/Modify: `tests/Fixtures/Projects/golden/expected/{7.4,8.0,8.1,8.2,8.5}/*.php.golden`

- [ ] **Step 1: Добавить схемы в конец `components/schemas` golden-спецификации**

```yaml
    Payment:
      type: object
      required: [kind, method]
      properties:
        kind: {const: card}
        method:
          oneOf:
            - {type: object, title: card details, required: [number], properties: {number: {type: string}}}
            - {type: object, properties: {iban: {type: string}}}
        priority: {enum: [low, 1, high]}
        level: {type: integer, enum: [1, 2], x-enum-varnames: [Basic, Premium]}
    Labels:
      type: array
      items: {type: object, properties: {text: {type: string}}}
```

- [ ] **Step 2: Run the golden test to see it fail**

Run: `vendor/bin/phpunit --filter GoldenProjectTest`
Expected: FAIL — новые файлы `Payment.php`, `CardDetails.php`, `PaymentMethodOption2.php`, `PaymentLevel.php`,
`LabelsItem.php` отсутствуют в ожиданиях.

- [ ] **Step 3: Обновить снапшоты и просмотреть вывод**

Run: `UPDATE_SNAPSHOTS=1 vendor/bin/phpunit --filter GoldenProjectTest`
Проверить в `expected/8.2/Payment.php.golden`: `public string $kind` с `@phpstan-var 'card'`, `method` —
`CardDetails|PaymentMethodOption2`, `priority` — `string|int|null` с `@phpstan-var 'low'|'high'|1|null`; в 7.4 —
`method` без нативного типа; `PaymentLevel` — case'ы `BASIC`/`PREMIUM`.

- [ ] **Step 4: Проверить вывод на целевых версиях**

Run: `make test-targets` (вне песочницы — нужен Docker)
Expected: `php -l` и PHPStan max проходят на 7.4…8.5.

- [ ] **Step 5: Commit**

```bash
git add tests/Fixtures/Projects/golden
git commit -m "test: golden project covers const, mixed enums, varnames and hoisted union members"
```

### Task 9: документация, база знаний, финальные проверки

**Files:**
- Modify: `docs/openapi-support.md`, `docs/x-extensions.md`, `CHANGELOG.md`, `.claude/docs/known-issues.md`

- [ ] **Step 1: Документация**
  - `docs/openapi-support.md`:
    - в таблицу типов добавить `const` (литерал PHPDoc) и смешанный `enum` (`string|int` с литералами);
    - строку «an inline object or enum…» расширить на члены `oneOf`/`anyOf` (имена `title` / `…Option<N>`) и на
      `items`/`additionalProperties` алиасов;
    - из «Known limitations» убрать пункт про инлайн-объекты в `oneOf`/`anyOf`, оставив случай с дискриминатором;
    - пункт «Not interpreted» переписать: эти ключевые слова теперь дают предупреждение; `const` из списка убрать;
    - пункт про `nullable: true` — «gives a warning».
  - `docs/x-extensions.md`: строка таблицы и раздел `x-enum-varnames` с примером; в разделе алиасов — запрет
    `x-enum-varnames`.
  - `CHANGELOG.md`, `## [Unreleased]` → `### Added` (вынос членов union и алиасов, `const`, `x-enum-varnames`,
    предупреждения), `### Changed` (смешанный enum — union вместо ошибки).
  - `.claude/docs/known-issues.md`: правило именования вынесенных членов, позиционный счёт `x-enum-varnames`,
    `LiteralType` не экранирует, а оставляет небезопасные строки без уточнения.

- [ ] **Step 2: Полный прогон**

Run: `make fix && make verify && make infection && make bc-check` (вне песочницы)
Expected: всё зелёное; MSI 100% (выжившие мутанты закрыть тестами или обоснованным ignore с комментарием).

- [ ] **Step 3: Commit**

```bash
git commit -am "docs: const, mixed enums, x-enum-varnames and hoisted union members"
```
