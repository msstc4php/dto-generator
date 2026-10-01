# DTO Generator — этап 2b: Builder (граф схем → IR). План реализации

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Из `GeneratorConfig`, `TargetProfile` и `SchemaGraph` (этап 2a) построить IR — список `ClassModel` с `PropertyModel`, по одному классу на каждую объектную схему, — и выдать все проблемы как диагностику с местом.

**Architecture:** Три службы DomainService в `Domain/Builder` и один use-case Application:
- `NameResolver` — имена;
- `TypeMapper` — схема → `TypeModel`, правила spec §5.1;
- `ClassBuilder` — схема → `ClassModel`, правила §5.2, §5.4, §5.5, проверка словаря `x-` §7;
- `Service/Model/Build` — регистрирует классы по всему графу, ловит коллизии имён и собирает IR.

Схемы, загруженные только как цели `$ref`, тоже становятся классами в namespace источника-владельца (решение владельца). Композиция, enum, `additionalProperties`-схема и инлайн-объекты появятся в этапе 4. До него они дают диагностику и тип `mixed`.

**Tech Stack:** PHP ≥ 7.4, PHPUnit 9.6, PHPStan 2 max (`phpVersion: 70400`), deptrac, Rector, CS-Fixer, Infection.

**Spec:** `docs/specs/2026-10-01-dto-generator-design.md`:
- §5.1 — типы, `format`, `x-php-type`, nullable, массивы, `$ref`;
- §5.2 — обязательность и default;
- §5.4 — именование;
- §5.5 — `description`, `deprecated`;
- §7 — словарь `x-` ядра без атрибутов;
- §13, этап 2, вторая половина.

Атрибуты (`x-php-attributes`, алиасы) — этап 5. Композиция — этап 4.

## Global Constraints

- Исходники и тесты работают на PHP 7.4. Запрещены: `enum`, `readonly`, атрибуты, `match`, union/`mixed`/`static` в сигнатурах, promoted-свойства, именованные аргументы, функции PHP 8+, висячая запятая в списках параметров.
- PHPStan `level: max` без ignore. Тесты исключений проверяют класс и подстроку сообщения.
- Ошибки пользовательского ввода — только `Diagnostics` с `SchemaLocation`, никаких исключений. У каждой `Diagnostic` есть место.
- Сравнение идентификаторов — `Identifier::asciiLower()`. Смена регистра первой буквы — `Identifier::asciiUpperFirst()/asciiLowerFirst()` (Task 1), не `ucfirst/lcfirst`: на 7.4 они зависят от локали.
- Порядок вывода детерминирован: порядок `SchemaGraph::all()`, внутри класса — порядок `properties` в схеме.
- Комментарии — на английском, только «почему». Документация — на русском. Даты — UTC.
- Коммит заканчивается строкой `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- Гейт каждой задачи: `make fix && make verify`. Docker-цели и `make infection` запускаются вне песочницы. В конце этапа MSI ≥ 99 %.

## Review Focus

1. **Два wire-имени, сводящиеся к одному PHP-имени** (`user_name` и `userName`, `URL` и `url`). Ожидается ошибка с подсказкой `x-php-name`, а не исключение из `ClassModel`. Тест — Task 3.
2. **Компоненты, различающиеся только регистром или разделителями** (`User` и `user`, `user_profile` и `UserProfile`), дают один класс PHP. Ожидается ошибка с подсказкой `x-php-class-name`. Тест — Task 4.
3. **Default, который нельзя выразить константой PHP** (дата по `format: date-time`, объект у map, `INF` из YAML `.inf`). Ожидается предупреждение или ошибка и `= null`, а не невалидный код на этапе 3. Тест — Task 3.
4. **Циклический alias** (`A: {$ref: B}`, `B: {$ref: A}`). Ожидается ошибка, а не бесконечная рекурсия. Тест — Task 2.
5. **Ссылка на схему, исключённую через `x-php-skip`**. Ожидается предупреждение и `mixed`, а не класс-призрак в типе. Тест — Task 4.

---

## Карта файлов

```
src/Domain/Model/Identifier.php           (+asciiUpperFirst, +asciiLowerFirst)
src/Domain/Builder/NameResolver.php       schema/wire name → PHP identifier
src/Domain/Builder/SchemaShape.php        class-like? unsupported keyword?
src/Domain/Builder/TypeMapper.php         Schema → TypeModel (spec §5.1)
src/Domain/Builder/ClassBuilder.php       ResolvedSchema → ClassModel (§5.2, §5.4, §5.5, §7)
src/Application/Service/Model/Build/      Action, Input, Output, BuiltClass
tests/Support/ModelFixture.php            YAML-like components → Build output, readable summary
tests/Unit/Domain/Builder/NameResolverTest.php
tests/Unit/Domain/Builder/TypeMapperTest.php
tests/Unit/Domain/Builder/ClassBuilderTest.php
tests/Unit/Application/Service/Model/BuildTest.php
tests/Integration/PetstoreLoadingTest.php (extended)
```

---

### Task 1: Имена — `NameResolver`

**Files:**
- Modify: `src/Domain/Model/Identifier.php`
- Create: `src/Domain/Builder/NameResolver.php`
- Test: `tests/Unit/Domain/Builder/NameResolverTest.php`; Modify: `tests/Unit/Domain/Model/IdentifierTest.php`

**Interfaces:**
- Consumes: `Identifier::isReserved()`, `Identifier::asciiLower()`.
- Produces:
  - `Identifier::asciiUpperFirst(string): string` и `Identifier::asciiLowerFirst(string): string` — меняют регистр только ASCII.
  - `NameResolver::className(string $schemaName): ?string` — PascalCase. Ведущая цифра получает префикс `_`, зарезервированное слово — суффикс `_`. `null`, если не осталось ни одного символа идентификатора.
  - `NameResolver::propertyName(string $wireName): ?string` — camelCase. Первое слово целиком в верхнем регистре (`URL`) приводится к нижнему целиком. Ведущая цифра получает префикс `_`, `this` превращается в `this_`. `null`, если символов не осталось.

- [ ] **Step 1: Написать падающие тесты**

В `tests/Unit/Domain/Model/IdentifierTest.php` добавить тест:

```php
    public function testChangesTheCaseOfTheFirstAsciiLetterOnly(): void
    {
        self::assertSame('User', Identifier::asciiUpperFirst('user'));
        self::assertSame('userName', Identifier::asciiLowerFirst('UserName'));
        self::assertSame("\xC3\xA4b", Identifier::asciiUpperFirst("\xC3\xA4b"));
        self::assertSame('', Identifier::asciiUpperFirst(''));
        self::assertSame('', Identifier::asciiLowerFirst(''));
    }
```

`tests/Unit/Domain/Builder/NameResolverTest.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Domain\Builder;

use MSSTC4PHP\DtoGenerator\Domain\Builder\NameResolver;
use PHPUnit\Framework\TestCase;

final class NameResolverTest extends TestCase
{
    /**
     * @dataProvider classNames
     */
    public function testDerivesPascalCaseClassNames(string $schemaName, ?string $expected): void
    {
        self::assertSame($expected, (new NameResolver())->className($schemaName));
    }

    /**
     * @return array<string, array{string, string|null}>
     */
    public static function classNames(): array
    {
        return [
            'plain' => ['user', 'User'],
            'snake case' => ['user_profile', 'UserProfile'],
            'kebab case' => ['user-profile', 'UserProfile'],
            'spaces' => ['My Type', 'MyType'],
            'slash' => ['a/b', 'AB'],
            'acronym kept' => ['HTTPResponse', 'HTTPResponse'],
            'leading digit' => ['200', '_200'],
            'leading digit word' => ['2fa_settings', '_2faSettings'],
            'reserved word' => ['list', 'List_'],
            'reserved type name' => ['Object', 'Object_'],
            'utf-8 letters' => ["Gr\xC3\xB6\xC3\x9Fe", "Gr\xC3\xB6\xC3\x9Fe"],
            'nothing usable' => ['***', null],
        ];
    }

    /**
     * @dataProvider propertyNames
     */
    public function testDerivesCamelCasePropertyNames(string $wireName, ?string $expected): void
    {
        self::assertSame($expected, (new NameResolver())->propertyName($wireName));
    }

    /**
     * @return array<string, array{string, string|null}>
     */
    public static function propertyNames(): array
    {
        return [
            'snake case' => ['user_name', 'userName'],
            'pascal case' => ['UserName', 'userName'],
            'kebab case' => ['e-mail', 'eMail'],
            'all caps' => ['URL', 'url'],
            'all caps words' => ['URL_PATH', 'urlPATH'],
            'mixed caps' => ['userID', 'userID'],
            'leading digit' => ['200', '_200'],
            'this' => ['this', 'this_'],
            'reserved words are fine for properties' => ['class', 'class'],
            'json-ld marker' => ['@type', 'type'],
            'nothing usable' => ['---', null],
        ];
    }
}
```

> Кейс `'all caps words'`: в верхнем регистре целиком приводится только **первое** слово, остальные слова только получают заглавную первую букву. Это правило задокументировано; полная нормализация акронимов не входит в v1.

- [ ] **Step 2: Убедиться, что тесты падают**

Run: `vendor/bin/phpunit --filter 'NameResolverTest|IdentifierTest'`
Expected: FAIL — `Call to undefined method ...Identifier::asciiUpperFirst()` и `Class "...\Builder\NameResolver" not found`.

- [ ] **Step 3: Реализовать**

В `src/Domain/Model/Identifier.php` добавить в класс:

```php
    public static function asciiUpperFirst(string $value): string
    {
        if ($value === '') {
            return '';
        }

        return strtr($value[0], 'abcdefghijklmnopqrstuvwxyz', 'ABCDEFGHIJKLMNOPQRSTUVWXYZ') . (string) substr($value, 1);
    }

    public static function asciiLowerFirst(string $value): string
    {
        if ($value === '') {
            return '';
        }

        return strtr($value[0], 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz') . (string) substr($value, 1);
    }
```

`src/Domain/Builder/NameResolver.php`:

```php
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
```

- [ ] **Step 4: Прогнать тесты**

Run: `vendor/bin/phpunit --filter 'NameResolverTest|IdentifierTest'`
Expected: PASS.

- [ ] **Step 5: Гейт и коммит**

Run: `make fix && make verify`
Expected: зелёное.

```bash
git add src/Domain/Model/Identifier.php src/Domain/Builder/NameResolver.php tests/Unit/Domain/Builder/NameResolverTest.php tests/Unit/Domain/Model/IdentifierTest.php
git commit -m "feat(builder): NameResolver for class and property names

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: Типы — `SchemaShape` и `TypeMapper`

**Files:**
- Create: `src/Domain/Builder/SchemaShape.php`, `src/Domain/Builder/TypeMapper.php`
- Create: `tests/Support/GraphFixture.php`
- Test: `tests/Unit/Domain/Builder/TypeMapperTest.php`

**Interfaces:**
- Consumes:
  - `SchemaGraph::resolve(ReferenceUse)`, `ReferenceUse`, `Schema` и `SchemaType` (этап 2a);
  - `TargetProfile::supports()` / `dateTimeClass()`, `Capability::RESERVED_NAMESPACE_SEGMENTS`;
  - модели типов: `ClassName`, `ScalarType`, `ClassType`, `ListType`, `MapType`, `UnionType`, `NullableType`, `MixedType`;
  - `Diagnostics`, тестовый `ConfigMother`.
- Produces:
  - `SchemaShape::unsupportedKeyword(Schema): ?string` — `'enum'|'allOf'|'oneOf'|'anyOf'|'discriminator'|'additionalProperties'|null`.
  - `SchemaShape::isClass(Schema): bool` — схема без `$ref`, без неподдержанных keyword'ов, с `properties` и с типом `object` либо без типа.
  - `TypeMapper::__construct(SchemaGraph $graph, array<string, ClassName> $classes, array<string, true> $skipped, TargetProfile $target, array<int|string, ClassName> $formats)`. Ключи `$classes` и `$skipped` — `SchemaLocation::toString()`.
  - `TypeMapper::map(Schema, Diagnostics): TypeModel`.
  - `TypeMapper::nullable(TypeModel): TypeModel` (static) — `mixed` и уже nullable-тип не оборачивает.
  - Тестовый `Tests\Support\GraphFixture::load(array $schemas, array $extraDocuments = []): SchemaGraph` — бросает `LogicException`, если загрузка дала ошибки.

- [ ] **Step 1: Написать фикстуру и падающий тест**

`tests/Support/GraphFixture.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Support;

use LogicException;
use MSSTC4PHP\DtoGenerator\Application\Config\SourceConfig;
use MSSTC4PHP\DtoGenerator\Application\Service\Schemas\Load\Action;
use MSSTC4PHP\DtoGenerator\Application\Service\Schemas\Load\Input;
use MSSTC4PHP\DtoGenerator\Domain\Builder\SchemaParser;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostic;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaGraph;

final class GraphFixture
{
    public const SPEC = '/project/api/openapi.yaml';

    /**
     * @param array<int|string, array<array-key, mixed>> $schemas components/schemas of the spec
     * @param array<string, array<array-key, mixed>> $extraDocuments further files by absolute path
     */
    public static function load(array $schemas, array $extraDocuments = [], ?SourceConfig $source = null): SchemaGraph
    {
        $documents = [self::SPEC => ['openapi' => '3.1.0', 'components' => ['schemas' => $schemas]]] + $extraDocuments;
        $config = ConfigMother::config($source ?? ConfigMother::source(self::SPEC));
        $output = (new Action(new InMemoryDocumentLoader($documents), new SchemaParser()))(new Input($config));
        if ($output->diagnostics()->hasErrors()) {
            throw new LogicException(implode("\n", array_map(static fn (Diagnostic $d): string => $d->toString(), $output->diagnostics()->all())));
        }

        return $output->graph();
    }
}
```

`tests/Unit/Domain/Builder/TypeMapperTest.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Domain\Builder;

use MSSTC4PHP\DtoGenerator\Domain\Builder\SchemaShape;
use MSSTC4PHP\DtoGenerator\Domain\Builder\TypeMapper;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostic;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;
use MSSTC4PHP\DtoGenerator\Domain\Model\MixedType;
use MSSTC4PHP\DtoGenerator\Domain\Model\NullableType;
use MSSTC4PHP\DtoGenerator\Domain\Model\ScalarType;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaGraph;
use MSSTC4PHP\DtoGenerator\Domain\Target\AccessorStyle;
use MSSTC4PHP\DtoGenerator\Domain\Target\DateTimeClass;
use MSSTC4PHP\DtoGenerator\Domain\Target\MetadataMode;
use MSSTC4PHP\DtoGenerator\Domain\Target\Mutability;
use MSSTC4PHP\DtoGenerator\Domain\Target\PhpVersion;
use MSSTC4PHP\DtoGenerator\Domain\Target\TargetProfile;
use MSSTC4PHP\DtoGenerator\Tests\Support\GraphFixture;
use PHPUnit\Framework\TestCase;

final class TypeMapperTest extends TestCase
{
    private const TAG = '/project/api/openapi.yaml#/components/schemas/Tag';

    /**
     * @dataProvider types
     *
     * @param array<array-key, mixed> $property
     * @param array<int|string, ClassName> $formats
     */
    public function testMapsSchemasToTypes(array $property, string $expected, array $formats = []): void
    {
        [$type, $messages] = $this->map($property, $formats);

        self::assertSame($expected, $type);
        self::assertSame([], $messages);
    }

    /**
     * @return array<string, array{0: array<array-key, mixed>, 1: string, 2?: array<int|string, ClassName>}>
     */
    public static function types(): array
    {
        return [
            'string' => [['type' => 'string'], 'string'],
            'non-empty string' => [['type' => 'string', 'minLength' => 1], 'non-empty-string'],
            'email stays a string' => [['type' => 'string', 'format' => 'email'], 'string'],
            'date-time' => [['type' => 'string', 'format' => 'date-time'], 'DateTimeImmutable'],
            'date' => [['type' => 'string', 'format' => 'date'], 'DateTimeImmutable'],
            'custom format' => [['type' => 'string', 'format' => 'uuid'], 'App\Uuid', ['uuid' => ClassName::fromFqcn('App\Uuid')]],
            'integer' => [['type' => 'integer', 'format' => 'int64'], 'int'],
            'positive' => [['type' => 'integer', 'minimum' => 1], 'positive-int'],
            'non-negative' => [['type' => 'integer', 'minimum' => 0], 'non-negative-int'],
            'exclusive minimum' => [['type' => 'integer', 'exclusiveMinimum' => 0], 'positive-int'],
            'lower bound' => [['type' => 'integer', 'minimum' => 5], 'int<5, max>'],
            'upper bound' => [['type' => 'integer', 'maximum' => 10], 'int<min, 10>'],
            'exclusive upper bound' => [['type' => 'integer', 'exclusiveMaximum' => 10], 'int<min, 9>'],
            'range' => [['type' => 'integer', 'minimum' => 5, 'maximum' => 10], 'int<5, 10>'],
            'tighter of two lower bounds' => [['type' => 'integer', 'minimum' => 1, 'exclusiveMinimum' => 4], 'int<5, max>'],
            'fractional bound ignored' => [['type' => 'integer', 'minimum' => 1.5], 'int'],
            'number' => [['type' => 'number', 'format' => 'double'], 'float'],
            'boolean' => [['type' => 'boolean'], 'bool'],
            'list' => [['type' => 'array', 'items' => ['type' => 'string']], 'list<string>'],
            'list without items' => [['type' => 'array'], 'list<mixed>'],
            'free-form object' => [['type' => 'object'], 'array<array-key, mixed>'],
            'no type' => [[], 'mixed'],
            'type union' => [['type' => ['string', 'integer']], 'string|int'],
            'nullable' => [['type' => ['string', 'null']], 'string|null'],
            'nullable list' => [['type' => ['array', 'null'], 'items' => ['type' => 'integer']], 'list<int>|null'],
            'only null' => [['type' => 'null'], 'mixed'],
            'class reference' => [['$ref' => '#/components/schemas/Tag'], 'App\Dto\Tag'],
            'list of classes' => [['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Tag']], 'list<App\Dto\Tag>'],
            'alias is inlined' => [['$ref' => '#/components/schemas/Email'], 'string'],
            'nullable alias' => [['$ref' => '#/components/schemas/MaybeCount'], 'int|null'],
            'x-php-type' => [['type' => 'string', 'x-php-type' => '\Symfony\Component\Uid\Uuid'], 'Symfony\Component\Uid\Uuid'],
            'nullable x-php-type' => [['type' => ['string', 'null'], 'x-php-type' => 'App\Uuid'], 'App\Uuid|null'],
        ];
    }

    public function testUsesTheConfiguredDateTimeClass(): void
    {
        [$type] = $this->map(['type' => 'string', 'format' => 'date-time'], [], '8.2', DateTimeClass::MUTABLE);

        self::assertSame('DateTime', $type);
    }

    /**
     * @dataProvider problems
     *
     * @param array<array-key, mixed> $property
     * @param list<string> $expected
     */
    public function testReportsWhatItCannotMap(array $property, string $type, array $expected, string $php = '8.2'): void
    {
        [$mapped, $messages] = $this->map($property, [], $php);

        self::assertSame($type, $mapped);
        self::assertSame($expected, $messages);
    }

    /**
     * @return array<string, array{0: array<array-key, mixed>, 1: string, 2: list<string>, 3?: string}>
     */
    public static function problems(): array
    {
        $at = '/project/api/openapi.yaml#/components/schemas/Holder/properties/value';

        return [
            'unknown string format' => [['type' => 'string', 'format' => 'color'], 'string', ["warning {$at}/format: Unknown string format \"color\"; the property stays a string."]],
            'unknown integer format' => [['type' => 'integer', 'format' => 'int128'], 'int', ["warning {$at}/format: Unknown integer format \"int128\"; the property stays an int."]],
            'unknown number format' => [['type' => 'number', 'format' => 'decimal'], 'float', ["warning {$at}/format: Unknown number format \"decimal\"; the property stays a float."]],
            'empty range' => [['type' => 'integer', 'minimum' => 10, 'maximum' => 5], 'int', ["warning {$at}: The minimum is greater than the maximum, so no range is applied."]],
            'enum' => [['type' => 'string', 'enum' => ['a']], 'mixed', ["error {$at}: \"enum\" is not supported yet; enums, composition and inline objects arrive in a later version."]],
            'oneOf' => [['oneOf' => [['type' => 'string']]], 'mixed', ["error {$at}: \"oneOf\" is not supported yet; enums, composition and inline objects arrive in a later version."]],
            'map schema' => [['type' => 'object', 'additionalProperties' => ['type' => 'string']], 'mixed', ["error {$at}: \"additionalProperties\" is not supported yet; enums, composition and inline objects arrive in a later version."]],
            'inline object' => [['type' => 'object', 'properties' => ['a' => []]], 'mixed', ["error {$at}: Inline object schemas are not supported yet; move it to components/schemas and use \$ref."]],
            'enum behind an alias' => [['$ref' => '#/components/schemas/Currency'], 'mixed', ['error /project/api/openapi.yaml#/components/schemas/Currency: "enum" is not supported yet; enums, composition and inline objects arrive in a later version.']],
            'alias loop' => [['$ref' => '#/components/schemas/LoopA'], 'mixed', ['error /project/api/openapi.yaml#/components/schemas/LoopA: The $ref chain loops back to itself without reaching an object schema.']],
            'skipped target' => [['$ref' => '#/components/schemas/Hidden'], 'mixed', ["warning {$at}: \$ref points to a schema excluded by \"x-php-skip\"."]],
            'x-php-type not a string' => [['x-php-type' => 5], 'mixed', ["error {$at}/x-php-type: \"x-php-type\" must be a class name."]],
            'x-php-type not a class' => [['x-php-type' => 'Not A Class'], 'mixed', ["error {$at}/x-php-type: \"Not A Class\" is not a valid class name: segment \"Not A Class\" is not a PHP identifier."]],
            'x-php-type reserved segment on 7.4' => [['x-php-type' => 'App\List\Uuid'], 'mixed', ["error {$at}/x-php-type: Namespace \"App\\List\" contains the reserved word \"List\", which PHP 7.4 cannot parse in a namespace (allowed from PHP 8.0)."], '7.4'],
        ];
    }

    public function testNullableNeverWrapsMixedOrNullable(): void
    {
        $nullable = new NullableType(ScalarType::string());

        self::assertInstanceOf(MixedType::class, TypeMapper::nullable(new MixedType()));
        self::assertSame($nullable, TypeMapper::nullable($nullable));
        self::assertSame('int|null', TypeMapper::nullable(ScalarType::int())->describe());
    }

    public function testRecognisesClassShapedSchemas(): void
    {
        $graph = $this->graph(['value' => []]);
        $shapes = [];
        foreach ($graph->all() as $resolved) {
            $shapes[$resolved->name()] = SchemaShape::isClass($resolved->schema());
        }

        self::assertSame(
            ['Holder' => true, 'Tag' => true, 'Email' => false, 'MaybeCount' => false, 'Currency' => false, 'LoopA' => false, 'LoopB' => false, 'Hidden' => true, 'Free' => false],
            $shapes,
        );
    }

    /**
     * @param array<array-key, mixed> $property
     * @param array<int|string, ClassName> $formats
     *
     * @return array{string, list<string>}
     */
    private function map(array $property, array $formats = [], string $php = '8.2', string $dateTimeClass = DateTimeClass::IMMUTABLE): array
    {
        $graph = $this->graph($property);
        $mapper = new TypeMapper(
            $graph,
            [self::TAG => ClassName::fromFqcn('App\Dto\Tag')],
            ['/project/api/openapi.yaml#/components/schemas/Hidden' => true],
            new TargetProfile(
                PhpVersion::fromString($php),
                MetadataMode::from(MetadataMode::NONE),
                Mutability::from(Mutability::MUTABLE),
                AccessorStyle::from(AccessorStyle::AUTO),
                DateTimeClass::from($dateTimeClass),
                true,
            ),
            $formats,
        );
        $holder = $graph->all()[0]->schema()->property('value');
        self::assertNotNull($holder);
        $diagnostics = new Diagnostics();
        $type = $mapper->map($holder, $diagnostics);

        return [$type->describe(), array_map(static fn (Diagnostic $d): string => $d->toString(), $diagnostics->all())];
    }

    /**
     * @param array<array-key, mixed> $property
     */
    private function graph(array $property): SchemaGraph
    {
        return GraphFixture::load([
            'Holder' => ['type' => 'object', 'properties' => ['value' => $property]],
            'Tag' => ['type' => 'object', 'properties' => ['label' => ['type' => 'string']]],
            'Email' => ['type' => 'string', 'format' => 'email'],
            'MaybeCount' => ['type' => ['integer', 'null']],
            'Currency' => ['type' => 'string', 'enum' => ['EUR']],
            'LoopA' => ['$ref' => '#/components/schemas/LoopB'],
            'LoopB' => ['$ref' => '#/components/schemas/LoopA'],
            'Hidden' => ['type' => 'object', 'properties' => ['x' => []], 'x-php-skip' => true],
            'Free' => ['type' => 'object'],
        ]);
    }
}
```

- [ ] **Step 2: Убедиться, что тест падает**

Run: `vendor/bin/phpunit --filter TypeMapperTest`
Expected: FAIL — `Class "...\Builder\TypeMapper" not found`.

- [ ] **Step 3: Реализовать**

`src/Domain/Builder/SchemaShape.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Builder;

use MSSTC4PHP\DtoGenerator\Domain\Schema\Discriminator;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Schema;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaType;

final class SchemaShape
{
    private function __construct()
    {
    }

    /**
     * The first keyword the builder cannot handle yet; composition, enums and maps of schemas arrive in stage 4.
     */
    public static function unsupportedKeyword(Schema $schema): ?string
    {
        if ($schema->enum() !== null) {
            return 'enum';
        }

        foreach (['allOf' => $schema->allOf(), 'oneOf' => $schema->oneOf(), 'anyOf' => $schema->anyOf()] as $keyword => $schemas) {
            if ($schemas !== []) {
                return $keyword;
            }
        }

        if ($schema->discriminator() instanceof Discriminator) {
            return 'discriminator';
        }

        return $schema->additionalProperties() instanceof Schema ? 'additionalProperties' : null;
    }

    /**
     * An object with its own properties, which the builder turns into a class.
     */
    public static function isClass(Schema $schema): bool
    {
        if ($schema->ref() !== null || self::unsupportedKeyword($schema) !== null || $schema->propertyNames() === []) {
            return false;
        }

        $types = $schema->nonNullTypes();

        return $types === [] || $types === [SchemaType::from(SchemaType::OBJECT)];
    }
}
```

`src/Domain/Builder/TypeMapper.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Builder;

use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassType;
use MSSTC4PHP\DtoGenerator\Domain\Model\ListType;
use MSSTC4PHP\DtoGenerator\Domain\Model\MapType;
use MSSTC4PHP\DtoGenerator\Domain\Model\MixedType;
use MSSTC4PHP\DtoGenerator\Domain\Model\NullableType;
use MSSTC4PHP\DtoGenerator\Domain\Model\ScalarType;
use MSSTC4PHP\DtoGenerator\Domain\Model\TypeModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\UnionType;
use MSSTC4PHP\DtoGenerator\Domain\Schema\ReferenceUse;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Schema;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaGraph;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaType;
use MSSTC4PHP\DtoGenerator\Domain\Target\Capability;
use MSSTC4PHP\DtoGenerator\Domain\Target\TargetProfile;

/**
 * Maps a schema to a PHP type (spec §5.1). Named non-object schemas are aliases and are inlined.
 */
final class TypeMapper
{
    private const STRING_FORMATS = [
        'email', 'idn-email', 'uri', 'uri-reference', 'iri', 'iri-reference', 'uri-template', 'uuid', 'hostname',
        'idn-hostname', 'ipv4', 'ipv6', 'time', 'duration', 'byte', 'binary', 'password', 'regex', 'json-pointer',
        'relative-json-pointer',
    ];

    private const DATE_FORMATS = ['date-time', 'date'];

    private const INTEGER_FORMATS = ['int32', 'int64'];

    private const NUMBER_FORMATS = ['float', 'double'];

    private SchemaGraph $graph;

    /** @var array<string, ClassName> */
    private array $classes;

    /** @var array<string, true> */
    private array $skipped;

    private TargetProfile $target;

    /** @var array<int|string, ClassName> */
    private array $formats;

    /**
     * @param array<string, ClassName> $classes class of every class-shaped schema, by location key
     * @param array<string, true> $skipped location keys of schemas excluded by x-php-skip
     * @param array<int|string, ClassName> $formats custom formats from the config
     */
    public function __construct(SchemaGraph $graph, array $classes, array $skipped, TargetProfile $target, array $formats)
    {
        $this->graph = $graph;
        $this->classes = $classes;
        $this->skipped = $skipped;
        $this->target = $target;
        $this->formats = $formats;
    }

    public function map(Schema $schema, Diagnostics $diagnostics): TypeModel
    {
        return $this->mapWithin($schema, $diagnostics, []);
    }

    public static function nullable(TypeModel $type): TypeModel
    {
        return $type instanceof MixedType || $type instanceof NullableType ? $type : new NullableType($type);
    }

    /**
     * @param array<string, true> $aliases alias schemas being inlined, to stop $ref loops
     */
    private function mapWithin(Schema $schema, Diagnostics $diagnostics, array $aliases): TypeModel
    {
        $type = $this->bareType($schema, $diagnostics, $aliases);

        return $schema->isNullable() ? self::nullable($type) : $type;
    }

    /**
     * @param array<string, true> $aliases
     */
    private function bareType(Schema $schema, Diagnostics $diagnostics, array $aliases): TypeModel
    {
        if ($schema->extensions()->has('x-php-type')) {
            return $this->explicitType($schema, $diagnostics);
        }

        $unsupported = SchemaShape::unsupportedKeyword($schema);
        if ($unsupported !== null) {
            $diagnostics->error(
                sprintf('"%s" is not supported yet; enums, composition and inline objects arrive in a later version.', $unsupported),
                $schema->location(),
            );

            return new MixedType();
        }

        $ref = $schema->ref();
        if ($ref !== null) {
            return $this->reference($schema, $ref, $diagnostics, $aliases);
        }

        if (SchemaShape::isClass($schema)) {
            $diagnostics->error('Inline object schemas are not supported yet; move it to components/schemas and use $ref.', $schema->location());

            return new MixedType();
        }

        $format = $schema->format();
        if ($format !== null && isset($this->formats[$format])) {
            return new ClassType($this->formats[$format]);
        }

        $members = [];
        foreach ($schema->nonNullTypes() as $type) {
            $member = $this->single($type, $schema, $diagnostics, $aliases);
            if ($member instanceof MixedType) {
                return $member;
            }

            $members[] = $member;
        }

        if ($members === []) {
            return new MixedType();
        }

        try {
            return count($members) === 1 ? $members[0] : new UnionType(...$members);
        } catch (InvalidModel $exception) {
            return $members[0];
        }
    }

    private function explicitType(Schema $schema, Diagnostics $diagnostics): TypeModel
    {
        $at = $schema->location()->child('x-php-type');
        $value = $schema->extensions()->get('x-php-type');
        if (!is_string($value)) {
            $diagnostics->error('"x-php-type" must be a class name.', $at);

            return new MixedType();
        }

        try {
            $class = ClassName::fromFqcn($value);
        } catch (InvalidModel $exception) {
            $diagnostics->error($exception->getMessage(), $at);

            return new MixedType();
        }

        if (!$this->target->supports(Capability::from(Capability::RESERVED_NAMESPACE_SEGMENTS))) {
            foreach ($class->reservedNamespaceSegments() as $segment) {
                $diagnostics->error(
                    sprintf(
                        'Namespace "%s" contains the reserved word "%s", which PHP %s cannot parse in a namespace (allowed from PHP 8.0).',
                        $class->namespace(),
                        $segment,
                        $this->target->php()->toString(),
                    ),
                    $at,
                );

                return new MixedType();
            }
        }

        return new ClassType($class);
    }

    /**
     * @param array<string, true> $aliases
     */
    private function reference(Schema $schema, string $ref, Diagnostics $diagnostics, array $aliases): TypeModel
    {
        // An unresolved $ref was already reported while loading.
        $target = $this->graph->resolve(new ReferenceUse($ref, $schema->location()));
        if ($target === null) {
            return new MixedType();
        }

        $key = $target->location()->toString();
        if (isset($this->classes[$key])) {
            return new ClassType($this->classes[$key]);
        }

        if (isset($this->skipped[$key])) {
            $diagnostics->warning('$ref points to a schema excluded by "x-php-skip".', $schema->location());

            return new MixedType();
        }

        // A class-shaped target without a class lost its namespace to an ambiguity that was already reported.
        if (SchemaShape::isClass($target->schema())) {
            return new MixedType();
        }

        if (isset($aliases[$key])) {
            $diagnostics->error('The $ref chain loops back to itself without reaching an object schema.', $target->location());

            return new MixedType();
        }

        $aliases[$key] = true;

        return $this->mapWithin($target->schema(), $diagnostics, $aliases);
    }

    /**
     * @param array<string, true> $aliases
     */
    private function single(SchemaType $type, Schema $schema, Diagnostics $diagnostics, array $aliases): TypeModel
    {
        switch ($type->value()) {
            case SchemaType::STRING:
                return $this->stringType($schema, $diagnostics);
            case SchemaType::INTEGER:
                $this->checkFormat($schema, self::INTEGER_FORMATS, 'integer', 'an int', $diagnostics);

                return ScalarType::int($this->integerRange($schema, $diagnostics));
            case SchemaType::NUMBER:
                $this->checkFormat($schema, self::NUMBER_FORMATS, 'number', 'a float', $diagnostics);

                return ScalarType::float();
            case SchemaType::BOOLEAN:
                return ScalarType::bool();
            case SchemaType::ARRAY:
                $items = $schema->items();

                return new ListType($items instanceof Schema ? $this->mapWithin($items, $diagnostics, $aliases) : new MixedType());
            default:
                return new MapType(new MixedType());
        }
    }

    private function stringType(Schema $schema, Diagnostics $diagnostics): TypeModel
    {
        if (in_array($schema->format(), self::DATE_FORMATS, true)) {
            return new ClassType(ClassName::fromFqcn($this->target->dateTimeClass()->className()));
        }

        $this->checkFormat($schema, self::STRING_FORMATS, 'string', 'a string', $diagnostics);
        $minLength = $schema->hasKeyword('minLength') ? $schema->keyword('minLength') : null;

        return ScalarType::string(is_int($minLength) && $minLength >= 1 ? 'non-empty-string' : null);
    }

    /**
     * @param list<string> $known
     */
    private function checkFormat(Schema $schema, array $known, string $type, string $result, Diagnostics $diagnostics): void
    {
        $format = $schema->format();
        if ($format !== null && !in_array($format, $known, true)) {
            $diagnostics->warning(
                sprintf('Unknown %s format "%s"; the property stays %s.', $type, $format, $result),
                $schema->location()->child('format'),
            );
        }
    }

    private function integerRange(Schema $schema, Diagnostics $diagnostics): ?string
    {
        $min = self::tightest(self::intKeyword($schema, 'minimum'), self::shifted(self::intKeyword($schema, 'exclusiveMinimum'), 1), true);
        $max = self::tightest(self::intKeyword($schema, 'maximum'), self::shifted(self::intKeyword($schema, 'exclusiveMaximum'), -1), false);
        if ($min !== null && $max !== null && $min > $max) {
            $diagnostics->warning('The minimum is greater than the maximum, so no range is applied.', $schema->location());

            return null;
        }

        if ($max === null && $min === 0) {
            return 'non-negative-int';
        }

        if ($max === null && $min === 1) {
            return 'positive-int';
        }

        if ($min === null && $max === null) {
            return null;
        }

        return sprintf('int<%s, %s>', $min ?? 'min', $max ?? 'max');
    }

    private static function intKeyword(Schema $schema, string $keyword): ?int
    {
        $value = $schema->hasKeyword($keyword) ? $schema->keyword($keyword) : null;

        return is_int($value) ? $value : null;
    }

    private static function shifted(?int $value, int $by): ?int
    {
        return $value === null ? null : $value + $by;
    }

    private static function tightest(?int $a, ?int $b, bool $lower): ?int
    {
        if ($a === null || $b === null) {
            return $a ?? $b;
        }

        return $lower ? max($a, $b) : min($a, $b);
    }
}
```

- [ ] **Step 4: Прогнать тест**

Run: `vendor/bin/phpunit --filter TypeMapperTest`
Expected: PASS.

- [ ] **Step 5: Гейт и коммит**

Run: `make fix && make verify`
Expected: зелёное. Rector может сделать статические хелперы методами экземпляра — это допустимо.

```bash
git add src/Domain/Builder/SchemaShape.php src/Domain/Builder/TypeMapper.php tests/Support/GraphFixture.php tests/Unit/Domain/Builder/TypeMapperTest.php
git commit -m "feat(builder): TypeMapper maps schemas to PHP types (spec §5.1)

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: Класс — `ClassBuilder`

**Files:**
- Create: `src/Domain/Builder/ClassBuilder.php`
- Test: `tests/Unit/Domain/Builder/ClassBuilderTest.php`

**Interfaces:**
- Consumes:
  - `NameResolver` (Task 1), `TypeMapper` (Task 2);
  - модели: `ClassModel`, `PropertyModel`, `ClassKind`, `DocModel`, `DefaultValue`, `Mutability`;
  - `TargetProfile::accessorsFor()` (бросает `IncompatibleTarget`), `ResolvedSchema`, `Diagnostics`, `GraphFixture`.
- Produces:
  - `ClassBuilder::__construct(NameResolver $names, TypeMapper $types, TargetProfile $target)`.
  - `ClassBuilder::build(ClassName $name, ResolvedSchema $resolved, Diagnostics $diagnostics): ClassModel`.
  - `ClassBuilder::isSkipped(Schema, Diagnostics): bool` (static) — `x-php-skip: true`; не-bool даёт ошибку и `false`.
  - Правила:
    - обязательное не-nullable свойство → `required=true` без default;
    - иначе тип `nullable(T)` и default из схемы либо `DefaultValue(null)`;
    - default у `ClassType`/`MapType` → warning и `null`;
    - нечисловой float (`INF`/`NAN`) → error и `null`;
    - коллизия PHP-имён без учёта регистра → error, второе свойство пропускается;
    - неизвестный `x-php-*`/`x-dto-*` → error, известный не к месту → warning;
    - `x-dto-mutable` не bool → error;
    - несовместимость с target → error, используется глобальная мутабельность.

- [ ] **Step 1: Написать падающий тест**

`tests/Unit/Domain/Builder/ClassBuilderTest.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Domain\Builder;

use MSSTC4PHP\DtoGenerator\Domain\Builder\ClassBuilder;
use MSSTC4PHP\DtoGenerator\Domain\Builder\NameResolver;
use MSSTC4PHP\DtoGenerator\Domain\Builder\TypeMapper;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostic;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;
use MSSTC4PHP\DtoGenerator\Domain\Model\PropertyModel;
use MSSTC4PHP\DtoGenerator\Domain\Shared\DefaultValue;
use MSSTC4PHP\DtoGenerator\Domain\Target\AccessorStyle;
use MSSTC4PHP\DtoGenerator\Domain\Target\DateTimeClass;
use MSSTC4PHP\DtoGenerator\Domain\Target\MetadataMode;
use MSSTC4PHP\DtoGenerator\Domain\Target\Mutability;
use MSSTC4PHP\DtoGenerator\Domain\Target\PhpVersion;
use MSSTC4PHP\DtoGenerator\Domain\Target\TargetProfile;
use MSSTC4PHP\DtoGenerator\Tests\Support\GraphFixture;
use PHPUnit\Framework\TestCase;

final class ClassBuilderTest extends TestCase
{
    private const AT = '/project/api/openapi.yaml#/components/schemas/User';

    public function testBuildsPropertiesWithRequiredAndDefaults(): void
    {
        [$class, $messages] = $this->build([
            'type' => 'object',
            'description' => 'A user',
            'deprecated' => true,
            'required' => ['id', 'nickname'],
            'properties' => [
                'id' => ['type' => 'integer', 'description' => 'Primary key'],
                'user_name' => ['type' => 'string'],
                'age' => ['type' => 'integer', 'default' => 18],
                'tags' => ['type' => 'array', 'items' => ['type' => 'string'], 'default' => ['a']],
                'nickname' => ['type' => ['string', 'null']],
                'old' => ['type' => 'string', 'deprecated' => true],
            ],
        ]);

        self::assertSame([], $messages);
        self::assertSame('App\Dto\User', $class->name()->fqcn());
        self::assertSame('final', $class->kind()->value());
        self::assertSame('immutable', $class->mutability()->value());
        self::assertSame('A user', $class->doc()->description());
        self::assertTrue($class->doc()->isDeprecated());
        self::assertSame(self::AT, $class->source()->toString());
        self::assertSame(
            [
                'id' => 'id: int',
                'userName' => 'user_name: string|null = NULL',
                'age' => 'age: int|null = 18',
                'tags' => "tags: list<string>|null = array (\n  0 => 'a',\n)",
                'nickname' => 'nickname: string|null = NULL',
                'old' => 'old: string|null = NULL',
            ],
            self::summary($class),
        );
        self::assertSame('Primary key', $this->property($class, 'id')->doc()->description());
        self::assertTrue($this->property($class, 'old')->doc()->isDeprecated());
        self::assertSame(self::AT . '/properties/user_name', $this->property($class, 'userName')->source()->toString());
    }

    public function testRequiredPropertiesIgnoreTheSchemaDefault(): void
    {
        [$class] = $this->build(['type' => 'object', 'required' => ['id'], 'properties' => ['id' => ['type' => 'integer', 'default' => 1]]]);

        self::assertNull($this->property($class, 'id')->default());
        self::assertTrue($this->property($class, 'id')->isRequired());
    }

    /**
     * @dataProvider problems
     *
     * @param array<array-key, mixed> $schema
     * @param array<string, string> $properties
     * @param list<string> $messages
     */
    public function testReportsProblems(array $schema, array $properties, array $messages): void
    {
        [$class, $actual] = $this->build($schema);

        self::assertSame($messages, $actual);
        self::assertSame($properties, self::summary($class));
    }

    /**
     * @return array<string, array{array<array-key, mixed>, array<string, string>, list<string>}>
     */
    public static function problems(): array
    {
        $at = self::AT;

        return [
            'names colliding after camelCase' => [
                ['type' => 'object', 'properties' => ['user_name' => ['type' => 'string'], 'userName' => ['type' => 'string']]],
                ['userName' => 'user_name: string|null = NULL'],
                ["error {$at}/properties/userName: Property \"userName\" becomes \$userName, which \"user_name\" already uses; set \"x-php-name\" on one of them."],
            ],
            'names colliding by case' => [
                ['type' => 'object', 'properties' => ['URL' => ['type' => 'string'], 'url' => ['type' => 'string']]],
                ['url' => 'URL: string|null = NULL'],
                ["error {$at}/properties/url: Property \"url\" becomes \$url, which \"URL\" already uses; set \"x-php-name\" on one of them."],
            ],
            'x-php-name' => [
                ['type' => 'object', 'properties' => ['user_name' => ['type' => 'string', 'x-php-name' => 'login']]],
                ['login' => 'user_name: string|null = NULL'],
                [],
            ],
            'invalid x-php-name' => [
                ['type' => 'object', 'properties' => ['user_name' => ['type' => 'string', 'x-php-name' => '1x']]],
                ['userName' => 'user_name: string|null = NULL'],
                ["error {$at}/properties/user_name/x-php-name: \"x-php-name\" must be a PHP identifier other than \"this\"."],
            ],
            'no usable name' => [
                ['type' => 'object', 'properties' => ['---' => ['type' => 'string']]],
                [],
                ["error {$at}/properties/---: Property name \"---\" has no usable characters; set \"x-php-name\"."],
            ],
            'skipped property' => [
                ['type' => 'object', 'properties' => ['secret' => ['type' => 'string', 'x-php-skip' => true], 'id' => ['type' => 'string']]],
                ['id' => 'id: string|null = NULL'],
                [],
            ],
            'x-php-skip not a boolean' => [
                ['type' => 'object', 'properties' => ['id' => ['type' => 'string', 'x-php-skip' => 'yes']]],
                ['id' => 'id: string|null = NULL'],
                ["error {$at}/properties/id/x-php-skip: \"x-php-skip\" must be true or false."],
            ],
            'default for a date' => [
                ['type' => 'object', 'properties' => ['at' => ['type' => 'string', 'format' => 'date-time', 'default' => '2020-01-01T00:00:00Z']]],
                ['at' => 'at: DateTimeImmutable|null = NULL'],
                ["warning {$at}/properties/at/default: A default for DateTimeImmutable cannot be a PHP constant expression; null is used instead."],
            ],
            'default for a map' => [
                ['type' => 'object', 'properties' => ['meta' => ['type' => 'object', 'default' => ['a' => 1]]]],
                ['meta' => 'meta: array<array-key, mixed>|null = NULL'],
                ["warning {$at}/properties/meta/default: A default for array<array-key, mixed> cannot be a PHP constant expression; null is used instead."],
            ],
            'infinite default' => [
                ['type' => 'object', 'properties' => ['ratio' => ['type' => 'number', 'default' => INF]]],
                ['ratio' => 'ratio: float|null = NULL'],
                ["error {$at}/properties/ratio/default: A default must be a finite number; INF and NAN have no PHP literal."],
            ],
            'NaN inside a list default' => [
                ['type' => 'object', 'properties' => ['values' => ['type' => 'array', 'items' => ['type' => 'number'], 'default' => [1.0, NAN]]]],
                ['values' => 'values: list<float>|null = NULL'],
                ["error {$at}/properties/values/default: A default must be a finite number; INF and NAN have no PHP literal."],
            ],
            'unknown extension' => [
                ['type' => 'object', 'x-dto-mutible' => true, 'properties' => ['id' => ['type' => 'string', 'x-php-nmae' => 'x']]],
                ['id' => 'id: string|null = NULL'],
                [
                    "error {$at}/x-dto-mutible: Unknown extension \"x-dto-mutible\"; known: x-php-class-name, x-php-name, x-php-type, x-dto-mutable, x-php-all-of, x-php-skip, x-php-attributes, x-enum-descriptions.",
                    "error {$at}/properties/id/x-php-nmae: Unknown extension \"x-php-nmae\"; known: x-php-class-name, x-php-name, x-php-type, x-dto-mutable, x-php-all-of, x-php-skip, x-php-attributes, x-enum-descriptions.",
                ],
            ],
            'misplaced extension' => [
                ['type' => 'object', 'x-php-name' => 'x', 'properties' => ['id' => ['type' => 'string', 'x-dto-mutable' => true]]],
                ['id' => 'id: string|null = NULL'],
                [
                    "warning {$at}/x-php-name: \"x-php-name\" has no effect here.",
                    "warning {$at}/properties/id/x-dto-mutable: \"x-dto-mutable\" has no effect here.",
                ],
            ],
            'foreign extensions are ignored' => [
                ['type' => 'object', 'x-audit' => true, 'properties' => ['id' => ['type' => 'string', 'x-internal' => 1]]],
                ['id' => 'id: string|null = NULL'],
                [],
            ],
            'x-dto-mutable not a boolean' => [
                ['type' => 'object', 'x-dto-mutable' => 'yes', 'properties' => ['id' => ['type' => 'string']]],
                ['id' => 'id: string|null = NULL'],
                ["error {$at}/x-dto-mutable: \"x-dto-mutable\" must be true or false."],
            ],
        ];
    }

    public function testAppliesXDtoMutable(): void
    {
        [$class] = $this->build(['type' => 'object', 'x-dto-mutable' => true, 'properties' => ['id' => ['type' => 'string']]]);

        self::assertSame('mutable', $class->mutability()->value());
    }

    public function testRejectsAnImmutableOverrideTheTargetCannotExpress(): void
    {
        [$class, $messages] = $this->build(
            ['type' => 'object', 'x-dto-mutable' => false, 'properties' => ['id' => ['type' => 'string']]],
            new TargetProfile(
                PhpVersion::fromString('7.4'),
                MetadataMode::from(MetadataMode::NONE),
                Mutability::from(Mutability::MUTABLE),
                AccessorStyle::from(AccessorStyle::PUBLIC_PROPERTIES),
                DateTimeClass::from(DateTimeClass::IMMUTABLE),
                true,
            ),
        );

        self::assertSame('mutable', $class->mutability()->value());
        self::assertSame(
            ["error {$this->at()}/x-dto-mutable: Immutable DTOs with public properties requires readonly-properties (PHP 8.1+), but the target is PHP 7.4."],
            $messages,
        );
    }

    public function testRecognisesSkippedSchemas(): void
    {
        $graph = GraphFixture::load([
            'A' => ['type' => 'object', 'properties' => ['x' => []], 'x-php-skip' => true],
            'B' => ['type' => 'object', 'properties' => ['x' => []]],
        ]);
        $diagnostics = new Diagnostics();

        self::assertTrue(ClassBuilder::isSkipped($graph->all()[0]->schema(), $diagnostics));
        self::assertFalse(ClassBuilder::isSkipped($graph->all()[1]->schema(), $diagnostics));
        self::assertSame([], $diagnostics->all());
    }

    private function at(): string
    {
        return self::AT;
    }

    /**
     * @param array<array-key, mixed> $schema
     *
     * @return array{ClassModel, list<string>}
     */
    private function build(array $schema, ?TargetProfile $target = null): array
    {
        $graph = GraphFixture::load(['User' => $schema]);
        $target = $target ?? new TargetProfile(
            PhpVersion::fromString('8.2'),
            MetadataMode::from(MetadataMode::NONE),
            Mutability::from(Mutability::IMMUTABLE),
            AccessorStyle::from(AccessorStyle::AUTO),
            DateTimeClass::from(DateTimeClass::IMMUTABLE),
            true,
        );
        $builder = new ClassBuilder(new NameResolver(), new TypeMapper($graph, [], [], $target, []), $target);
        $diagnostics = new Diagnostics();
        $class = $builder->build(ClassName::fromFqcn('App\Dto\User'), $graph->all()[0], $diagnostics);

        return [$class, array_map(static fn (Diagnostic $d): string => $d->toString(), $diagnostics->all())];
    }

    private function property(ClassModel $class, string $name): PropertyModel
    {
        $property = $class->property($name);
        self::assertNotNull($property);

        return $property;
    }

    /**
     * @return array<string, string>
     */
    private static function summary(ClassModel $class): array
    {
        $summary = [];
        foreach ($class->properties() as $property) {
            $default = $property->default();
            $summary[$property->name()] = sprintf(
                '%s: %s%s',
                $property->wireName(),
                $property->type()->describe(),
                $default instanceof DefaultValue ? ' = ' . var_export($default->value(), true) : '',
            );
        }

        return $summary;
    }
}
```

- [ ] **Step 2: Убедиться, что тест падает**

Run: `vendor/bin/phpunit --filter ClassBuilderTest`
Expected: FAIL — `Class "...\Builder\ClassBuilder" not found`.

- [ ] **Step 3: Реализовать**

`src/Domain/Builder/ClassBuilder.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Builder;

use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Exception\IncompatibleTarget;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassKind;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassType;
use MSSTC4PHP\DtoGenerator\Domain\Model\DocModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\Identifier;
use MSSTC4PHP\DtoGenerator\Domain\Model\MapType;
use MSSTC4PHP\DtoGenerator\Domain\Model\NullableType;
use MSSTC4PHP\DtoGenerator\Domain\Model\PropertyModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\TypeModel;
use MSSTC4PHP\DtoGenerator\Domain\Schema\ResolvedSchema;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Schema;
use MSSTC4PHP\DtoGenerator\Domain\Shared\DefaultValue;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Json;
use MSSTC4PHP\DtoGenerator\Domain\Target\Mutability;
use MSSTC4PHP\DtoGenerator\Domain\Target\TargetProfile;

/**
 * Builds the class of one object schema (spec §5.2, §5.4, §5.5) and checks the core x- vocabulary (§7).
 *
 * @phpstan-import-type JsonValue from Json
 */
final class ClassBuilder
{
    /** Every core extension; x-php-attributes and x-php-all-of take effect in later stages. */
    private const KNOWN_EXTENSIONS = [
        'x-php-class-name', 'x-php-name', 'x-php-type', 'x-dto-mutable', 'x-php-all-of', 'x-php-skip',
        'x-php-attributes', 'x-enum-descriptions',
    ];

    private const CLASS_EXTENSIONS = [
        'x-php-class-name', 'x-php-type', 'x-dto-mutable', 'x-php-all-of', 'x-php-skip', 'x-php-attributes',
        'x-enum-descriptions',
    ];

    private const PROPERTY_EXTENSIONS = ['x-php-name', 'x-php-type', 'x-php-skip', 'x-php-attributes'];

    private NameResolver $names;

    private TypeMapper $types;

    private TargetProfile $target;

    public function __construct(NameResolver $names, TypeMapper $types, TargetProfile $target)
    {
        $this->names = $names;
        $this->types = $types;
        $this->target = $target;
    }

    public static function isSkipped(Schema $schema, Diagnostics $diagnostics): bool
    {
        if (!$schema->extensions()->has('x-php-skip')) {
            return false;
        }

        $value = $schema->extensions()->get('x-php-skip');
        if (!is_bool($value)) {
            $diagnostics->error('"x-php-skip" must be true or false.', $schema->location()->child('x-php-skip'));

            return false;
        }

        return $value;
    }

    public function build(ClassName $name, ResolvedSchema $resolved, Diagnostics $diagnostics): ClassModel
    {
        $schema = $resolved->schema();
        self::checkExtensions($schema, self::CLASS_EXTENSIONS, $diagnostics);

        $properties = [];
        $taken = [];
        foreach ($schema->propertyNames() as $wireName) {
            $propertySchema = $schema->property($wireName);
            if (!$propertySchema instanceof Schema) {
                continue;
            }

            self::checkExtensions($propertySchema, self::PROPERTY_EXTENSIONS, $diagnostics);
            if (self::isSkipped($propertySchema, $diagnostics)) {
                continue;
            }

            $property = $this->property($schema, $wireName, $propertySchema, $diagnostics);
            if (!$property instanceof PropertyModel) {
                continue;
            }

            // Accessors are case-insensitive in PHP, so $url and $URL would both declare getUrl().
            $key = Identifier::asciiLower($property->name());
            if (isset($taken[$key])) {
                $diagnostics->error(
                    sprintf('Property "%s" becomes $%s, which "%s" already uses; set "x-php-name" on one of them.', $wireName, $property->name(), $taken[$key]),
                    $propertySchema->location(),
                );

                continue;
            }

            $taken[$key] = $wireName;
            $properties[] = $property;
        }

        return new ClassModel(
            $name,
            ClassKind::from(ClassKind::FINAL),
            null,
            $properties,
            $this->mutability($schema, $diagnostics),
            new DocModel($schema->description(), $schema->isDeprecated()),
            $schema->location(),
        );
    }

    private function property(Schema $owner, string $wireName, Schema $schema, Diagnostics $diagnostics): ?PropertyModel
    {
        $name = $this->propertyName($wireName, $schema, $diagnostics);
        if ($name === null) {
            return null;
        }

        $type = $this->types->map($schema, $diagnostics);
        $required = $owner->isRequired($wireName) && !$type instanceof NullableType;
        $default = null;
        if (!$required) {
            $type = TypeMapper::nullable($type);
            $default = $this->defaultFor($schema, $type, $diagnostics) ?? new DefaultValue(null);
        }

        return new PropertyModel(
            $name,
            $wireName,
            $type,
            $required,
            $default,
            new DocModel($schema->description(), $schema->isDeprecated()),
            $schema->location(),
        );
    }

    private function propertyName(string $wireName, Schema $schema, Diagnostics $diagnostics): ?string
    {
        if ($schema->extensions()->has('x-php-name')) {
            $override = $schema->extensions()->get('x-php-name');
            if (is_string($override) && Identifier::isValid($override) && $override !== 'this') {
                return $override;
            }

            $diagnostics->error('"x-php-name" must be a PHP identifier other than "this".', $schema->location()->child('x-php-name'));
        }

        $name = $this->names->propertyName($wireName);
        if ($name === null) {
            $diagnostics->error(sprintf('Property name "%s" has no usable characters; set "x-php-name".', $wireName), $schema->location());
        }

        return $name;
    }

    private function defaultFor(Schema $schema, TypeModel $type, Diagnostics $diagnostics): ?DefaultValue
    {
        $default = $schema->default();
        if (!$default instanceof DefaultValue || $default->value() === null) {
            return $default;
        }

        $at = $schema->location()->child('default');
        $inner = $type instanceof NullableType ? $type->inner() : $type;
        if ($inner instanceof ClassType || $inner instanceof MapType) {
            $diagnostics->warning(sprintf('A default for %s cannot be a PHP constant expression; null is used instead.', $inner->describe()), $at);

            return null;
        }

        if (self::containsNonFiniteFloat($default->value())) {
            $diagnostics->error('A default must be a finite number; INF and NAN have no PHP literal.', $at);

            return null;
        }

        return $default;
    }

    /**
     * @param JsonValue $value
     */
    private static function containsNonFiniteFloat($value): bool
    {
        if (is_float($value)) {
            return !is_finite($value);
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                if (self::containsNonFiniteFloat(Json::value($item))) {
                    return true;
                }
            }
        }

        return false;
    }

    private function mutability(Schema $schema, Diagnostics $diagnostics): Mutability
    {
        $default = $this->target->mutability();
        if (!$schema->extensions()->has('x-dto-mutable')) {
            return $default;
        }

        $at = $schema->location()->child('x-dto-mutable');
        $value = $schema->extensions()->get('x-dto-mutable');
        if (!is_bool($value)) {
            $diagnostics->error('"x-dto-mutable" must be true or false.', $at);

            return $default;
        }

        $mutability = Mutability::from($value ? Mutability::MUTABLE : Mutability::IMMUTABLE);
        try {
            $this->target->accessorsFor($mutability);
        } catch (IncompatibleTarget $exception) {
            $diagnostics->error($exception->getMessage(), $at);

            return $default;
        }

        return $mutability;
    }

    /**
     * @param list<string> $allowed
     */
    private static function checkExtensions(Schema $schema, array $allowed, Diagnostics $diagnostics): void
    {
        foreach ($schema->extensions()->keys() as $key) {
            if (strncmp($key, 'x-php-', 6) !== 0 && strncmp($key, 'x-dto-', 6) !== 0) {
                continue;
            }

            $at = $schema->location()->child($key);
            if (!in_array($key, self::KNOWN_EXTENSIONS, true)) {
                $diagnostics->error(sprintf('Unknown extension "%s"; known: %s.', $key, implode(', ', self::KNOWN_EXTENSIONS)), $at);
            } elseif (!in_array($key, $allowed, true)) {
                $diagnostics->warning(sprintf('"%s" has no effect here.', $key), $at);
            }
        }
    }
}
```

- [ ] **Step 4: Прогнать тест**

Run: `vendor/bin/phpunit --filter ClassBuilderTest`
Expected: PASS.

- [ ] **Step 5: Гейт и коммит**

Run: `make fix && make verify`
Expected: зелёное.

```bash
git add src/Domain/Builder/ClassBuilder.php tests/Unit/Domain/Builder/ClassBuilderTest.php
git commit -m "feat(builder): ClassBuilder for properties, defaults, docs and the core x- vocabulary

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Use-case `Model/Build`

**Files:**
- Create: `src/Application/Service/Model/Build/Action.php`, `Input.php`, `Output.php`, `BuiltClass.php`
- Create: `tests/Support/ModelFixture.php`
- Test: `tests/Unit/Application/Service/Model/BuildTest.php`
- Modify: `tests/Integration/PetstoreLoadingTest.php`

**Interfaces:**
- Consumes:
  - `GeneratorConfig` (`sources()[i]->namespace()`, `formats()`), `TargetProfile`, `SchemaGraph`/`ResolvedSchema`;
  - `NameResolver`, `SchemaShape`, `TypeMapper`, `ClassBuilder` (Tasks 1–3);
  - `ClassName`, `Identifier`, `Diagnostics`;
  - тестовые `GraphFixture`, `ConfigMother`.
- Produces:
  - `Model\Build\Input::__construct(GeneratorConfig $config, TargetProfile $target, SchemaGraph $graph)` и геттеры.
  - `Model\Build\BuiltClass::__construct(ClassModel $model, int $source)`, методы `model()` и `source()`.
  - `Model\Build\Output::__construct(list<BuiltClass> $classes, Diagnostics $diagnostics)`, методы `classes()` и `diagnostics()`.
  - `Model\Build\Action::__construct(NameResolver $names)` с `__invoke(Input): Output`. Логика:
    - класс получает каждая схема формы класса, у которой есть источник-владелец и которая не исключена через `x-php-skip`;
    - короткое имя — `x-php-class-name` или `NameResolver::className(ResolvedSchema::name())`;
    - namespace — от источника;
    - коллизия FQCN без учёта регистра → error, класс пропускается;
    - выбранная, но неподдерживаемая схема → warning, класс не создаётся.
  - Тестовый `Tests\Support\ModelFixture::build(array $schemas, array $extraDocuments = [], array $include = ['*']): Output`.

- [ ] **Step 1: Написать фикстуру и падающий тест**

`tests/Support/ModelFixture.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Support;

use MSSTC4PHP\DtoGenerator\Application\Service\Model\Build\Action;
use MSSTC4PHP\DtoGenerator\Application\Service\Model\Build\BuiltClass;
use MSSTC4PHP\DtoGenerator\Application\Service\Model\Build\Input;
use MSSTC4PHP\DtoGenerator\Application\Service\Model\Build\Output;
use MSSTC4PHP\DtoGenerator\Domain\Builder\NameResolver;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostic;
use MSSTC4PHP\DtoGenerator\Domain\Model\PropertyModel;
use MSSTC4PHP\DtoGenerator\Domain\Target\AccessorStyle;
use MSSTC4PHP\DtoGenerator\Domain\Target\DateTimeClass;
use MSSTC4PHP\DtoGenerator\Domain\Target\MetadataMode;
use MSSTC4PHP\DtoGenerator\Domain\Target\Mutability;
use MSSTC4PHP\DtoGenerator\Domain\Target\PhpVersion;
use MSSTC4PHP\DtoGenerator\Domain\Target\TargetProfile;

final class ModelFixture
{
    /**
     * @param array<int|string, array<array-key, mixed>> $schemas components/schemas of the spec
     * @param array<string, array<array-key, mixed>> $extraDocuments
     * @param list<string> $include
     */
    public static function build(array $schemas, array $extraDocuments = [], array $include = ['*']): Output
    {
        $source = ConfigMother::source(GraphFixture::SPEC, $include);
        $graph = GraphFixture::load($schemas, $extraDocuments, $source);
        $target = new TargetProfile(
            PhpVersion::fromString('8.2'),
            MetadataMode::from(MetadataMode::NONE),
            Mutability::from(Mutability::IMMUTABLE),
            AccessorStyle::from(AccessorStyle::AUTO),
            DateTimeClass::from(DateTimeClass::IMMUTABLE),
            true,
        );

        return (new Action(new NameResolver()))(new Input(ConfigMother::config($source), $target, $graph));
    }

    /**
     * @return array<string, list<string>> class → "wire: type" per property
     */
    public static function classes(Output $output): array
    {
        $classes = [];
        foreach ($output->classes() as $class) {
            $classes[$class->model()->name()->fqcn()] = array_map(
                static fn (PropertyModel $property): string => $property->wireName() . ': ' . $property->type()->describe(),
                $class->model()->properties(),
            );
        }

        return $classes;
    }

    /**
     * @return list<string>
     */
    public static function messages(Output $output): array
    {
        return array_map(static fn (Diagnostic $d): string => $d->toString(), $output->diagnostics()->all());
    }

    /**
     * @return list<int>
     */
    public static function sources(Output $output): array
    {
        return array_map(static fn (BuiltClass $class): int => $class->source(), $output->classes());
    }
}
```

`tests/Unit/Application/Service/Model/BuildTest.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Application\Service\Model;

use MSSTC4PHP\DtoGenerator\Tests\Support\ModelFixture;
use PHPUnit\Framework\TestCase;

final class BuildTest extends TestCase
{
    private const AT = '/project/api/openapi.yaml#/components/schemas/';

    public function testBuildsAClassPerObjectSchema(): void
    {
        $output = ModelFixture::build([
            'User' => ['type' => 'object', 'required' => ['id'], 'properties' => [
                'id' => ['type' => 'integer'],
                'tag' => ['$ref' => '#/components/schemas/Tag'],
                'email' => ['$ref' => '#/components/schemas/Email'],
            ]],
            'Tag' => ['type' => 'object', 'properties' => ['label' => ['type' => 'string']]],
            'Email' => ['type' => 'string', 'format' => 'email'],
            'Free' => ['type' => 'object'],
        ]);

        self::assertSame([], ModelFixture::messages($output));
        self::assertSame(
            [
                'App\Dto\User' => ['id: int', 'tag: App\Dto\Tag|null', 'email: string|null'],
                'App\Dto\Tag' => ['label: string|null'],
            ],
            ModelFixture::classes($output),
        );
        self::assertSame([0, 0], ModelFixture::sources($output));
    }

    public function testGeneratesSchemasThatAreOnlyReferenced(): void
    {
        $output = ModelFixture::build(
            [
                'User' => ['type' => 'object', 'properties' => [
                    'tag' => ['$ref' => '#/components/schemas/Tag'],
                    'salary' => ['$ref' => '../shared/common.json#/Money'],
                ]],
                'Tag' => ['type' => 'object', 'properties' => ['label' => ['type' => 'string']]],
                'Unused' => ['type' => 'object', 'properties' => ['x' => ['type' => 'string']]],
            ],
            ['/project/shared/common.json' => ['Money' => ['type' => 'object', 'properties' => ['amount' => ['type' => 'string']]]]],
            ['User'],
        );

        self::assertSame([], ModelFixture::messages($output));
        self::assertSame(['App\Dto\User', 'App\Dto\Tag', 'App\Dto\Money'], array_keys(ModelFixture::classes($output)));
    }

    public function testNamesClassesFromSchemaNamesOrOverrides(): void
    {
        $output = ModelFixture::build([
            'user_profile' => ['type' => 'object', 'properties' => ['id' => []]],
            'list' => ['type' => 'object', 'properties' => ['id' => []]],
            'Order' => ['type' => 'object', 'x-php-class-name' => 'PurchaseOrder', 'properties' => ['id' => []]],
        ]);

        self::assertSame([], ModelFixture::messages($output));
        self::assertSame(['App\Dto\UserProfile', 'App\Dto\List_', 'App\Dto\PurchaseOrder'], array_keys(ModelFixture::classes($output)));
    }

    /**
     * @dataProvider namingProblems
     *
     * @param array<int|string, array<array-key, mixed>> $schemas
     * @param list<string> $classes
     * @param list<string> $messages
     */
    public function testReportsNamingProblems(array $schemas, array $classes, array $messages): void
    {
        $output = ModelFixture::build($schemas);

        self::assertSame($messages, ModelFixture::messages($output));
        self::assertSame($classes, array_keys(ModelFixture::classes($output)));
    }

    /**
     * @return array<string, array{array<int|string, array<array-key, mixed>>, list<string>, list<string>}>
     */
    public static function namingProblems(): array
    {
        $object = ['type' => 'object', 'properties' => ['id' => []]];
        $at = self::AT;

        return [
            'case-only collision' => [
                ['User' => $object, 'user' => $object],
                ['App\Dto\User'],
                ["error {$at}user: Class App\\Dto\\User is already generated from {$at}User; set \"x-php-class-name\" on one of them."],
            ],
            'separator collision' => [
                ['user_profile' => $object, 'UserProfile' => $object],
                ['App\Dto\UserProfile'],
                ["error {$at}UserProfile: Class App\\Dto\\UserProfile is already generated from {$at}user_profile; set \"x-php-class-name\" on one of them."],
            ],
            'invalid override' => [
                ['User' => $object + ['x-php-class-name' => 'List']],
                [],
                ["error {$at}User/x-php-class-name: \"x-php-class-name\" must be a PHP identifier that is not a reserved word."],
            ],
            'no usable name' => [
                ['***' => $object],
                [],
                ["error {$at}***: Schema name \"***\" has no usable characters; set \"x-php-class-name\"."],
            ],
        ];
    }

    public function testSkipsSchemasMarkedWithXPhpSkip(): void
    {
        $output = ModelFixture::build([
            'User' => ['type' => 'object', 'properties' => ['secret' => ['$ref' => '#/components/schemas/Secret']]],
            'Secret' => ['type' => 'object', 'x-php-skip' => true, 'properties' => ['x' => []]],
        ]);

        self::assertSame(['App\Dto\User' => ['secret: mixed']], ModelFixture::classes($output));
        self::assertSame(["warning {$this->at()}User/properties/secret: \$ref points to a schema excluded by \"x-php-skip\"."], ModelFixture::messages($output));
    }

    public function testWarnsAboutSelectedSchemasItCannotBuildYet(): void
    {
        $output = ModelFixture::build([
            'Currency' => ['type' => 'string', 'enum' => ['EUR']],
            'Pet' => ['oneOf' => [['$ref' => '#/components/schemas/Cat']]],
            'Cat' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]],
        ]);

        self::assertSame(['App\Dto\Cat'], array_keys(ModelFixture::classes($output)));
        self::assertSame(
            [
                "warning {$this->at()}Currency: \"enum\" is not supported yet, so no class is generated for \"Currency\".",
                "warning {$this->at()}Pet: \"oneOf\" is not supported yet, so no class is generated for \"Pet\".",
            ],
            ModelFixture::messages($output),
        );
    }

    public function testKeepsGraphOrder(): void
    {
        $output = ModelFixture::build([
            'B' => ['type' => 'object', 'properties' => ['a' => ['$ref' => '#/components/schemas/A']]],
            'A' => ['type' => 'object', 'properties' => ['id' => []]],
        ]);

        self::assertSame(['App\Dto\B', 'App\Dto\A'], array_keys(ModelFixture::classes($output)));
    }

    private function at(): string
    {
        return self::AT;
    }
}
```

В `tests/Integration/PetstoreLoadingTest.php` в конец `testLoadsTheProjectEndToEnd()` добавить сборку модели и `use` для `Model\Build\Action as BuildModel`, `Model\Build\Input as BuildInput`, `Domain\Builder\NameResolver`:

```php
        $model = (new BuildModel(new NameResolver()))(new BuildInput($config, $target, $schemas->graph()));

        self::assertSame(
            ['error ' . $money->location()->file() . '#/definitions/Currency: "enum" is not supported yet; enums, composition and inline objects arrive in a later version.'],
            array_map(static fn (Diagnostic $d): string => $d->toString(), $model->diagnostics()->all()),
        );
        self::assertSame(
            ['App\Dto\Pet', 'App\Dto\Tag', 'App\Dto\Money'],
            array_map(static fn (BuiltClass $class): string => $class->model()->name()->fqcn(), $model->classes()),
        );
```

(+ `use MSSTC4PHP\DtoGenerator\Application\Service\Model\Build\BuiltClass;`)

- [ ] **Step 2: Убедиться, что тесты падают**

Run: `vendor/bin/phpunit --filter 'BuildTest|PetstoreLoadingTest'`
Expected: FAIL — `Class "...\Model\Build\Action" not found`.

- [ ] **Step 3: Реализовать**

`src/Application/Service/Model/Build/Input.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Service\Model\Build;

use MSSTC4PHP\DtoGenerator\Application\Config\GeneratorConfig;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaGraph;
use MSSTC4PHP\DtoGenerator\Domain\Target\TargetProfile;

final class Input
{
    private GeneratorConfig $config;

    private TargetProfile $target;

    private SchemaGraph $graph;

    public function __construct(GeneratorConfig $config, TargetProfile $target, SchemaGraph $graph)
    {
        $this->config = $config;
        $this->target = $target;
        $this->graph = $graph;
    }

    public function config(): GeneratorConfig
    {
        return $this->config;
    }

    public function target(): TargetProfile
    {
        return $this->target;
    }

    public function graph(): SchemaGraph
    {
        return $this->graph;
    }
}
```

`src/Application/Service/Model/Build/BuiltClass.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Service\Model\Build;

use MSSTC4PHP\DtoGenerator\Domain\Model\ClassModel;

/**
 * A class together with the config source whose outputDir it belongs to.
 */
final class BuiltClass
{
    private ClassModel $model;

    private int $source;

    public function __construct(ClassModel $model, int $source)
    {
        $this->model = $model;
        $this->source = $source;
    }

    public function model(): ClassModel
    {
        return $this->model;
    }

    public function source(): int
    {
        return $this->source;
    }
}
```

`src/Application/Service/Model/Build/Output.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Service\Model\Build;

use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;

final class Output
{
    /** @var list<BuiltClass> */
    private array $classes;

    private Diagnostics $diagnostics;

    /**
     * @param list<BuiltClass> $classes in graph order
     */
    public function __construct(array $classes, Diagnostics $diagnostics)
    {
        $this->classes = $classes;
        $this->diagnostics = $diagnostics;
    }

    /**
     * @return list<BuiltClass>
     */
    public function classes(): array
    {
        return $this->classes;
    }

    public function diagnostics(): Diagnostics
    {
        return $this->diagnostics;
    }
}
```

`src/Application/Service/Model/Build/Action.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Service\Model\Build;

use MSSTC4PHP\DtoGenerator\Domain\Builder\ClassBuilder;
use MSSTC4PHP\DtoGenerator\Domain\Builder\NameResolver;
use MSSTC4PHP\DtoGenerator\Domain\Builder\SchemaShape;
use MSSTC4PHP\DtoGenerator\Domain\Builder\TypeMapper;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;
use MSSTC4PHP\DtoGenerator\Domain\Model\Identifier;
use MSSTC4PHP\DtoGenerator\Domain\Schema\ResolvedSchema;

/**
 * Turns the schema graph into the IR: one class per object schema, including schemas only reached by $ref,
 * in the namespace of the source that owns them.
 */
final class Action
{
    private NameResolver $names;

    public function __construct(NameResolver $names)
    {
        $this->names = $names;
    }

    public function __invoke(Input $input): Output
    {
        $diagnostics = new Diagnostics();
        $config = $input->config();
        /** @var array<string, ClassName> $classes */
        $classes = [];
        /** @var array<string, true> $skipped */
        $skipped = [];
        /** @var array<string, string> $taken lower-cased FQCN → location that claimed it */
        $taken = [];
        /** @var list<array{ResolvedSchema, ClassName, int}> $planned */
        $planned = [];

        foreach ($input->graph()->all() as $resolved) {
            $source = $resolved->source();
            $schema = $resolved->schema();
            $key = $resolved->location()->toString();
            if ($source === null) {
                continue;
            }

            if (!SchemaShape::isClass($schema)) {
                $unsupported = SchemaShape::unsupportedKeyword($schema);
                if ($unsupported !== null && $resolved->isSelected()) {
                    $diagnostics->warning(
                        sprintf('"%s" is not supported yet, so no class is generated for "%s".', $unsupported, $resolved->name()),
                        $schema->location(),
                    );
                }

                continue;
            }

            if (ClassBuilder::isSkipped($schema, $diagnostics)) {
                $skipped[$key] = true;

                continue;
            }

            $short = $this->shortName($resolved, $diagnostics);
            if ($short === null) {
                continue;
            }

            $name = ClassName::fromFqcn($config->sources()[$source]->namespace() . '\\' . $short);
            $lower = Identifier::asciiLower($name->fqcn());
            if (isset($taken[$lower])) {
                $diagnostics->error(
                    sprintf('Class %s is already generated from %s; set "x-php-class-name" on one of them.', $name->fqcn(), $taken[$lower]),
                    $schema->location(),
                );

                continue;
            }

            $taken[$lower] = $key;
            $classes[$key] = $name;
            $planned[] = [$resolved, $name, $source];
        }

        $builder = new ClassBuilder(
            $this->names,
            new TypeMapper($input->graph(), $classes, $skipped, $input->target(), $config->formats()),
            $input->target(),
        );
        $built = [];
        foreach ($planned as [$resolved, $name, $source]) {
            $built[] = new BuiltClass($builder->build($name, $resolved, $diagnostics), $source);
        }

        return new Output($built, $diagnostics);
    }

    private function shortName(ResolvedSchema $resolved, Diagnostics $diagnostics): ?string
    {
        $schema = $resolved->schema();
        if ($schema->extensions()->has('x-php-class-name')) {
            $override = $schema->extensions()->get('x-php-class-name');
            if (is_string($override) && Identifier::isValid($override) && !Identifier::isReserved($override)) {
                return $override;
            }

            $diagnostics->error(
                '"x-php-class-name" must be a PHP identifier that is not a reserved word.',
                $schema->location()->child('x-php-class-name'),
            );

            return null;
        }

        $name = $this->names->className($resolved->name());
        if ($name === null) {
            $diagnostics->error(
                sprintf('Schema name "%s" has no usable characters; set "x-php-class-name".', $resolved->name()),
                $schema->location(),
            );
        }

        return $name;
    }
}
```

- [ ] **Step 4: Прогнать тесты**

Run: `vendor/bin/phpunit`
Expected: PASS.

- [ ] **Step 5: Гейт и коммит**

Run: `make fix && make verify`
Expected: зелёное, deptrac без нарушений (Application → Domain/DomainService).

```bash
git add src/Application/Service/Model tests/Support/ModelFixture.php tests/Unit/Application/Service/Model tests/Integration/PetstoreLoadingTest.php
git commit -m "feat(model): Model/Build use-case turning the schema graph into the IR

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: Закрытие этапа

**Files:**
- Modify: `infection.json5` (при необходимости, только эквивалентные мутанты по методам с причиной), `README.md`, `.claude/docs/architecture.md`, `.claude/docs/domain-model.md`, `.claude/docs/known-issues.md`, `.claude/docs/conventions.md`

**Interfaces:**
- Consumes: всё из Tasks 1–4.
- Produces: MSI ≥ 99 %, актуальная база знаний.

- [ ] **Step 1: Мутации**

Run: `make infection`
Expected: MSI ≥ 99 %. Реальных выживших убить тестами в тест-классах соответствующих задач. Эквивалентных занести в `infection.json5` по методу и с причиной. Пример: `(string) substr` в `Identifier::asciiUpperFirst/asciiLowerFirst` — offset 1 у непустой строки.

- [ ] **Step 2: Документация**

В `README.md` заменить строку статуса:

```markdown
> **Статус:** в разработке. Готовы этапы 1–2: доменная модель, загрузка конфига и спецификаций с графом `$ref`, построение IR (классы и свойства по spec §5.1–§5.5).
> Генерация файлов, CLI, Composer-плагин и Docker-образ появятся в следующих этапах.
```

В `.claude/docs/architecture.md` дописать абзац:

```markdown
Этап 2b: `Service/Model/Build` — граф схем → IR. `Domain/Builder`: `NameResolver` (имена), `SchemaShape` (форма класса / неподдержанный keyword), `TypeMapper` (§5.1), `ClassBuilder` (§5.2, §5.4, §5.5, проверка `x-`). Порядок: регистрация всех классов графа (имена, коллизии) → построение свойств с уже известными именами целей `$ref`.
```

В `.claude/docs/domain-model.md` дописать раздел:

```markdown
## Builder (этап 2b)
- Класс = схема без `$ref`/неподдержанных keyword'ов, с `properties` и типом `object` или без типа (`SchemaShape::isClass`). Прочие именованные схемы — алиасы: `$ref` на них встраивает их тип (с защитой от циклов).
- Схемы только-по-`$ref` (selected=false) тоже становятся классами — в namespace источника-владельца (решение владельца 2026-10-01 UTC).
- Свойство: обязательное и не-nullable → без default; иначе `?T` и default из схемы или `null`. Default у класса (дата) или map → warning и `null`; `INF`/`NAN` → ошибка.
- Имена: класс — PascalCase, ведущая цифра `_`, зарезервированное слово `_` в конце; свойство — camelCase, первое слово целиком в капсе приводится к нижнему регистру целиком, `this` → `this_`. Коллизии — без учёта ASCII-регистра.
- Неподдержанное до этапа 4 (`enum`, `allOf/oneOf/anyOf`, `discriminator`, `additionalProperties`-схема, инлайн-объект): в свойстве — ошибка и `mixed`; у выбранного компонента — warning и класс не создаётся.
```

В `.claude/docs/known-issues.md` дописать:

```markdown
- **Акронимы в именах свойств.** `URL_PATH` → `urlPATH`: целиком в нижний регистр приводится только первое слово. Нужен другой вид — `x-php-name`.
- **Required + default.** Default обязательного не-nullable свойства игнорируется молча (spec §5.2: обязательный аргумент без значения).
- **Схема без типа** (`{}`) → `mixed`; `type: object` без `properties` → `array<array-key, mixed>`.
```

В `.claude/docs/conventions.md` дописать:

```markdown
- Регистр первой буквы — `Identifier::asciiUpperFirst/asciiLowerFirst` (не `ucfirst/lcfirst`: на 7.4 зависят от локали).
- Тесты Builder'а строят граф через `tests/Support/GraphFixture` (настоящий `Schemas/Load` на `InMemoryDocumentLoader`), IR — через `tests/Support/ModelFixture`.
```

- [ ] **Step 3: Гейт и коммит**

Run: `make fix && make verify && make infection`
Expected: зелёное, MSI ≥ 99 %.

```bash
git add infection.json5 README.md .claude/docs
git commit -m "docs: stage 2b knowledge base and README status

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

- [ ] **Step 4: Закрытие итерации по глобальным правилам**

`/acc:code-review high` по ветке (новый модуль). Исправить все находки, после нетривиальных исправлений провести повторный ревью, дополнить `.claude/docs/`.

---

## Следующий план

**Этап 3 — Emitter.** IR → PHP-код для всех версий PHP (форма класса по §6.2, PHPDoc по §5.5), Writer с манифестом, CLI и golden-матрица с проверкой вывода в Docker на каждой целевой версии.
