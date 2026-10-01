# DTO Generator — этап 2a: загрузка конфига и схем. План реализации

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** По абсолютному пути к `dto-generator.yaml` получить проверенный `GeneratorConfig`, `TargetProfile` и граф разобранных схем `SchemaGraph`: все выбранные `components/schemas` всех источников плюс всё, на что они ссылаются через `$ref`, включая внешние файлы. Все ошибки возвращаются как диагностика с местом в файле.

**Architecture:**
- **Domain:** чистые помощники — `Path`, `JsonPointer`, `Reference`, `Diagnostics`, `SchemaGraph`.
- **DomainService:** разбор «сырого» JSON в `Schema` (`Domain/Builder/SchemaParser`).
- **Application:** модель конфига, два use-case'а (`Config/Load`, `Schemas/Load`) и порты `DocumentLoader` / `ProjectPhpConstraint`.
- **Infrastructure:** адаптеры порта: файловый загрузчик YAML/JSON на `symfony/yaml` и поиск `composer.json`.

Ошибки не бросаются наружу. Они копятся в `Diagnostics`, а проблемный фрагмент пропускается, поэтому за один прогон видны все ошибки.

**Tech Stack:** PHP ≥ 7.4, `symfony/yaml ^5.4 || ^6.4 || ^7.0`, PHPUnit 9.6, PHPStan 2 max (`phpVersion: 70400`), deptrac, Rector, CS-Fixer, Infection.

**Spec:** `docs/specs/2026-10-01-dto-generator-design.md`. План реализует:
- §13 этап 2, первую половину: загрузчик, `$ref`, конфиг;
- §4 — конфиг, правила `auto` для `target.php` и `target.metadata`, несколько источников;
- §10 — классы ошибок «Конфиг» и «Схема».

Builder (схема → IR) — в плане 2b.

## Global Constraints

- Исходники и тесты парсятся и работают на PHP 7.4. Запрещены: `enum`, `readonly`, атрибуты, `match`, union/`mixed`/`static` в сигнатурах, promoted-свойства, именованные аргументы, `?->`, функции PHP 8+. Висячая запятая допустима только в вызовах и массивах.
- PHPStan `level: max` без ignore и baseline. Тесты исключений проверяют класс **и** подстроку сообщения.
- Классы `final`. Объекты-значения неизменяемы, изменения — через `with*()`, которые строят копию через конструктор.
- Сравнение идентификаторов — через `Identifier::asciiLower()`. Регулярки на целую строку якорятся `\z`.
- Доменные ошибки → `InvalidModel` (`DomainError`). Ошибки пользовательского ввода (конфиг, схемы) → `Diagnostics` с `SchemaLocation`, без исключений.
- Пути внутри системы — абсолютные и нормализованные через `Path::normalize()`: только `/`, без `.` и `..`. Ключ схемы в графе — `SchemaLocation::toString()` от лексически нормализованного пути. Symlink'и не раскрываются.
- Комментарии — на английском, только «почему». Документация — на русском. Даты — UTC.
- Коммит заканчивается строкой `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- Гейт каждой задачи: `make fix && make verify`. Docker-цели запускаются вне песочницы. В конце этапа MSI ≥ 99 % (`make infection`).

## Review Focus

1. **Версия PHP без кавычек в YAML** (`php: 8.2`, `php: 8.0`) приходит как float, а `8.0` превращается в `"8"`. Ожидается, что сработает как `"8.2"` / `"8.0"`. Тест — Task 6.
2. **Даты без кавычек в YAML** (`default: 2020-01-01`) `symfony/yaml` превращает в int-timestamp. Ожидается, что поведение зафиксировано тестом и описано в known-issues, а не всплывёт сюрпризом в этапе 2b. Тест — Task 5.
3. **Циклические `$ref`** (`User → Group → User`, ссылка на себя) должны завершаться, а каждая схема — попадать в граф ровно один раз. Тест — Task 8.
4. **`$ref` с percent- и `~`-экранированием** (`#/components/schemas/My%20Type`, `#/a~1b`) должны находить схему. Тесты — Task 3 и Task 8.
5. **Один файл, записанный по-разному** (`../other/openapi.yaml` в `$ref` и `./other/openapi.yaml` в конфиге) должен давать один ключ и одну схему, а не два класса. Тесты — Task 2 и Task 8.

---

## Карта файлов

```
src/Domain/Diagnostic/        Severity, Diagnostic, Diagnostics
src/Domain/Shared/            Path (new), Json (+isList, +value)
src/Domain/Schema/            JsonPointer, Reference, ReferenceUse, ResolvedSchema, SchemaGraph (new);
                              Schema (+references), Discriminator (bare names), SchemaLocation (child via JsonPointer)
src/Domain/Target/            AllOfStrategy (new), Capability (+RESERVED_NAMESPACE_SEGMENTS)
src/Domain/Builder/           SchemaParser
src/Application/ValueObject/  Document
src/Application/Port/         DocumentLoader, DocumentLoadFailed, ProjectPhpConstraint
src/Application/Config/       RawSection, ConfigFactory, GeneratorConfig, TargetSettings, DtoSettings,
                              ExtensionSettings, SourceConfig, PhpConstraint, TargetResolver
src/Application/Service/Config/Load/   Action, Input, Output
src/Application/Service/Schemas/Load/  Action, Input, Output, GraphBuilder
src/Infrastructure/Document/  FileDocumentLoader
src/Infrastructure/Environment/ ComposerJsonPhpConstraint
tests/Support/                InMemoryDocumentLoader, FixedPhpConstraint, ConfigMother
tests/Integration/            FileDocumentLoaderTest, ComposerJsonPhpConstraintTest, PetstoreLoadingTest
tests/Fixtures/               Documents/*, Projects/petstore/*
```

---

### Task 1: Диагностика

**Files:**
- Create: `src/Domain/Diagnostic/Severity.php`, `src/Domain/Diagnostic/Diagnostic.php`, `src/Domain/Diagnostic/Diagnostics.php`
- Modify: `tests/Unit/Domain/Shared/EnumValuesTest.php`
- Test: `tests/Unit/Domain/Diagnostic/DiagnosticsTest.php`

**Interfaces:**
- Consumes: `AbstractEnum`, `SchemaLocation`, `InvalidModel`.
- Produces:
  - `Severity` (`ERROR`, `WARNING`) с методом `isError()`.
  - `Diagnostic::__construct(Severity, string $message, ?SchemaLocation $location = null)`, методы `severity()`, `message()`, `location()`, `toString()`. Формат строки: `"error a.yaml#/x: msg"` или `"warning: msg"`.
  - `Diagnostics implements Countable` — изменяемый сборщик. Методы: `error(string, ?SchemaLocation = null)`, `warning(...)`, `add(Diagnostic)`, `merge(Diagnostics)`, `all(): list<Diagnostic>`, `errors(): list<Diagnostic>`, `hasErrors(): bool`, `count()`.

- [ ] **Step 1: Написать падающие тесты**

`tests/Unit/Domain/Diagnostic/DiagnosticsTest.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Domain\Diagnostic;

use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostic;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Severity;
use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;
use PHPUnit\Framework\TestCase;

final class DiagnosticsTest extends TestCase
{
    public function testCollectsInOrderAndKnowsAboutErrors(): void
    {
        $diagnostics = new Diagnostics();
        $diagnostics->warning('Looks odd.');

        self::assertFalse($diagnostics->hasErrors());

        $location = new SchemaLocation('a.yaml', '/x');
        $diagnostics->error('Broken.', $location);

        self::assertTrue($diagnostics->hasErrors());
        self::assertCount(2, $diagnostics);
        self::assertSame(
            ['warning: Looks odd.', 'error a.yaml#/x: Broken.'],
            array_map(static fn (Diagnostic $diagnostic): string => $diagnostic->toString(), $diagnostics->all()),
        );

        $error = $diagnostics->errors()[0];
        self::assertCount(1, $diagnostics->errors());
        self::assertSame($location, $error->location());
        self::assertTrue($error->severity()->isError());
        self::assertFalse($diagnostics->all()[0]->severity()->isError());
        self::assertSame('Broken.', $error->message());
    }

    public function testMergesAnotherCollector(): void
    {
        $first = new Diagnostics();
        $first->warning('a');
        $second = new Diagnostics();
        $second->error('b');

        $first->merge($second);

        self::assertSame(['a', 'b'], array_map(static fn (Diagnostic $diagnostic): string => $diagnostic->message(), $first->all()));
    }

    public function testRejectsABlankMessage(): void
    {
        $this->expectException(InvalidModel::class);
        $this->expectExceptionMessage('needs a message');

        new Diagnostic(Severity::from(Severity::ERROR), '  ');
    }
}
```

В `tests/Unit/Domain/Shared/EnumValuesTest.php` добавить строку в `valueSets()` и `use` для `Severity`:

```php
            'severity' => [Severity::class, ['error', 'warning']],
```

```php
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Severity;
```

- [ ] **Step 2: Убедиться, что тесты падают**

Run: `vendor/bin/phpunit --filter 'DiagnosticsTest|EnumValuesTest'`
Expected: FAIL — `Class "MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics" not found`.

- [ ] **Step 3: Реализовать**

`src/Domain/Diagnostic/Severity.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Diagnostic;

use MSSTC4PHP\DtoGenerator\Domain\Shared\AbstractEnum;

final class Severity extends AbstractEnum
{
    public const ERROR = 'error';

    public const WARNING = 'warning';

    public function isError(): bool
    {
        return $this->value() === self::ERROR;
    }

    protected static function values(): array
    {
        return [self::ERROR, self::WARNING];
    }
}
```

`src/Domain/Diagnostic/Diagnostic.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Diagnostic;

use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;

final class Diagnostic
{
    private Severity $severity;

    private string $message;

    private ?SchemaLocation $location;

    public function __construct(Severity $severity, string $message, ?SchemaLocation $location = null)
    {
        if (trim($message) === '') {
            throw new InvalidModel('A diagnostic needs a message.');
        }

        $this->severity = $severity;
        $this->message = $message;
        $this->location = $location;
    }

    public function severity(): Severity
    {
        return $this->severity;
    }

    public function message(): string
    {
        return $this->message;
    }

    public function location(): ?SchemaLocation
    {
        return $this->location;
    }

    public function toString(): string
    {
        if (!$this->location instanceof SchemaLocation) {
            return sprintf('%s: %s', $this->severity->value(), $this->message);
        }

        return sprintf('%s %s: %s', $this->severity->value(), $this->location->toString(), $this->message);
    }
}
```

`src/Domain/Diagnostic/Diagnostics.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Diagnostic;

use Countable;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;

/**
 * Collecting parameter: generation reports every problem it finds instead of stopping at the first.
 */
final class Diagnostics implements Countable
{
    /** @var list<Diagnostic> */
    private array $items = [];

    public function error(string $message, ?SchemaLocation $location = null): void
    {
        $this->add(new Diagnostic(Severity::from(Severity::ERROR), $message, $location));
    }

    public function warning(string $message, ?SchemaLocation $location = null): void
    {
        $this->add(new Diagnostic(Severity::from(Severity::WARNING), $message, $location));
    }

    public function add(Diagnostic $diagnostic): void
    {
        $this->items[] = $diagnostic;
    }

    public function merge(self $other): void
    {
        foreach ($other->items as $diagnostic) {
            $this->items[] = $diagnostic;
        }
    }

    /**
     * @return list<Diagnostic>
     */
    public function all(): array
    {
        return $this->items;
    }

    /**
     * @return list<Diagnostic>
     */
    public function errors(): array
    {
        return array_values(array_filter(
            $this->items,
            static fn (Diagnostic $diagnostic): bool => $diagnostic->severity()->isError(),
        ));
    }

    public function hasErrors(): bool
    {
        return $this->errors() !== [];
    }

    public function count(): int
    {
        return count($this->items);
    }
}
```

- [ ] **Step 4: Прогнать тесты**

Run: `vendor/bin/phpunit --filter 'DiagnosticsTest|EnumValuesTest'`
Expected: PASS.

- [ ] **Step 5: Гейт и коммит**

Run: `make fix && make verify`
Expected: всё зелёное на 8.4 и 7.4.

```bash
git add src/Domain/Diagnostic tests/Unit/Domain/Diagnostic tests/Unit/Domain/Shared/EnumValuesTest.php
git commit -m "feat(diagnostic): located diagnostics collector

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: Пути и разбор JSON-значений

**Files:**
- Create: `src/Domain/Shared/Path.php`
- Modify: `src/Domain/Shared/Json.php`
- Test: `tests/Unit/Domain/Shared/PathTest.php`, `tests/Unit/Domain/Shared/JsonTest.php`

**Interfaces:**
- Consumes: `InvalidModel`.
- Produces:
  - `Path::isAbsolute(string): bool`.
  - `Path::normalize(string): string` — только `/`, без `.`/`..` и двойных слэшей. Сохраняет `/` или `C:/` в начале. Ведущие `..` у относительного пути остаются.
  - `Path::resolve(string $baseDir, string $path): string` — абсолютный путь не меняет, относительный присоединяет к `$baseDir`. Результат нормализован.
  - `Path::directory(string): string` — `'/a/b.yaml'` → `'/a'`, `'/b.yaml'` → `'/'`, `'b.yaml'` → `'.'`.
  - `Json::isList(array): bool` и `Json::value(mixed): JsonValue`. Второй бросает `InvalidModel` для объектов и ресурсов.

- [ ] **Step 1: Написать падающие тесты**

`tests/Unit/Domain/Shared/PathTest.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Domain\Shared;

use MSSTC4PHP\DtoGenerator\Domain\Shared\Path;
use PHPUnit\Framework\TestCase;

final class PathTest extends TestCase
{
    /**
     * @dataProvider normalizations
     */
    public function testNormalizes(string $path, string $expected): void
    {
        self::assertSame($expected, Path::normalize($path));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function normalizations(): array
    {
        return [
            'dots and double slashes' => ['/a/./b//c', '/a/b/c'],
            'parent' => ['/a/b/../c', '/a/c'],
            'parent above root' => ['/../a', '/a'],
            'relative parent kept' => ['a/../../b', '../b'],
            'windows' => ['C:\\x\\y\\..\\z', 'C:/x/z'],
            'current dir prefix' => ['./a', 'a'],
            'root' => ['/', '/'],
            'empty' => ['', ''],
        ];
    }

    /**
     * @dataProvider resolutions
     */
    public function testResolvesAgainstABaseDirectory(string $base, string $path, string $expected): void
    {
        self::assertSame($expected, Path::resolve($base, $path));
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function resolutions(): array
    {
        return [
            'relative' => ['/base/dir', 'spec/openapi.yaml', '/base/dir/spec/openapi.yaml'],
            'parent' => ['/base/dir', '../x.yaml', '/base/x.yaml'],
            'dot segment' => ['/base/dir', './other/../x.yaml', '/base/dir/x.yaml'],
            'absolute' => ['/base', '/abs/x.yaml', '/abs/x.yaml'],
            'windows absolute' => ['/base', 'C:/abs/x.yaml', 'C:/abs/x.yaml'],
        ];
    }

    /**
     * @dataProvider directories
     */
    public function testFindsTheDirectory(string $path, string $expected): void
    {
        self::assertSame($expected, Path::directory($path));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function directories(): array
    {
        return [
            'nested' => ['/a/b.yaml', '/a'],
            'in root' => ['/b.yaml', '/'],
            'root itself' => ['/', '/'],
            'relative file' => ['b.yaml', '.'],
            'windows drive' => ['C:/b.yaml', 'C:/'],
        ];
    }

    public function testRecognisesAbsolutePaths(): void
    {
        self::assertTrue(Path::isAbsolute('/a'));
        self::assertTrue(Path::isAbsolute('C:\\a'));
        self::assertTrue(Path::isAbsolute('\\\\server\\share'));
        self::assertFalse(Path::isAbsolute('a/b'));
        self::assertFalse(Path::isAbsolute('C:a'));
    }
}
```

`tests/Unit/Domain/Shared/JsonTest.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Domain\Shared;

use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Json;
use PHPUnit\Framework\TestCase;
use stdClass;

final class JsonTest extends TestCase
{
    public function testRecognisesLists(): void
    {
        self::assertTrue(Json::isList([]));
        self::assertTrue(Json::isList(['a', 'b']));
        self::assertFalse(Json::isList([1 => 'a']));
        self::assertFalse(Json::isList(['a' => 1]));
        self::assertFalse(Json::isList([0 => 'a', 2 => 'b']));
    }

    public function testPassesDecodedValuesThrough(): void
    {
        self::assertNull(Json::value(null));
        self::assertSame('s', Json::value('s'));
        self::assertSame(1.5, Json::value(1.5));
        self::assertSame(['a' => [1]], Json::value(['a' => [1]]));
    }

    public function testRejectsObjects(): void
    {
        $this->expectException(InvalidModel::class);
        $this->expectExceptionMessage('stdClass is not a JSON value');

        Json::value(new stdClass());
    }
}
```

- [ ] **Step 2: Убедиться, что тесты падают**

Run: `vendor/bin/phpunit --filter 'PathTest|JsonTest'`
Expected: FAIL — `Class "...\Shared\Path" not found` и `Call to undefined method ...Json::isList()`.

- [ ] **Step 3: Реализовать**

`src/Domain/Shared/Path.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Shared;

/**
 * Lexical path handling; never touches the filesystem, so symlinks are not resolved.
 */
final class Path
{
    private function __construct()
    {
    }

    public static function isAbsolute(string $path): bool
    {
        return strncmp($path, '/', 1) === 0
            || strncmp($path, '\\', 1) === 0
            || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1;
    }

    public static function resolve(string $baseDir, string $path): string
    {
        return self::normalize(self::isAbsolute($path) ? $path : rtrim($baseDir, '/\\') . '/' . $path);
    }

    public static function normalize(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $prefix = preg_match('#^(?:[A-Za-z]:)?/#', $path, $matches) === 1 ? $matches[0] : '';

        $segments = [];
        foreach (explode('/', (string) substr($path, strlen($prefix))) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                if ($segments !== [] && $segments[count($segments) - 1] !== '..') {
                    array_pop($segments);

                    continue;
                }

                if ($prefix !== '') {
                    continue;
                }
            }

            $segments[] = $segment;
        }

        return $prefix . implode('/', $segments);
    }

    public static function directory(string $path): string
    {
        $normalized = self::normalize($path);
        $position = strrpos($normalized, '/');
        if ($position === false) {
            return '.';
        }

        $directory = (string) substr($normalized, 0, $position);

        return $directory === '' || preg_match('#^[A-Za-z]:\z#', $directory) === 1 ? $directory . '/' : $directory;
    }
}
```

`src/Domain/Shared/Json.php` — добавить в класс два метода и `use` для `InvalidModel`:

```php
    /**
     * @param array<array-key, mixed> $array
     */
    public static function isList(array $array): bool
    {
        $expected = 0;
        foreach (array_keys($array) as $key) {
            if ($key !== $expected) {
                return false;
            }

            $expected++;
        }

        return true;
    }

    /**
     * Narrows a decoded value; decoders never produce objects or resources, so those are rejected.
     *
     * @param mixed $value
     *
     * @return JsonValue
     */
    public static function value($value)
    {
        if ($value === null || is_scalar($value) || is_array($value)) {
            return $value;
        }

        throw new InvalidModel(sprintf('%s is not a JSON value.', is_object($value) ? get_class($value) : gettype($value)));
    }
```

```php
use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;
```

- [ ] **Step 4: Прогнать тесты**

Run: `vendor/bin/phpunit --filter 'PathTest|JsonTest'`
Expected: PASS.

- [ ] **Step 5: Гейт и коммит**

Run: `make fix && make verify`
Expected: зелёное.

```bash
git add src/Domain/Shared tests/Unit/Domain/Shared
git commit -m "feat(shared): lexical paths and decoded JSON narrowing

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: JSON pointer, `$ref` и перечень ссылок схемы

**Files:**
- Create: `src/Domain/Schema/JsonPointer.php`, `src/Domain/Schema/Reference.php`, `src/Domain/Schema/ReferenceUse.php`
- Modify: `src/Domain/Schema/SchemaLocation.php` (`child()`), `src/Domain/Schema/Discriminator.php` (bare names), `src/Domain/Schema/Schema.php` (`references()`)
- Test: `tests/Unit/Domain/Schema/JsonPointerTest.php`, `tests/Unit/Domain/Schema/ReferenceTest.php`; Modify: `tests/Unit/Domain/Schema/DiscriminatorTest.php`, `tests/Unit/Domain/Schema/SchemaTest.php`

**Interfaces:**
- Consumes: `Path`, `Json` (Task 2), `SchemaLocation`, `Schema`, `SchemaBuilder`, `Discriminator`.
- Produces:
  - `JsonPointer::segments(string): list<string>` — декодирует `~1`/`~0`; при неверном pointer бросает `InvalidModel`.
  - `JsonPointer::fromSegments(string ...): string`.
  - `JsonPointer::has(array $document, string $pointer): bool` и `JsonPointer::get(array, string): JsonValue` (бросает `InvalidModel`).
  - `Reference::target(string $ref, SchemaLocation $from): ?SchemaLocation` — `null` для удалённых (`scheme://`). Бросает `InvalidModel`, если `$ref` пуст, фрагмент — якорь или escape неверный. Фрагмент проходит через `rawurldecode`.
  - `ReferenceUse::__construct(string $ref, SchemaLocation $location)`, методы `ref()`, `location()`.
  - `Schema::references(): list<ReferenceUse>` — все `$ref` в схеме и её подсхемах, плюс цели `discriminator.mapping`.
  - `Discriminator` хранит mapping уже нормализованным: значение без `/` и `#` превращается в `#/components/schemas/<value>`.

- [ ] **Step 1: Написать падающие тесты**

`tests/Unit/Domain/Schema/JsonPointerTest.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Domain\Schema;

use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;
use MSSTC4PHP\DtoGenerator\Domain\Schema\JsonPointer;
use PHPUnit\Framework\TestCase;

final class JsonPointerTest extends TestCase
{
    private const DOCUMENT = [
        'components' => ['schemas' => ['User' => ['type' => 'object'], 'a/b' => ['type' => 'string']]],
        'list' => ['x', 'y'],
        'nothing' => null,
    ];

    public function testSplitsAndDecodesSegments(): void
    {
        self::assertSame([], JsonPointer::segments(''));
        self::assertSame(['a/b', 'c~d', ''], JsonPointer::segments('/a~1b/c~0d/'));
    }

    public function testEncodesSegments(): void
    {
        self::assertSame('/components/schemas/a~1b/x~0y', JsonPointer::fromSegments('components', 'schemas', 'a/b', 'x~y'));
        self::assertSame('', JsonPointer::fromSegments());
    }

    /**
     * @dataProvider invalidPointers
     */
    public function testRejectsInvalidPointers(string $pointer): void
    {
        $this->expectException(InvalidModel::class);
        $this->expectExceptionMessage('is not a valid JSON pointer');

        JsonPointer::segments($pointer);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidPointers(): array
    {
        return [
            'no leading slash' => ['components'],
            'bad escape' => ['/a~2'],
            'trailing tilde' => ['/a~'],
        ];
    }

    public function testNavigatesMapsAndLists(): void
    {
        self::assertSame(['type' => 'object'], JsonPointer::get(self::DOCUMENT, '/components/schemas/User'));
        self::assertSame(['type' => 'string'], JsonPointer::get(self::DOCUMENT, '/components/schemas/a~1b'));
        self::assertSame('y', JsonPointer::get(self::DOCUMENT, '/list/1'));
        self::assertSame(self::DOCUMENT, JsonPointer::get(self::DOCUMENT, ''));
    }

    public function testDistinguishesAFoundNullFromAMissingValue(): void
    {
        self::assertTrue(JsonPointer::has(self::DOCUMENT, '/nothing'));
        self::assertNull(JsonPointer::get(self::DOCUMENT, '/nothing'));
        self::assertFalse(JsonPointer::has(self::DOCUMENT, '/missing'));
        self::assertFalse(JsonPointer::has(self::DOCUMENT, '/list/5'));
        self::assertFalse(JsonPointer::has(self::DOCUMENT, '/nothing/deeper'));
    }

    public function testGetRejectsAMissingValue(): void
    {
        $this->expectException(InvalidModel::class);
        $this->expectExceptionMessage('JSON pointer "/missing" does not resolve');

        JsonPointer::get(self::DOCUMENT, '/missing');
    }
}
```

`tests/Unit/Domain/Schema/ReferenceTest.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Domain\Schema;

use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Reference;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;
use PHPUnit\Framework\TestCase;

final class ReferenceTest extends TestCase
{
    /**
     * @dataProvider targets
     */
    public function testResolvesAgainstTheReferringFile(string $ref, string $expected): void
    {
        $from = new SchemaLocation('/spec/api/openapi.yaml', '/components/schemas/User/properties/tag');
        $target = Reference::target($ref, $from);

        self::assertNotNull($target);
        self::assertSame($expected, $target->toString());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function targets(): array
    {
        return [
            'internal' => ['#/components/schemas/Tag', '/spec/api/openapi.yaml#/components/schemas/Tag'],
            'external file and pointer' => ['common.json#/definitions/Money', '/spec/api/common.json#/definitions/Money'],
            'parent directory' => ['../shared/x.yaml#/X', '/spec/shared/x.yaml#/X'],
            'whole file' => ['common.json', '/spec/api/common.json#'],
            'whole current document' => ['#', '/spec/api/openapi.yaml#'],
            'percent-encoded fragment' => ['#/components/schemas/My%20Type', '/spec/api/openapi.yaml#/components/schemas/My Type'],
            'escaped slash stays escaped' => ['#/components/schemas/a~1b', '/spec/api/openapi.yaml#/components/schemas/a~1b'],
            'percent-encoded file' => ['my%20file.yaml#/X', '/spec/api/my file.yaml#/X'],
        ];
    }

    public function testRemoteReferencesYieldNull(): void
    {
        self::assertNull(Reference::target('https://example.com/schemas.json#/X', new SchemaLocation('/a.yaml')));
    }

    /**
     * @dataProvider malformed
     */
    public function testRejectsMalformedReferences(string $ref, string $message): void
    {
        $this->expectException(InvalidModel::class);
        $this->expectExceptionMessage($message);

        Reference::target($ref, new SchemaLocation('/a.yaml'));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function malformed(): array
    {
        return [
            'empty' => ['', 'Empty $ref'],
            'anchor' => ['#User', 'only JSON pointer fragments are supported'],
            'bad escape' => ['#/a~2', 'is not a valid JSON pointer'],
        ];
    }
}
```

В `tests/Unit/Domain/Schema/DiscriminatorTest.php` добавить тест:

```php
    public function testABareMappingValueNamesAComponentSchema(): void
    {
        $discriminator = new Discriminator('kind', ['cat' => 'Cat', 'dog' => 'dog.yaml#/Dog']);

        self::assertSame('#/components/schemas/Cat', $discriminator->refFor('cat'));
        self::assertSame('dog.yaml#/Dog', $discriminator->refFor('dog'));
    }
```

В `tests/Unit/Domain/Schema/SchemaTest.php` добавить тест и `use` для `ReferenceUse` и `Discriminator` (если его ещё нет):

```php
    public function testListsEveryReferenceWithItsLocation(): void
    {
        $tag = $this->builder('/properties/tag')->ref('#/components/schemas/Tag')->build();
        $item = $this->builder('/properties/list/items')->ref('#/components/schemas/Item')->build();
        $list = $this->builder('/properties/list')->types($this->type(SchemaType::ARRAY))->items($item)->build();
        $base = $this->builder('/allOf/0')->ref('base.yaml')->build();
        $extra = $this->builder('/additionalProperties')->ref('#/components/schemas/Extra')->build();
        $schema = $this->builder()
            ->property('tag', $tag)
            ->property('list', $list)
            ->allOf($base)
            ->additionalProperties($extra)
            ->discriminator(new Discriminator('kind', ['cat' => 'Cat']))
            ->build();

        self::assertSame(
            [
                ['#/components/schemas/Tag', '/properties/tag'],
                ['#/components/schemas/Item', '/properties/list/items'],
                ['#/components/schemas/Extra', '/additionalProperties'],
                ['base.yaml', '/allOf/0'],
                ['#/components/schemas/Cat', '/discriminator/mapping/cat'],
            ],
            array_map(static fn (ReferenceUse $use): array => [$use->ref(), $use->location()->pointer()], $schema->references()),
        );
    }

    public function testASchemaWithoutReferencesListsNone(): void
    {
        self::assertSame([], $this->builder()->types($this->type(SchemaType::STRING))->build()->references());
    }
```

- [ ] **Step 2: Убедиться, что тесты падают**

Run: `vendor/bin/phpunit --filter 'JsonPointerTest|ReferenceTest|DiscriminatorTest|SchemaTest'`
Expected: FAIL — `Class "...\Schema\JsonPointer" not found`, `Call to undefined method ...Schema::references()`, а у bare name — неверный `refFor()`.

- [ ] **Step 3: Реализовать**

`src/Domain/Schema/JsonPointer.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Schema;

use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Json;

/**
 * RFC 6901 JSON pointers.
 *
 * @phpstan-import-type JsonValue from Json
 */
final class JsonPointer
{
    private function __construct()
    {
    }

    /**
     * @return list<string>
     */
    public static function segments(string $pointer): array
    {
        if ($pointer === '') {
            return [];
        }

        if (strncmp($pointer, '/', 1) !== 0 || preg_match('/~(?![01])/', $pointer) === 1) {
            throw new InvalidModel(sprintf('"%s" is not a valid JSON pointer.', $pointer));
        }

        // "~1" must be decoded before "~0", otherwise "~01" would turn into "/".
        return array_map(
            static fn (string $segment): string => str_replace(['~1', '~0'], ['/', '~'], $segment),
            explode('/', (string) substr($pointer, 1)),
        );
    }

    public static function fromSegments(string ...$segments): string
    {
        $pointer = '';
        foreach ($segments as $segment) {
            $pointer .= '/' . str_replace(['~', '/'], ['~0', '~1'], $segment);
        }

        return $pointer;
    }

    /**
     * @param array<array-key, mixed> $document
     */
    public static function has(array $document, string $pointer): bool
    {
        return self::lookup($document, $pointer) !== null;
    }

    /**
     * @param array<array-key, mixed> $document
     *
     * @return JsonValue
     */
    public static function get(array $document, string $pointer)
    {
        $found = self::lookup($document, $pointer);
        if ($found === null) {
            throw new InvalidModel(sprintf('JSON pointer "%s" does not resolve.', $pointer));
        }

        return $found[0];
    }

    /**
     * @param array<array-key, mixed> $document
     *
     * @return array{JsonValue}|null wrapped, so that a found null differs from "not found"
     */
    private static function lookup(array $document, string $pointer): ?array
    {
        $current = $document;
        foreach (self::segments($pointer) as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return null;
            }

            $current = $current[$segment];
        }

        return [Json::value($current)];
    }
}
```

`src/Domain/Schema/Reference.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Schema;

use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Path;

final class Reference
{
    private function __construct()
    {
    }

    /**
     * Resolves a `$ref` against the location it appears in; null for remote references.
     */
    public static function target(string $ref, SchemaLocation $from): ?SchemaLocation
    {
        if ($ref === '') {
            throw new InvalidModel('Empty $ref.');
        }

        if (preg_match('#^[A-Za-z][A-Za-z0-9+.\-]*://#', $ref) === 1) {
            return null;
        }

        $hash = strpos($ref, '#');
        $file = $hash === false ? $ref : (string) substr($ref, 0, $hash);
        $fragment = $hash === false ? '' : rawurldecode((string) substr($ref, $hash + 1));
        if ($fragment !== '' && strncmp($fragment, '/', 1) !== 0) {
            throw new InvalidModel(sprintf('$ref "%s": only JSON pointer fragments are supported, not anchors.', $ref));
        }

        JsonPointer::segments($fragment);
        $path = $file === '' ? $from->file() : Path::resolve(Path::directory($from->file()), rawurldecode($file));

        return new SchemaLocation($path, $fragment);
    }
}
```

`src/Domain/Schema/ReferenceUse.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Schema;

use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;

/**
 * A `$ref` together with the place it was written, for resolution and for diagnostics.
 */
final class ReferenceUse
{
    private string $ref;

    private SchemaLocation $location;

    public function __construct(string $ref, SchemaLocation $location)
    {
        if ($ref === '') {
            throw new InvalidModel('A reference needs a target.');
        }

        $this->ref = $ref;
        $this->location = $location;
    }

    public function ref(): string
    {
        return $this->ref;
    }

    public function location(): SchemaLocation
    {
        return $this->location;
    }
}
```

`src/Domain/Schema/SchemaLocation.php` — заменить тело `child()`:

```php
    public function child(string ...$segments): self
    {
        return new self($this->file, $this->pointer . JsonPointer::fromSegments(...$segments));
    }
```

`src/Domain/Schema/Discriminator.php` — в конструкторе заменить присваивание `$this->mapping = $mapping;` на нормализацию:

```php
        $this->propertyName = $propertyName;
        $this->mapping = [];
        foreach ($mapping as $value => $ref) {
            // OpenAPI: a mapping value without "/" or "#" names a component schema.
            $this->mapping[$value] = strpos($ref, '#') === false && strpos($ref, '/') === false
                ? '#/components/schemas/' . $ref
                : $ref;
        }
```

`src/Domain/Schema/Schema.php` — добавить публичный метод после `extensions()`, приватный помощник в конец класса и `use` не нужен (тот же namespace):

```php
    /**
     * Every `$ref` in this schema and its subschemas, plus discriminator mapping targets.
     *
     * @return list<ReferenceUse>
     */
    public function references(): array
    {
        $uses = $this->ref === null ? [] : [new ReferenceUse($this->ref, $this->location)];
        foreach ($this->subschemas() as $subschema) {
            foreach ($subschema->references() as $use) {
                $uses[] = $use;
            }
        }

        if ($this->discriminator instanceof Discriminator) {
            foreach ($this->discriminator->values() as $value) {
                $ref = $this->discriminator->refFor($value);
                if ($ref !== null) {
                    $uses[] = new ReferenceUse($ref, $this->location->child('discriminator', 'mapping', $value));
                }
            }
        }

        return $uses;
    }
```

```php
    /**
     * @return list<Schema>
     */
    private function subschemas(): array
    {
        $subschemas = array_values($this->properties);
        if ($this->items instanceof self) {
            $subschemas[] = $this->items;
        }

        if ($this->additionalProperties instanceof self) {
            $subschemas[] = $this->additionalProperties;
        }

        return array_merge($subschemas, $this->allOf, $this->oneOf, $this->anyOf);
    }
```

- [ ] **Step 4: Прогнать тесты**

Run: `vendor/bin/phpunit`
Expected: PASS. Существующие тесты `SchemaLocationTest` проверяют, что после рефакторинга `child()` экранирование не изменилось.

- [ ] **Step 5: Гейт и коммит**

Run: `make fix && make verify`
Expected: зелёное.

```bash
git add src/Domain/Schema tests/Unit/Domain/Schema
git commit -m "feat(schema): JSON pointers, \$ref targets and schema reference listing

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: `SchemaParser` — сырой узел → `Schema`

**Files:**
- Create: `src/Domain/Builder/SchemaParser.php`
- Test: `tests/Unit/Domain/Builder/SchemaParserTest.php`

**Interfaces:**
- Consumes: `Json` (Task 2), `Diagnostics` (Task 1), `SchemaBuilder`, `Schema::STRUCTURAL_KEYWORDS`, `SchemaType::tryFrom`, `Extensions::isExtensionKey`, `Discriminator`.
- Produces: `SchemaParser::parse(JsonValue $node, SchemaLocation $location, Diagnostics $diagnostics): Schema`.
  - Метод всегда возвращает `Schema`.
  - Каждая проблема — `error` или `warning` с местом; неверный keyword пропускается.
  - `true` превращается в пустую схему; `false` и не-объекты дают ошибку.
  - Требование этапа 2 из known-issues: `additionalProperties`, который не bool и не объект, — ошибка.

- [ ] **Step 1: Написать падающий тест**

`tests/Unit/Domain/Builder/SchemaParserTest.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Domain\Builder;

use MSSTC4PHP\DtoGenerator\Domain\Builder\SchemaParser;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostic;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Schema;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaType;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Json;
use PHPUnit\Framework\TestCase;

/**
 * @phpstan-import-type JsonValue from Json
 */
final class SchemaParserTest extends TestCase
{
    public function testParsesAFullSchema(): void
    {
        $diagnostics = new Diagnostics();
        $schema = (new SchemaParser())->parse([
            'type' => ['object', 'null'],
            'description' => 'A user',
            'deprecated' => true,
            'required' => ['id'],
            'properties' => [
                'id' => ['type' => 'string', 'format' => 'uuid', 'minLength' => 1],
                'tags' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Tag']],
                'meta' => ['additionalProperties' => ['type' => 'integer']],
                '200' => ['type' => 'boolean'],
            ],
            'default' => null,
            'x-php-class-name' => 'Account',
        ], $this->root(), $diagnostics);

        self::assertSame([], $diagnostics->all());
        self::assertTrue($schema->isNullable());
        self::assertSame('A user', $schema->description());
        self::assertTrue($schema->isDeprecated());
        self::assertSame(['id', 'tags', 'meta', '200'], $schema->propertyNames());
        self::assertTrue($schema->isRequired('id'));
        self::assertNotNull($schema->default());
        self::assertSame('Account', $schema->extensions()->get('x-php-class-name'));

        $id = $schema->property('id');
        self::assertNotNull($id);
        self::assertSame('uuid', $id->format());
        self::assertSame(1, $id->keyword('minLength'));
        self::assertSame('a.yaml#/components/schemas/User/properties/id', $id->location()->toString());

        $tags = $schema->property('tags');
        self::assertNotNull($tags);
        $items = $tags->items();
        self::assertNotNull($items);
        self::assertSame('#/components/schemas/Tag', $items->ref());
        self::assertSame('/components/schemas/User/properties/tags/items', $items->location()->pointer());

        $meta = $schema->property('meta');
        self::assertNotNull($meta);
        self::assertInstanceOf(Schema::class, $meta->additionalProperties());
    }

    public function testParsesCompositionAndDiscriminator(): void
    {
        $diagnostics = new Diagnostics();
        $schema = (new SchemaParser())->parse([
            'allOf' => [['$ref' => '#/components/schemas/Base']],
            'oneOf' => [['$ref' => '#/components/schemas/Cat'], ['$ref' => '#/components/schemas/Dog']],
            'anyOf' => [['type' => 'string']],
            'discriminator' => ['propertyName' => 'kind', 'mapping' => ['cat' => 'Cat']],
            'enum' => ['a', 'b'],
            'additionalProperties' => false,
        ], $this->root(), $diagnostics);

        self::assertSame([], $diagnostics->all());
        self::assertCount(1, $schema->allOf());
        self::assertCount(2, $schema->oneOf());
        self::assertSame('/components/schemas/User/oneOf/1', $schema->oneOf()[1]->location()->pointer());
        self::assertCount(1, $schema->anyOf());
        self::assertNotNull($schema->discriminator());
        self::assertSame('#/components/schemas/Cat', $schema->discriminator()->refFor('cat'));
        self::assertSame(['a', 'b'], $schema->enum());
        self::assertFalse($schema->additionalProperties());
    }

    public function testTrueIsTheEmptySchema(): void
    {
        $diagnostics = new Diagnostics();
        $schema = (new SchemaParser())->parse(true, $this->root(), $diagnostics);

        self::assertSame([], $diagnostics->all());
        self::assertSame([], $schema->types());
    }

    public function testAnEmptyObjectIsTheEmptySchema(): void
    {
        $diagnostics = new Diagnostics();
        (new SchemaParser())->parse([], $this->root(), $diagnostics);

        self::assertSame([], $diagnostics->all());
    }

    public function testAcceptsNumericRequiredNamesFromUnquotedYaml(): void
    {
        $schema = (new SchemaParser())->parse(['required' => [200]], $this->root(), new Diagnostics());

        self::assertSame(['200'], $schema->required());
    }

    public function testWarnsAboutRepeatsAndKeepsOneCopy(): void
    {
        $diagnostics = new Diagnostics();
        $schema = (new SchemaParser())->parse(['type' => ['string', 'string'], 'required' => ['a', 'a']], $this->root(), $diagnostics);

        self::assertFalse($diagnostics->hasErrors());
        self::assertSame(
            ['Type "string" is listed twice.', '"a" is listed twice.'],
            array_map(static fn (Diagnostic $diagnostic): string => $diagnostic->message(), $diagnostics->all()),
        );
        self::assertSame([SchemaType::from(SchemaType::STRING)], $schema->types());
        self::assertSame(['a'], $schema->required());
    }

    public function testReportsEveryProblemInOnePass(): void
    {
        $diagnostics = new Diagnostics();
        (new SchemaParser())->parse(['type' => 'text', 'format' => 1, 'enum' => []], $this->root(), $diagnostics);

        self::assertCount(3, $diagnostics->errors());
    }

    /**
     * @dataProvider invalidNodes
     *
     * @param JsonValue $node
     */
    public function testReportsInvalidKeywordsWithTheirLocation($node, string $message, string $pointer): void
    {
        $diagnostics = new Diagnostics();
        (new SchemaParser())->parse($node, $this->root(), $diagnostics);

        self::assertCount(1, $diagnostics->errors());
        $error = $diagnostics->errors()[0];
        self::assertStringContainsString($message, $error->message());
        self::assertNotNull($error->location());
        self::assertSame('/components/schemas/User' . $pointer, $error->location()->pointer());
    }

    /**
     * @return array<string, array{JsonValue, string, string}>
     */
    public static function invalidNodes(): array
    {
        return [
            'list instead of object' => [[1, 2], 'A schema must be an object', ''],
            'scalar' => ['string', 'A schema must be an object', ''],
            'false schema' => [false, 'The "false" schema is not supported', ''],
            'unknown type' => [['type' => 'text'], 'Unknown type "text"', '/type'],
            'unknown type in list' => [['type' => ['string', 'text']], 'Unknown type "text"', '/type/1'],
            'non-string type in list' => [['type' => ['string', 1]], 'Unknown type integer', '/type/1'],
            'empty type list' => [['type' => []], 'non-empty list of type names', '/type'],
            'empty ref' => [['$ref' => ''], '"$ref" must be a non-empty string', '/$ref'],
            'numeric format' => [['format' => 5], '"format" must be a non-empty string', '/format'],
            'description object' => [['description' => ['a' => 1]], '"description" must be a string', '/description'],
            'deprecated string' => [['deprecated' => 'yes'], '"deprecated" must be a boolean', '/deprecated'],
            'empty enum' => [['enum' => []], '"enum" must be a non-empty list', '/enum'],
            'enum map' => [['enum' => ['a' => 1]], '"enum" must be a non-empty list', '/enum'],
            'properties list' => [['properties' => [['type' => 'string']]], '"properties" must be an object', '/properties'],
            'required map' => [['required' => ['a' => 'id']], '"required" must be a list', '/required'],
            'required non-string' => [['required' => [true]], 'A required property name must be a string', '/required/0'],
            'tuple items' => [['items' => [['type' => 'string']]], 'tuple arrays', '/items'],
            'additionalProperties string' => [['additionalProperties' => 'yes'], 'must be a boolean or a schema', '/additionalProperties'],
            'additionalProperties list' => [['additionalProperties' => [1]], 'must be a boolean or a schema', '/additionalProperties'],
            'empty allOf' => [['allOf' => []], '"allOf" must be a non-empty list of schemas', '/allOf'],
            'oneOf map' => [['oneOf' => ['a' => []]], '"oneOf" must be a non-empty list of schemas', '/oneOf'],
            'anyOf scalar' => [['anyOf' => 'x'], '"anyOf" must be a non-empty list of schemas', '/anyOf'],
            'discriminator without property' => [['discriminator' => ['mapping' => []]], 'needs a non-empty "propertyName"', '/discriminator'],
            'discriminator mapping scalar' => [['discriminator' => ['propertyName' => 'kind', 'mapping' => 'x']], '"mapping" must be an object', '/discriminator/mapping'],
            'discriminator bad target' => [['discriminator' => ['propertyName' => 'kind', 'mapping' => ['cat' => 1]]], 'A mapping target must be a non-empty string', '/discriminator/mapping/cat'],
            'nested error keeps its location' => [['properties' => ['id' => ['type' => 'uuid']]], 'Unknown type "uuid"', '/properties/id/type'],
        ];
    }

    private function root(): SchemaLocation
    {
        return new SchemaLocation('a.yaml', '/components/schemas/User');
    }
}
```

- [ ] **Step 2: Убедиться, что тест падает**

Run: `vendor/bin/phpunit --filter SchemaParserTest`
Expected: FAIL — `Class "...\Builder\SchemaParser" not found`.

- [ ] **Step 3: Реализовать**

`src/Domain/Builder/SchemaParser.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Builder;

use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Discriminator;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Extensions;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Schema;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaBuilder;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaType;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Json;

/**
 * Turns a decoded JSON Schema node into a {@see Schema}. A malformed keyword becomes a located diagnostic
 * and is dropped, so one pass reports every problem.
 *
 * @phpstan-import-type JsonValue from Json
 */
final class SchemaParser
{
    /**
     * @param JsonValue $node
     */
    public function parse($node, SchemaLocation $location, Diagnostics $diagnostics): Schema
    {
        $builder = new SchemaBuilder($location);
        if ($node === true) {
            return $builder->build();
        }

        if (!is_array($node) || ($node !== [] && Json::isList($node))) {
            $diagnostics->error($node === false ? 'The "false" schema is not supported.' : 'A schema must be an object.', $location);

            return $builder->build();
        }

        $extensions = [];
        foreach ($node as $key => $rawValue) {
            $keyword = (string) $key;
            $value = Json::value($rawValue);
            if (Extensions::isExtensionKey($keyword)) {
                $extensions[$keyword] = $value;

                continue;
            }

            if (in_array($keyword, Schema::STRUCTURAL_KEYWORDS, true)) {
                $this->applyStructural($builder, $keyword, $value, $location->child($keyword), $diagnostics);

                continue;
            }

            $builder->keyword($keyword, $value);
        }

        return $builder->extensions(new Extensions($extensions))->build();
    }

    /**
     * @param JsonValue $value
     */
    private function applyStructural(SchemaBuilder $builder, string $keyword, $value, SchemaLocation $at, Diagnostics $diagnostics): void
    {
        switch ($keyword) {
            case 'type':
                $builder->types(...$this->types($value, $at, $diagnostics));

                break;
            case '$ref':
                if (is_string($value) && $value !== '') {
                    $builder->ref($value);
                } else {
                    $diagnostics->error('"$ref" must be a non-empty string.', $at);
                }

                break;
            case 'format':
                if (is_string($value) && $value !== '') {
                    $builder->format($value);
                } else {
                    $diagnostics->error('"format" must be a non-empty string.', $at);
                }

                break;
            case 'description':
                if (is_string($value)) {
                    $builder->description($value);
                } else {
                    $diagnostics->error('"description" must be a string.', $at);
                }

                break;
            case 'deprecated':
                if (is_bool($value)) {
                    $builder->deprecated($value);
                } else {
                    $diagnostics->error('"deprecated" must be a boolean.', $at);
                }

                break;
            case 'default':
                $builder->default($value);

                break;
            case 'enum':
                if (is_array($value) && $value !== [] && Json::isList($value)) {
                    $builder->enum(array_map(static fn ($item) => Json::value($item), $value));
                } else {
                    $diagnostics->error('"enum" must be a non-empty list.', $at);
                }

                break;
            case 'properties':
                $this->properties($builder, $value, $at, $diagnostics);

                break;
            case 'required':
                $builder->required(...$this->required($value, $at, $diagnostics));

                break;
            case 'items':
                if (is_array($value) && $value !== [] && Json::isList($value)) {
                    $diagnostics->error('"items" must be a single schema; tuple arrays ("prefixItems") are not supported.', $at);
                } else {
                    $builder->items($this->parse($value, $at, $diagnostics));
                }

                break;
            case 'additionalProperties':
                if (is_bool($value)) {
                    $builder->additionalProperties($value);
                } elseif (is_array($value) && ($value === [] || !Json::isList($value))) {
                    $builder->additionalProperties($this->parse($value, $at, $diagnostics));
                } else {
                    $diagnostics->error('"additionalProperties" must be a boolean or a schema.', $at);
                }

                break;
            case 'allOf':
                $builder->allOf(...$this->schemaList($value, $keyword, $at, $diagnostics));

                break;
            case 'oneOf':
                $builder->oneOf(...$this->schemaList($value, $keyword, $at, $diagnostics));

                break;
            case 'anyOf':
                $builder->anyOf(...$this->schemaList($value, $keyword, $at, $diagnostics));

                break;
            case 'discriminator':
                $discriminator = $this->discriminator($value, $at, $diagnostics);
                if ($discriminator instanceof Discriminator) {
                    $builder->discriminator($discriminator);
                }

                break;
        }
    }

    /**
     * @param JsonValue $value
     *
     * @return list<SchemaType>
     */
    private function types($value, SchemaLocation $at, Diagnostics $diagnostics): array
    {
        $names = is_string($value) ? [$value] : (is_array($value) && Json::isList($value) ? $value : []);
        if ($names === []) {
            $diagnostics->error('"type" must be a type name or a non-empty list of type names.', $at);

            return [];
        }

        $types = [];
        foreach ($names as $index => $name) {
            $type = is_string($name) ? SchemaType::tryFrom($name) : null;
            if (!$type instanceof SchemaType) {
                $diagnostics->error(
                    sprintf('Unknown type %s.', is_string($name) ? '"' . $name . '"' : gettype($name)),
                    is_string($value) ? $at : $at->child((string) $index),
                );

                continue;
            }

            if (in_array($type, $types, true)) {
                $diagnostics->warning(sprintf('Type "%s" is listed twice.', $type->value()), $at);

                continue;
            }

            $types[] = $type;
        }

        return $types;
    }

    /**
     * @param JsonValue $value
     */
    private function properties(SchemaBuilder $builder, $value, SchemaLocation $at, Diagnostics $diagnostics): void
    {
        if (!is_array($value) || ($value !== [] && Json::isList($value))) {
            $diagnostics->error('"properties" must be an object.', $at);

            return;
        }

        foreach ($value as $name => $node) {
            $builder->property((string) $name, $this->parse(Json::value($node), $at->child((string) $name), $diagnostics));
        }
    }

    /**
     * @param JsonValue $value
     *
     * @return list<string>
     */
    private function required($value, SchemaLocation $at, Diagnostics $diagnostics): array
    {
        if (!is_array($value) || !Json::isList($value)) {
            $diagnostics->error('"required" must be a list of property names.', $at);

            return [];
        }

        $names = [];
        foreach ($value as $index => $name) {
            // Unquoted numeric names arrive from YAML as integers.
            if (is_int($name)) {
                $name = (string) $name;
            }

            if (!is_string($name)) {
                $diagnostics->error('A required property name must be a string.', $at->child((string) $index));

                continue;
            }

            if (in_array($name, $names, true)) {
                $diagnostics->warning(sprintf('"%s" is listed twice.', $name), $at->child((string) $index));

                continue;
            }

            $names[] = $name;
        }

        return $names;
    }

    /**
     * @param JsonValue $value
     *
     * @return list<Schema>
     */
    private function schemaList($value, string $keyword, SchemaLocation $at, Diagnostics $diagnostics): array
    {
        if (!is_array($value) || $value === [] || !Json::isList($value)) {
            $diagnostics->error(sprintf('"%s" must be a non-empty list of schemas.', $keyword), $at);

            return [];
        }

        $schemas = [];
        foreach ($value as $index => $node) {
            $schemas[] = $this->parse(Json::value($node), $at->child((string) $index), $diagnostics);
        }

        return $schemas;
    }

    /**
     * @param JsonValue $value
     */
    private function discriminator($value, SchemaLocation $at, Diagnostics $diagnostics): ?Discriminator
    {
        $propertyName = is_array($value) ? ($value['propertyName'] ?? null) : null;
        if (!is_array($value) || !is_string($propertyName) || $propertyName === '') {
            $diagnostics->error('"discriminator" needs a non-empty "propertyName".', $at);

            return null;
        }

        $rawMapping = $value['mapping'] ?? [];
        if (!is_array($rawMapping)) {
            $diagnostics->error('"mapping" must be an object.', $at->child('mapping'));

            return null;
        }

        $mapping = [];
        foreach ($rawMapping as $discriminatorValue => $ref) {
            if (!is_string($ref) || $ref === '') {
                $diagnostics->error('A mapping target must be a non-empty string.', $at->child('mapping', (string) $discriminatorValue));

                return null;
            }

            $mapping[$discriminatorValue] = $ref;
        }

        return new Discriminator($propertyName, $mapping);
    }
}
```

- [ ] **Step 4: Прогнать тест**

Run: `vendor/bin/phpunit --filter SchemaParserTest`
Expected: PASS.

- [ ] **Step 5: Гейт и коммит**

Run: `make fix && make verify`
Expected: зелёное. Если PHPStan не сужает `$value` после `is_array` в `discriminator()`, извлеките `$propertyName` после отдельного `if (!is_array($value))`, без ignore.

```bash
git add src/Domain/Builder tests/Unit/Domain/Builder
git commit -m "feat(builder): SchemaParser with located diagnostics

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: Загрузка документов YAML/JSON

**Files:**
- Modify: `composer.json` (`symfony/yaml`), `phpunit.xml.dist` (suite `integration`), `infection.json5` (оба suite)
- Create: `src/Application/ValueObject/Document.php`, `src/Application/Port/DocumentLoader.php`, `src/Application/Port/DocumentLoadFailed.php`, `src/Infrastructure/Document/FileDocumentLoader.php`
- Create: `tests/Fixtures/Documents/valid.yaml`, `valid.yml`, `valid.json`, `broken.yaml`, `broken.json`, `scalar.json`, `list.yaml`, `notes.txt`, `gotchas.yaml`
- Create: `tests/Support/InMemoryDocumentLoader.php`
- Test: `tests/Integration/Infrastructure/FileDocumentLoaderTest.php`

**Interfaces:**
- Consumes: `Path`, `Json`.
- Produces:
  - `Document::__construct(string $path, array<array-key, mixed> $root)`, методы `path()` и `root()`.
  - Интерфейс `DocumentLoader::load(string $path): Document`, бросает `DocumentLoadFailed`.
  - `DocumentLoadFailed extends \RuntimeException` с конструкторами `notFound`, `unreadable`, `unsupportedFormat`, `malformed`, `notAnObject`.
  - `FileDocumentLoader implements DocumentLoader`. `Document::path()` равен `Path::normalize($path)` запрошенного пути; кеш разобранных данных — по `realpath`.
  - Тестовый двойник `Tests\Support\InMemoryDocumentLoader` (`array<string, array>` по нормализованному пути).

- [ ] **Step 1: Зависимость и конфигурация тестов**

Run: `composer require "symfony/yaml:^5.4 || ^6.4 || ^7.0"`
Expected: ставится `symfony/yaml v5.4.x`, потому что `config.platform.php = 7.4.33`.

В `phpunit.xml.dist` после suite `unit` добавить:

```xml
        <testsuite name="integration">
            <directory>tests/Integration</directory>
        </testsuite>
```

В `infection.json5` заменить `"testFrameworkOptions": "--testsuite=unit"` на:

```json5
    "testFrameworkOptions": "--testsuite=unit,integration"
```

- [ ] **Step 2: Фикстуры**

`tests/Fixtures/Documents/valid.yaml`:

```yaml
openapi: 3.1.0
components:
  schemas:
    User:
      type: object
```

`tests/Fixtures/Documents/valid.yml` — тот же текст, что в `valid.yaml`.

`tests/Fixtures/Documents/valid.json`:

```json
{"openapi": "3.1.0", "components": {"schemas": {"User": {"type": "object"}}}}
```

`tests/Fixtures/Documents/broken.yaml`:

```yaml
openapi: [3.1.0
```

`tests/Fixtures/Documents/broken.json`:

```json
{"openapi": "3.1.0",
```

`tests/Fixtures/Documents/scalar.json`:

```json
"just a string"
```

`tests/Fixtures/Documents/list.yaml`:

```yaml
- a
- b
```

`tests/Fixtures/Documents/notes.txt`:

```text
not a document
```

`tests/Fixtures/Documents/gotchas.yaml`:

```yaml
created: 2020-01-01
quoted: '2020-01-01'
object: !php/object 'O:8:"stdClass":0:{}'
```

- [ ] **Step 3: Написать падающий тест**

`tests/Integration/Infrastructure/FileDocumentLoaderTest.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Integration\Infrastructure;

use MSSTC4PHP\DtoGenerator\Application\Port\DocumentLoadFailed;
use MSSTC4PHP\DtoGenerator\Infrastructure\Document\FileDocumentLoader;
use PHPUnit\Framework\TestCase;

final class FileDocumentLoaderTest extends TestCase
{
    private const DIR = __DIR__ . '/../../Fixtures/Documents';

    private const EXPECTED = ['openapi' => '3.1.0', 'components' => ['schemas' => ['User' => ['type' => 'object']]]];

    /**
     * @dataProvider validFiles
     */
    public function testDecodesYamlAndJson(string $file): void
    {
        $document = (new FileDocumentLoader())->load(self::DIR . '/' . $file);

        self::assertSame(self::EXPECTED, $document->root());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function validFiles(): array
    {
        return [
            'yaml' => ['valid.yaml'],
            'yml' => ['valid.yml'],
            'json' => ['valid.json'],
        ];
    }

    public function testReportsTheNormalizedRequestedPath(): void
    {
        $document = (new FileDocumentLoader())->load(self::DIR . '/../Documents/./valid.yaml');

        self::assertStringEndsWith('tests/Fixtures/Documents/valid.yaml', $document->path());
        self::assertStringNotContainsString('..', $document->path());
    }

    public function testReusesTheDecodedContentForAnotherSpelling(): void
    {
        $loader = new FileDocumentLoader();
        $first = $loader->load(self::DIR . '/valid.yaml');
        $second = $loader->load(self::DIR . '/../Documents/valid.yaml');

        self::assertSame($first->root(), $second->root());
        self::assertSame($first->path(), $second->path());
    }

    /**
     * @dataProvider failures
     */
    public function testReportsUnusableFiles(string $file, string $message): void
    {
        $this->expectException(DocumentLoadFailed::class);
        $this->expectExceptionMessage($message);

        (new FileDocumentLoader())->load(self::DIR . '/' . $file);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function failures(): array
    {
        return [
            'missing' => ['missing.yaml', 'does not exist'],
            'broken yaml' => ['broken.yaml', 'is not valid'],
            'broken json' => ['broken.json', 'is not valid'],
            'scalar' => ['scalar.json', 'must contain an object at the top level'],
            'list' => ['list.yaml', 'must contain an object at the top level'],
            'unsupported' => ['notes.txt', 'must be YAML (.yaml, .yml) or JSON (.json)'],
            'directory' => ['.', 'does not exist'],
        ];
    }

    public function testPinsYamlScalarGotchas(): void
    {
        $root = (new FileDocumentLoader())->load(self::DIR . '/gotchas.yaml')->root();

        // symfony/yaml turns unquoted dates into Unix timestamps; see known-issues.md.
        self::assertSame(1577836800, $root['created']);
        self::assertSame('2020-01-01', $root['quoted']);
        // Object tags are never instantiated.
        self::assertNull($root['object']);
    }
}
```

`tests/Support/InMemoryDocumentLoader.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Support;

use MSSTC4PHP\DtoGenerator\Application\Port\DocumentLoader;
use MSSTC4PHP\DtoGenerator\Application\Port\DocumentLoadFailed;
use MSSTC4PHP\DtoGenerator\Application\ValueObject\Document;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Path;

final class InMemoryDocumentLoader implements DocumentLoader
{
    /** @var array<string, array<array-key, mixed>> */
    private array $documents;

    /**
     * @param array<string, array<array-key, mixed>> $documents keyed by absolute path
     */
    public function __construct(array $documents)
    {
        $this->documents = $documents;
    }

    public function load(string $path): Document
    {
        $normalized = Path::normalize($path);
        if (!isset($this->documents[$normalized])) {
            throw DocumentLoadFailed::notFound($normalized);
        }

        return new Document($normalized, $this->documents[$normalized]);
    }
}
```

- [ ] **Step 4: Убедиться, что тест падает**

Run: `vendor/bin/phpunit --testsuite integration`
Expected: FAIL — `Class "...\Infrastructure\Document\FileDocumentLoader" not found`.

- [ ] **Step 5: Реализовать**

`src/Application/ValueObject/Document.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\ValueObject;

use InvalidArgumentException;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Path;

final class Document
{
    private string $path;

    /** @var array<array-key, mixed> */
    private array $root;

    /**
     * @param string $path absolute and normalized; it is the document's identity in locations
     * @param array<array-key, mixed> $root the decoded top-level object
     */
    public function __construct(string $path, array $root)
    {
        if (!Path::isAbsolute($path) || Path::normalize($path) !== $path) {
            throw new InvalidArgumentException(sprintf('Document path "%s" must be absolute and normalized.', $path));
        }

        $this->path = $path;
        $this->root = $root;
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * @return array<array-key, mixed>
     */
    public function root(): array
    {
        return $this->root;
    }
}
```

`src/Application/Port/DocumentLoader.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Port;

use MSSTC4PHP\DtoGenerator\Application\ValueObject\Document;

interface DocumentLoader
{
    /**
     * @throws DocumentLoadFailed
     */
    public function load(string $path): Document;
}
```

`src/Application/Port/DocumentLoadFailed.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Port;

use RuntimeException;

final class DocumentLoadFailed extends RuntimeException
{
    public static function notFound(string $path): self
    {
        return new self(sprintf('File "%s" does not exist.', $path));
    }

    public static function unreadable(string $path): self
    {
        return new self(sprintf('File "%s" cannot be read.', $path));
    }

    public static function unsupportedFormat(string $path): self
    {
        return new self(sprintf('File "%s" must be YAML (.yaml, .yml) or JSON (.json).', $path));
    }

    public static function malformed(string $path, string $reason): self
    {
        return new self(sprintf('File "%s" is not valid: %s', $path, $reason));
    }

    public static function notAnObject(string $path): self
    {
        return new self(sprintf('File "%s" must contain an object at the top level.', $path));
    }
}
```

`src/Infrastructure/Document/FileDocumentLoader.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Infrastructure\Document;

use JsonException;
use MSSTC4PHP\DtoGenerator\Application\Port\DocumentLoader;
use MSSTC4PHP\DtoGenerator\Application\Port\DocumentLoadFailed;
use MSSTC4PHP\DtoGenerator\Application\ValueObject\Document;
use MSSTC4PHP\DtoGenerator\Domain\Model\Identifier;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Json;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Path;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final class FileDocumentLoader implements DocumentLoader
{
    /** @var array<string, array<array-key, mixed>> decoded content by real path */
    private array $decoded = [];

    public function load(string $path): Document
    {
        $normalized = Path::normalize($path);
        $real = realpath($normalized);
        if ($real === false || !is_file($real)) {
            throw DocumentLoadFailed::notFound($normalized);
        }

        if (!isset($this->decoded[$real])) {
            $this->decoded[$real] = $this->decode($normalized, $real);
        }

        // The requested spelling is the identity, so locations stay lexical and match resolved $refs.
        return new Document($normalized, $this->decoded[$real]);
    }

    /**
     * @return array<array-key, mixed>
     */
    private function decode(string $path, string $real): array
    {
        $extension = Identifier::asciiLower(pathinfo($real, PATHINFO_EXTENSION));
        if (!in_array($extension, ['json', 'yaml', 'yml'], true)) {
            throw DocumentLoadFailed::unsupportedFormat($path);
        }

        $content = is_readable($real) ? file_get_contents($real) : false;
        if ($content === false) {
            throw DocumentLoadFailed::unreadable($path);
        }

        try {
            // Without PARSE_OBJECT symfony/yaml never instantiates "!php/object" tags.
            $decoded = $extension === 'json'
                ? json_decode($content, true, 512, JSON_THROW_ON_ERROR)
                : Yaml::parse($content);
        } catch (JsonException|ParseException $exception) {
            throw DocumentLoadFailed::malformed($path, $exception->getMessage());
        }

        if (!is_array($decoded) || ($decoded !== [] && Json::isList($decoded))) {
            throw DocumentLoadFailed::notAnObject($path);
        }

        return $decoded;
    }
}
```

> Multi-catch `JsonException|ParseException` — это синтаксис PHP 7.1, он допустим.

- [ ] **Step 6: Прогнать тесты**

Run: `vendor/bin/phpunit --testsuite integration`
Expected: PASS. Если `symfony/yaml` вернул для `!php/object` не `null`, а строку: зафиксируйте фактическое значение и проверьте, что это не объект (`assertIsNotObject`). Ledger-ruling обязателен.

- [ ] **Step 7: Гейт и коммит**

Run: `make fix && make verify`
Expected: зелёное, интеграционный suite проходит и на 7.4.

```bash
git add composer.json phpunit.xml.dist infection.json5 src/Application src/Infrastructure tests/Fixtures/Documents tests/Support tests/Integration
git commit -m "feat(infrastructure): YAML/JSON document loader behind the DocumentLoader port

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: Модель конфига и его разбор

**Files:**
- Create: `src/Domain/Target/AllOfStrategy.php`
- Create: `src/Application/Config/RawSection.php`, `ConfigFactory.php`, `GeneratorConfig.php`, `TargetSettings.php`, `DtoSettings.php`, `ExtensionSettings.php`, `SourceConfig.php`
- Create: `tests/Support/ConfigMother.php`
- Modify: `tests/Unit/Domain/Shared/EnumValuesTest.php`
- Test: `tests/Unit/Application/Config/ConfigFactoryTest.php`

**Interfaces:**
- Consumes: `Diagnostics`, `Path`, `Json`, `Identifier::normalizeQualifiedName`, `ClassName`, `Extensions::isExtensionKey`, `PhpVersion`, режимы из `Domain/Target`.
- Produces:
  - `AllOfStrategy` (`EXTENDS`, `MERGE`).
  - `TargetSettings(?PhpVersion $php, ?MetadataMode $metadata, bool $strict)`; `null` означает `auto`.
  - `DtoSettings(Mutability, AccessorStyle, DateTimeClass, AllOfStrategy)`.
  - `ExtensionSettings(list<ClassName> $extensions, bool $discover, array<string, JsonValue> $config, array<string, array<array-key, mixed>> $aliases, ?bool $verifyClasses)`.
  - `SourceConfig(string $spec, string $namespace, string $outputDir, list<string> $include, list<string> $exclude)` — пути абсолютные.
  - `GeneratorConfig(string $path, TargetSettings, DtoSettings, array<string, ClassName> $formats, ExtensionSettings, non-empty-list<SourceConfig>)`. Методы: `path()`, `baseDir()`, `location(): SchemaLocation`, `target()`, `dto()`, `formats()`, `extensions()`, `sources()`.
  - `ConfigFactory::create(array $raw, string $path, Diagnostics): ?GeneratorConfig` — `null`, если добавилась хотя бы одна ошибка.
  - `RawSection` — служебный класс `ConfigFactory`. Методы: `location`, `rejectUnknownKeys`, `has`, `raw`, `requiredString`, `choice`, `bool`, `stringList`, `section`, `sectionList`, `keys`, `error`, `report`.
  - Тестовый `Tests\Support\ConfigMother::config(SourceConfig ...$sources): GeneratorConfig` и `::source(string $spec, array $include = ['*'], array $exclude = [], string $namespace = 'App\Dto'): SourceConfig`.

- [ ] **Step 1: Написать падающие тесты**

В `tests/Unit/Domain/Shared/EnumValuesTest.php` добавить строку и `use` для `AllOfStrategy`:

```php
            'allOf strategy' => [AllOfStrategy::class, ['extends', 'merge']],
```

`tests/Unit/Application/Config/ConfigFactoryTest.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Application\Config;

use MSSTC4PHP\DtoGenerator\Application\Config\ConfigFactory;
use MSSTC4PHP\DtoGenerator\Application\Config\GeneratorConfig;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostic;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;
use PHPUnit\Framework\TestCase;

final class ConfigFactoryTest extends TestCase
{
    private const PATH = '/project/config/dto-generator.yaml';

    private const MINIMAL = [
        'version' => 1,
        'sources' => [['spec' => '../api/openapi.yaml', 'namespace' => 'App\Dto', 'outputDir' => '../src/Dto']],
    ];

    public function testAppliesDefaultsToAMinimalConfig(): void
    {
        $config = $this->valid(self::MINIMAL);

        self::assertSame(self::PATH, $config->path());
        self::assertSame('/project/config', $config->baseDir());
        self::assertNull($config->target()->php());
        self::assertNull($config->target()->metadata());
        self::assertTrue($config->target()->isStrict());
        self::assertSame('immutable', $config->dto()->mutability()->value());
        self::assertSame('auto', $config->dto()->accessors()->value());
        self::assertSame('DateTimeImmutable', $config->dto()->dateTimeClass()->value());
        self::assertSame('extends', $config->dto()->allOfStrategy()->value());
        self::assertSame([], $config->formats());
        self::assertSame([], $config->extensions()->classes());
        self::assertTrue($config->extensions()->discover());
        self::assertNull($config->extensions()->verifyClasses());

        $source = $config->sources()[0];
        self::assertSame('/project/api/openapi.yaml', $source->spec());
        self::assertSame('App\Dto', $source->namespace());
        self::assertSame('/project/src/Dto', $source->outputDir());
        self::assertSame(['*'], $source->include());
        self::assertSame([], $source->exclude());
    }

    public function testReadsAFullConfig(): void
    {
        $config = $this->valid([
            'version' => 1,
            'target' => ['php' => '8.2', 'metadata' => 'annotations', 'strict' => false],
            'dto' => ['mutability' => 'mutable', 'accessors' => 'getters', 'dateTimeClass' => 'DateTime', 'allOfStrategy' => 'merge'],
            'formats' => ['uuid' => ['type' => '\Symfony\Component\Uid\Uuid']],
            'attributeAliases' => ['x-audit' => ['class' => 'App\Attr\Audited']],
            'verifyClasses' => false,
            'discoverExtensions' => false,
            'extensions' => ['MSSTC4PHP\DtoGeneratorBridgeSymfony\SymfonyExtension'],
            'extensionConfig' => ['symfony' => ['version' => 'auto']],
            'sources' => [[
                'spec' => '/abs/openapi.yaml',
                'namespace' => '\App\Dto\Public',
                'outputDir' => 'src/Dto',
                'include' => ['User*'],
                'exclude' => ['UserInternal'],
            ]],
        ]);

        self::assertNotNull($config->target()->php());
        self::assertSame('8.2', $config->target()->php()->toString());
        self::assertNotNull($config->target()->metadata());
        self::assertSame('annotations', $config->target()->metadata()->value());
        self::assertFalse($config->target()->isStrict());
        self::assertSame('mutable', $config->dto()->mutability()->value());
        self::assertSame('merge', $config->dto()->allOfStrategy()->value());
        self::assertSame('Symfony\Component\Uid\Uuid', $config->formats()['uuid']->fqcn());
        self::assertSame(['x-audit' => ['class' => 'App\Attr\Audited']], $config->extensions()->aliases());
        self::assertFalse($config->extensions()->verifyClasses());
        self::assertFalse($config->extensions()->discover());
        self::assertSame(
            ['MSSTC4PHP\DtoGeneratorBridgeSymfony\SymfonyExtension'],
            array_map(static fn (ClassName $class): string => $class->fqcn(), $config->extensions()->classes()),
        );
        self::assertSame(['symfony' => ['version' => 'auto']], $config->extensions()->config());
        self::assertSame('/abs/openapi.yaml', $config->sources()[0]->spec());
        self::assertSame('App\Dto\Public', $config->sources()[0]->namespace());
        self::assertSame(['User*'], $config->sources()[0]->include());
        self::assertSame(['UserInternal'], $config->sources()[0]->exclude());
    }

    /**
     * @dataProvider unquotedVersions
     *
     * @param float|string $php
     */
    public function testAcceptsUnquotedYamlVersions($php, string $expected): void
    {
        $config = $this->valid(['target' => ['php' => $php]] + self::MINIMAL);

        self::assertNotNull($config->target()->php());
        self::assertSame($expected, $config->target()->php()->toString());
    }

    /**
     * @return array<string, array{float|string, string}>
     */
    public static function unquotedVersions(): array
    {
        return [
            'float 8.2' => [8.2, '8.2'],
            'float 8.0' => [8.0, '8.0'],
            'float 7.4' => [7.4, '7.4'],
        ];
    }

    /**
     * @dataProvider invalidConfigs
     *
     * @param array<array-key, mixed> $raw
     */
    public function testReportsProblemsWithTheirLocation(array $raw, string $message, string $pointer): void
    {
        $diagnostics = new Diagnostics();

        self::assertNull((new ConfigFactory())->create($raw, self::PATH, $diagnostics));
        $matching = array_values(array_filter(
            $diagnostics->errors(),
            static fn (Diagnostic $error): bool => strpos($error->message(), $message) !== false,
        ));
        self::assertCount(1, $matching, implode("\n", array_map(static fn (Diagnostic $d): string => $d->toString(), $diagnostics->all())));
        self::assertNotNull($matching[0]->location());
        self::assertSame(self::PATH . '#' . $pointer, $matching[0]->location()->toString());
    }

    /**
     * @return array<string, array{array<array-key, mixed>, string, string}>
     */
    public static function invalidConfigs(): array
    {
        $source = ['spec' => 'a.yaml', 'namespace' => 'App\Dto', 'outputDir' => 'src'];

        return [
            'missing version' => [['sources' => [$source]], '"version" is required', ''],
            'wrong version' => [['version' => 2, 'sources' => [$source]], '"version" must be 1', '/version'],
            'unknown root key' => [['version' => 1, 'sorces' => [], 'sources' => [$source]], 'Unknown key "sorces"', '/sorces'],
            'unknown target key' => [['version' => 1, 'target' => ['phpp' => '8.2'], 'sources' => [$source]], 'Unknown key "phpp"', '/target/phpp'],
            'unsupported php' => [['version' => 1, 'target' => ['php' => '9.9'], 'sources' => [$source]], 'PHP 9.9 is not supported', '/target/php'],
            'php as list' => [['version' => 1, 'target' => ['php' => ['8.2']], 'sources' => [$source]], 'must be "auto" or a version', '/target/php'],
            'bad metadata' => [['version' => 1, 'target' => ['metadata' => 'attribute'], 'sources' => [$source]], 'must be one of: auto, attributes, annotations, none', '/target/metadata'],
            'strict not bool' => [['version' => 1, 'target' => ['strict' => 'yes'], 'sources' => [$source]], '"strict" must be true or false', '/target/strict'],
            'target list' => [['version' => 1, 'target' => ['8.2'], 'sources' => [$source]], '"target" must be an object', '/target'],
            'bad mutability' => [['version' => 1, 'dto' => ['mutability' => 'frozen'], 'sources' => [$source]], 'must be one of: immutable, mutable', '/dto/mutability'],
            'format without type' => [['version' => 1, 'formats' => ['uuid' => []], 'sources' => [$source]], '"type" is required', '/formats/uuid'],
            'format bad class' => [['version' => 1, 'formats' => ['uuid' => ['type' => 'Not A Class']], 'sources' => [$source]], 'is not a valid class name', '/formats/uuid/type'],
            'reserved alias prefix' => [['version' => 1, 'attributeAliases' => ['x-php-audit' => []], 'sources' => [$source]], 'outside the reserved', '/attributeAliases/x-php-audit'],
            'alias not object' => [['version' => 1, 'attributeAliases' => ['x-audit' => 'App\A'], 'sources' => [$source]], 'An alias must be an object', '/attributeAliases/x-audit'],
            'bad verifyClasses' => [['version' => 1, 'verifyClasses' => 'yes', 'sources' => [$source]], 'must be "auto", true or false', '/verifyClasses'],
            'bad extension class' => [['version' => 1, 'extensions' => ['Not A Class'], 'sources' => [$source]], 'is not a valid class name', '/extensions/0'],
            'missing sources' => [['version' => 1], '"sources" is required', ''],
            'empty sources' => [['version' => 1, 'sources' => []], '"sources" must be a non-empty list', '/sources'],
            'source not object' => [['version' => 1, 'sources' => ['a.yaml']], 'Expected an object', '/sources/0'],
            'source missing spec' => [['version' => 1, 'sources' => [['namespace' => 'App', 'outputDir' => 'src']]], '"spec" is required', '/sources/0'],
            'unknown source key' => [['version' => 1, 'sources' => [$source + ['output' => 'x']]], 'Unknown key "output"', '/sources/0/output'],
            'bad namespace' => [['version' => 1, 'sources' => [['namespace' => 'App\1Dto'] + $source]], 'is not a valid namespace', '/sources/0/namespace'],
            'empty include' => [['version' => 1, 'sources' => [$source + ['include' => []]]], '"include" must not be empty', '/sources/0/include'],
            'include not strings' => [['version' => 1, 'sources' => [$source + ['include' => [1]]]], 'Expected a non-empty string', '/sources/0/include/0'],
        ];
    }

    /**
     * @param array<array-key, mixed> $raw
     */
    private function valid(array $raw): GeneratorConfig
    {
        $diagnostics = new Diagnostics();
        $config = (new ConfigFactory())->create($raw, self::PATH, $diagnostics);

        self::assertSame([], array_map(static fn (Diagnostic $d): string => $d->toString(), $diagnostics->all()));
        self::assertNotNull($config);

        return $config;
    }
}
```

- [ ] **Step 2: Убедиться, что тесты падают**

Run: `vendor/bin/phpunit --filter 'ConfigFactoryTest|EnumValuesTest'`
Expected: FAIL — `Class "...\Application\Config\ConfigFactory" not found`, `Class "...\Target\AllOfStrategy" not found`.

- [ ] **Step 3: Реализовать перечисление и DTO настроек**

`src/Domain/Target/AllOfStrategy.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Target;

use MSSTC4PHP\DtoGenerator\Domain\Shared\AbstractEnum;

final class AllOfStrategy extends AbstractEnum
{
    public const EXTENDS = 'extends';

    public const MERGE = 'merge';

    protected static function values(): array
    {
        return [self::EXTENDS, self::MERGE];
    }
}
```

`src/Application/Config/TargetSettings.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Config;

use MSSTC4PHP\DtoGenerator\Domain\Target\MetadataMode;
use MSSTC4PHP\DtoGenerator\Domain\Target\PhpVersion;

final class TargetSettings
{
    private ?PhpVersion $php;

    private ?MetadataMode $metadata;

    private bool $strict;

    /**
     * @param PhpVersion|null $php null for "auto"
     * @param MetadataMode|null $metadata null for "auto"
     */
    public function __construct(?PhpVersion $php, ?MetadataMode $metadata, bool $strict)
    {
        $this->php = $php;
        $this->metadata = $metadata;
        $this->strict = $strict;
    }

    public function php(): ?PhpVersion
    {
        return $this->php;
    }

    public function metadata(): ?MetadataMode
    {
        return $this->metadata;
    }

    public function isStrict(): bool
    {
        return $this->strict;
    }
}
```

`src/Application/Config/DtoSettings.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Config;

use MSSTC4PHP\DtoGenerator\Domain\Target\AccessorStyle;
use MSSTC4PHP\DtoGenerator\Domain\Target\AllOfStrategy;
use MSSTC4PHP\DtoGenerator\Domain\Target\DateTimeClass;
use MSSTC4PHP\DtoGenerator\Domain\Target\Mutability;

final class DtoSettings
{
    private Mutability $mutability;

    private AccessorStyle $accessors;

    private DateTimeClass $dateTimeClass;

    private AllOfStrategy $allOfStrategy;

    public function __construct(Mutability $mutability, AccessorStyle $accessors, DateTimeClass $dateTimeClass, AllOfStrategy $allOfStrategy)
    {
        $this->mutability = $mutability;
        $this->accessors = $accessors;
        $this->dateTimeClass = $dateTimeClass;
        $this->allOfStrategy = $allOfStrategy;
    }

    public function mutability(): Mutability
    {
        return $this->mutability;
    }

    public function accessors(): AccessorStyle
    {
        return $this->accessors;
    }

    public function dateTimeClass(): DateTimeClass
    {
        return $this->dateTimeClass;
    }

    public function allOfStrategy(): AllOfStrategy
    {
        return $this->allOfStrategy;
    }
}
```

`src/Application/Config/ExtensionSettings.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Config;

use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Json;

/**
 * @phpstan-import-type JsonValue from Json
 */
final class ExtensionSettings
{
    /** @var list<ClassName> */
    private array $classes;

    private bool $discover;

    /** @var array<string, JsonValue> */
    private array $config;

    /** @var array<string, array<array-key, mixed>> */
    private array $aliases;

    private ?bool $verifyClasses;

    /**
     * @param list<ClassName> $classes extensions listed explicitly, in order
     * @param array<string, JsonValue> $config extensionConfig sections by extension name
     * @param array<string, array<array-key, mixed>> $aliases attributeAliases; their shape is validated in stage 5
     * @param bool|null $verifyClasses null for "auto"
     */
    public function __construct(array $classes, bool $discover, array $config, array $aliases, ?bool $verifyClasses)
    {
        $this->classes = $classes;
        $this->discover = $discover;
        $this->config = $config;
        $this->aliases = $aliases;
        $this->verifyClasses = $verifyClasses;
    }

    /**
     * @return list<ClassName>
     */
    public function classes(): array
    {
        return $this->classes;
    }

    public function discover(): bool
    {
        return $this->discover;
    }

    /**
     * @return array<string, JsonValue>
     */
    public function config(): array
    {
        return $this->config;
    }

    /**
     * @return array<string, array<array-key, mixed>>
     */
    public function aliases(): array
    {
        return $this->aliases;
    }

    public function verifyClasses(): ?bool
    {
        return $this->verifyClasses;
    }
}
```

`src/Application/Config/SourceConfig.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Config;

use InvalidArgumentException;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Path;

final class SourceConfig
{
    private string $spec;

    private string $namespace;

    private string $outputDir;

    /** @var non-empty-list<string> */
    private array $include;

    /** @var list<string> */
    private array $exclude;

    /**
     * @param list<string> $include glob patterns over component schema names
     * @param list<string> $exclude glob patterns over component schema names
     */
    public function __construct(string $spec, string $namespace, string $outputDir, array $include, array $exclude)
    {
        if (!Path::isAbsolute($spec) || !Path::isAbsolute($outputDir)) {
            throw new InvalidArgumentException('Source paths must be absolute.');
        }

        if ($include === []) {
            throw new InvalidArgumentException('A source must include at least one pattern.');
        }

        $this->spec = Path::normalize($spec);
        $this->namespace = $namespace;
        $this->outputDir = Path::normalize($outputDir);
        $this->include = $include;
        $this->exclude = $exclude;
    }

    public function spec(): string
    {
        return $this->spec;
    }

    public function namespace(): string
    {
        return $this->namespace;
    }

    public function outputDir(): string
    {
        return $this->outputDir;
    }

    /**
     * @return non-empty-list<string>
     */
    public function include(): array
    {
        return $this->include;
    }

    /**
     * @return list<string>
     */
    public function exclude(): array
    {
        return $this->exclude;
    }
}
```

`src/Application/Config/GeneratorConfig.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Config;

use InvalidArgumentException;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Path;

final class GeneratorConfig
{
    private string $path;

    private TargetSettings $target;

    private DtoSettings $dto;

    /** @var array<string, ClassName> */
    private array $formats;

    private ExtensionSettings $extensions;

    /** @var non-empty-list<SourceConfig> */
    private array $sources;

    /**
     * @param string $path absolute path of the config file; relative paths inside it were resolved against its directory
     * @param array<string, ClassName> $formats custom format → PHP type
     * @param non-empty-list<SourceConfig> $sources
     */
    public function __construct(string $path, TargetSettings $target, DtoSettings $dto, array $formats, ExtensionSettings $extensions, array $sources)
    {
        if (!Path::isAbsolute($path)) {
            throw new InvalidArgumentException(sprintf('Config path "%s" must be absolute.', $path));
        }

        $this->path = Path::normalize($path);
        $this->target = $target;
        $this->dto = $dto;
        $this->formats = $formats;
        $this->extensions = $extensions;
        $this->sources = $sources;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function baseDir(): string
    {
        return Path::directory($this->path);
    }

    /**
     * Root of every config diagnostic.
     */
    public function location(): SchemaLocation
    {
        return new SchemaLocation($this->path);
    }

    public function target(): TargetSettings
    {
        return $this->target;
    }

    public function dto(): DtoSettings
    {
        return $this->dto;
    }

    /**
     * @return array<string, ClassName>
     */
    public function formats(): array
    {
        return $this->formats;
    }

    public function extensions(): ExtensionSettings
    {
        return $this->extensions;
    }

    /**
     * @return non-empty-list<SourceConfig>
     */
    public function sources(): array
    {
        return $this->sources;
    }
}
```

- [ ] **Step 4: Реализовать разбор**

`src/Application/Config/RawSection.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Config;

use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Json;

/**
 * Typed, located reads from one decoded config object. A wrong value becomes a diagnostic and the default
 * is used, so every mistake in the file is reported in one run.
 *
 * @phpstan-import-type JsonValue from Json
 */
final class RawSection
{
    /** @var array<array-key, mixed> */
    private array $values;

    private SchemaLocation $location;

    private Diagnostics $diagnostics;

    /**
     * @param array<array-key, mixed> $values
     */
    public function __construct(array $values, SchemaLocation $location, Diagnostics $diagnostics)
    {
        $this->values = $values;
        $this->location = $location;
        $this->diagnostics = $diagnostics;
    }

    public function location(): SchemaLocation
    {
        return $this->location;
    }

    /**
     * @param list<string> $known
     */
    public function rejectUnknownKeys(array $known): void
    {
        foreach ($this->keys() as $key) {
            if (!in_array($key, $known, true)) {
                $this->report(sprintf('Unknown key "%s"; expected one of: %s.', $key, implode(', ', $known)), $key);
            }
        }
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    /**
     * @return JsonValue
     */
    public function raw(string $key)
    {
        return $this->has($key) ? Json::value($this->values[$key]) : null;
    }

    public function requiredString(string $key): ?string
    {
        if (!$this->has($key)) {
            $this->report(sprintf('"%s" is required.', $key));

            return null;
        }

        $value = $this->values[$key];
        if (!is_string($value) || $value === '') {
            $this->error($key, 'must be a non-empty string');

            return null;
        }

        return $value;
    }

    /**
     * @param list<string> $allowed
     */
    public function choice(string $key, string $default, array $allowed): string
    {
        if (!$this->has($key)) {
            return $default;
        }

        $value = $this->values[$key];
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            $this->error($key, sprintf('must be one of: %s', implode(', ', $allowed)));

            return $default;
        }

        return $value;
    }

    public function bool(string $key, bool $default): bool
    {
        if (!$this->has($key)) {
            return $default;
        }

        $value = $this->values[$key];
        if (!is_bool($value)) {
            $this->error($key, 'must be true or false');

            return $default;
        }

        return $value;
    }

    /**
     * @param list<string> $default
     *
     * @return list<string>
     */
    public function stringList(string $key, array $default): array
    {
        if (!$this->has($key)) {
            return $default;
        }

        $value = $this->values[$key];
        if (!is_array($value) || !Json::isList($value)) {
            $this->error($key, 'must be a list of strings');

            return $default;
        }

        $strings = [];
        foreach ($value as $index => $item) {
            if (!is_string($item) || $item === '') {
                $this->report('Expected a non-empty string.', $key, (string) $index);

                continue;
            }

            $strings[] = $item;
        }

        return $strings;
    }

    public function section(string $key): self
    {
        $value = $this->has($key) ? $this->values[$key] : [];
        if (!is_array($value) || ($value !== [] && Json::isList($value))) {
            $this->error($key, 'must be an object');
            $value = [];
        }

        return new self($value, $this->location->child($key), $this->diagnostics);
    }

    /**
     * @return list<self>
     */
    public function sectionList(string $key): array
    {
        if (!$this->has($key)) {
            $this->report(sprintf('"%s" is required.', $key));

            return [];
        }

        $value = $this->values[$key];
        if (!is_array($value) || $value === [] || !Json::isList($value)) {
            $this->error($key, 'must be a non-empty list');

            return [];
        }

        $sections = [];
        foreach ($value as $index => $item) {
            if (!is_array($item) || ($item !== [] && Json::isList($item))) {
                $this->report('Expected an object.', $key, (string) $index);

                continue;
            }

            $sections[] = new self($item, $this->location->child($key, (string) $index), $this->diagnostics);
        }

        return $sections;
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_map('strval', array_keys($this->values));
    }

    public function error(string $key, string $problem): void
    {
        $this->report(sprintf('"%s" %s.', $key, $problem), $key);
    }

    public function report(string $message, string ...$path): void
    {
        $this->diagnostics->error($message, $this->location->child(...$path));
    }
}
```

`src/Application/Config/ConfigFactory.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Config;

use InvalidArgumentException;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;
use MSSTC4PHP\DtoGenerator\Domain\Exception\UnsupportedPhpVersion;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;
use MSSTC4PHP\DtoGenerator\Domain\Model\Identifier;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Extensions;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;
use MSSTC4PHP\DtoGenerator\Domain\Shared\AbstractEnum;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Json;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Path;
use MSSTC4PHP\DtoGenerator\Domain\Target\AccessorStyle;
use MSSTC4PHP\DtoGenerator\Domain\Target\AllOfStrategy;
use MSSTC4PHP\DtoGenerator\Domain\Target\DateTimeClass;
use MSSTC4PHP\DtoGenerator\Domain\Target\MetadataMode;
use MSSTC4PHP\DtoGenerator\Domain\Target\Mutability;
use MSSTC4PHP\DtoGenerator\Domain\Target\PhpVersion;

/**
 * Validates a decoded `dto-generator.yaml` (spec §4) into a {@see GeneratorConfig}.
 */
final class ConfigFactory
{
    private const ROOT_KEYS = [
        'version', 'target', 'dto', 'formats', 'attributeAliases', 'verifyClasses', 'discoverExtensions',
        'extensions', 'extensionConfig', 'sources',
    ];

    /**
     * @param array<array-key, mixed> $raw
     * @param string $path absolute path of the config file
     */
    public function create(array $raw, string $path, Diagnostics $diagnostics): ?GeneratorConfig
    {
        if (!Path::isAbsolute($path)) {
            throw new InvalidArgumentException(sprintf('Config path "%s" must be absolute.', $path));
        }

        $errors = count($diagnostics->errors());
        $root = new RawSection($raw, new SchemaLocation(Path::normalize($path)), $diagnostics);
        $root->rejectUnknownKeys(self::ROOT_KEYS);
        if (!$root->has('version')) {
            $root->report('"version" is required.');
        } elseif ($root->raw('version') !== 1) {
            $root->error('version', 'must be 1');
        }

        $target = $this->target($root->section('target'));
        $dto = $this->dto($root->section('dto'));
        $formats = $this->formats($root->section('formats'));
        $extensions = $this->extensions($root);
        $sources = $this->sources($root, Path::directory($path));

        if (count($diagnostics->errors()) > $errors || $sources === []) {
            return null;
        }

        return new GeneratorConfig($path, $target, $dto, $formats, $extensions, $sources);
    }

    private function target(RawSection $section): TargetSettings
    {
        $section->rejectUnknownKeys(['php', 'metadata', 'strict']);

        $php = null;
        $value = $section->raw('php') ?? 'auto';
        // Unquoted `php: 8.2` arrives from YAML as a float, and 8.0 would print as "8".
        if (is_float($value)) {
            $value = sprintf('%.1f', $value);
        }

        if (!is_string($value)) {
            $section->error('php', 'must be "auto" or a version such as "8.2"');
        } elseif ($value !== 'auto') {
            try {
                $php = PhpVersion::fromString($value);
            } catch (UnsupportedPhpVersion $exception) {
                $section->report($exception->getMessage(), 'php');
            }
        }

        $metadata = $section->choice('metadata', 'auto', array_merge(['auto'], self::values(MetadataMode::cases())));

        return new TargetSettings(
            $php,
            $metadata === 'auto' ? null : MetadataMode::from($metadata),
            $section->bool('strict', true),
        );
    }

    private function dto(RawSection $section): DtoSettings
    {
        $section->rejectUnknownKeys(['mutability', 'accessors', 'dateTimeClass', 'allOfStrategy']);

        return new DtoSettings(
            Mutability::from($section->choice('mutability', Mutability::IMMUTABLE, self::values(Mutability::cases()))),
            AccessorStyle::from($section->choice('accessors', AccessorStyle::AUTO, self::values(AccessorStyle::cases()))),
            DateTimeClass::from($section->choice('dateTimeClass', DateTimeClass::IMMUTABLE, self::values(DateTimeClass::cases()))),
            AllOfStrategy::from($section->choice('allOfStrategy', AllOfStrategy::EXTENDS, self::values(AllOfStrategy::cases()))),
        );
    }

    /**
     * @return array<string, ClassName>
     */
    private function formats(RawSection $section): array
    {
        $formats = [];
        foreach ($section->keys() as $name) {
            $entry = $section->section($name);
            $entry->rejectUnknownKeys(['type']);
            $type = $entry->requiredString('type');
            if ($type === null) {
                continue;
            }

            try {
                $formats[$name] = ClassName::fromFqcn($type);
            } catch (InvalidModel $exception) {
                $entry->report($exception->getMessage(), 'type');
            }
        }

        return $formats;
    }

    private function extensions(RawSection $root): ExtensionSettings
    {
        $classes = [];
        foreach ($root->stringList('extensions', []) as $index => $class) {
            try {
                $classes[] = ClassName::fromFqcn($class);
            } catch (InvalidModel $exception) {
                $root->report($exception->getMessage(), 'extensions', (string) $index);
            }
        }

        $aliasSection = $root->section('attributeAliases');
        $aliases = [];
        foreach ($aliasSection->keys() as $name) {
            if (!Extensions::isExtensionKey($name) || strncmp($name, 'x-php-', 6) === 0 || strncmp($name, 'x-dto-', 6) === 0) {
                $aliasSection->report(sprintf('Alias "%s" must be an "x-" key outside the reserved "x-php-" and "x-dto-" prefixes.', $name), $name);

                continue;
            }

            $value = $aliasSection->raw($name);
            if (!is_array($value) || ($value !== [] && Json::isList($value))) {
                $aliasSection->report('An alias must be an object with "class" and optional "args".', $name);

                continue;
            }

            $aliases[$name] = $value;
        }

        $verify = $root->raw('verifyClasses') ?? 'auto';
        if ($verify !== 'auto' && !is_bool($verify)) {
            $root->error('verifyClasses', 'must be "auto", true or false');
            $verify = 'auto';
        }

        $configSection = $root->section('extensionConfig');
        $config = [];
        foreach ($configSection->keys() as $name) {
            $config[$name] = $configSection->raw($name);
        }

        return new ExtensionSettings($classes, $root->bool('discoverExtensions', true), $config, $aliases, is_bool($verify) ? $verify : null);
    }

    /**
     * @return list<SourceConfig>
     */
    private function sources(RawSection $root, string $baseDir): array
    {
        $sources = [];
        foreach ($root->sectionList('sources') as $section) {
            $section->rejectUnknownKeys(['spec', 'namespace', 'outputDir', 'include', 'exclude']);
            $spec = $section->requiredString('spec');
            $namespace = $section->requiredString('namespace');
            $outputDir = $section->requiredString('outputDir');
            $include = $section->stringList('include', ['*']);
            $exclude = $section->stringList('exclude', []);

            if ($namespace !== null) {
                try {
                    $namespace = Identifier::normalizeQualifiedName($namespace, 'namespace');
                } catch (InvalidModel $exception) {
                    $section->report($exception->getMessage(), 'namespace');
                    $namespace = null;
                }
            }

            if ($include === []) {
                $section->error('include', 'must not be empty');
            }

            if ($spec === null || $namespace === null || $outputDir === null || $include === []) {
                continue;
            }

            $sources[] = new SourceConfig(Path::resolve($baseDir, $spec), $namespace, Path::resolve($baseDir, $outputDir), $include, $exclude);
        }

        return $sources;
    }

    /**
     * @param list<AbstractEnum> $cases
     *
     * @return list<string>
     */
    private static function values(array $cases): array
    {
        return array_map(static fn (AbstractEnum $case): string => $case->value(), $cases);
    }
}
```

> В кейсе `'include not strings'` строка `1` отбрасывается с ошибкой, и `include` становится пустым. Так появляется вторая ошибка — «must not be empty». Тест фильтрует ошибки по подстроке сообщения, поэтому ожидание «ровно одна совпавшая» выполняется.

`tests/Support/ConfigMother.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Support;

use MSSTC4PHP\DtoGenerator\Application\Config\DtoSettings;
use MSSTC4PHP\DtoGenerator\Application\Config\ExtensionSettings;
use MSSTC4PHP\DtoGenerator\Application\Config\GeneratorConfig;
use MSSTC4PHP\DtoGenerator\Application\Config\SourceConfig;
use MSSTC4PHP\DtoGenerator\Application\Config\TargetSettings;
use MSSTC4PHP\DtoGenerator\Domain\Target\AccessorStyle;
use MSSTC4PHP\DtoGenerator\Domain\Target\AllOfStrategy;
use MSSTC4PHP\DtoGenerator\Domain\Target\DateTimeClass;
use MSSTC4PHP\DtoGenerator\Domain\Target\Mutability;

final class ConfigMother
{
    public const PATH = '/project/dto-generator.yaml';

    public static function config(SourceConfig $source, SourceConfig ...$more): GeneratorConfig
    {
        return new GeneratorConfig(
            self::PATH,
            new TargetSettings(null, null, true),
            new DtoSettings(
                Mutability::from(Mutability::IMMUTABLE),
                AccessorStyle::from(AccessorStyle::AUTO),
                DateTimeClass::from(DateTimeClass::IMMUTABLE),
                AllOfStrategy::from(AllOfStrategy::EXTENDS),
            ),
            [],
            new ExtensionSettings([], true, [], [], null),
            array_merge([$source], $more),
        );
    }

    /**
     * @param list<string> $include
     * @param list<string> $exclude
     */
    public static function source(string $spec, array $include = ['*'], array $exclude = [], string $namespace = 'App\Dto'): SourceConfig
    {
        return new SourceConfig($spec, $namespace, '/project/src/Dto', $include, $exclude);
    }
}
```

- [ ] **Step 5: Прогнать тесты**

Run: `vendor/bin/phpunit --filter 'ConfigFactoryTest|EnumValuesTest'`
Expected: PASS.

- [ ] **Step 6: Гейт и коммит**

Run: `make fix && make verify`
Expected: зелёное.

```bash
git add src/Domain/Target/AllOfStrategy.php src/Application/Config tests/Support/ConfigMother.php tests/Unit/Application tests/Unit/Domain/Shared/EnumValuesTest.php
git commit -m "feat(config): typed GeneratorConfig with located validation

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 7: Целевая версия PHP и use-case `Config/Load`

**Files:**
- Modify: `src/Domain/Target/Capability.php` (+`RESERVED_NAMESPACE_SEGMENTS`), `tests/Unit/Domain/Target/CapabilityTest.php`
- Create: `src/Application/Config/PhpConstraint.php`, `src/Application/Config/TargetResolver.php`, `src/Application/Port/ProjectPhpConstraint.php`
- Create: `src/Application/Service/Config/Load/Action.php`, `Input.php`, `Output.php`
- Create: `src/Infrastructure/Environment/ComposerJsonPhpConstraint.php`
- Create: `tests/Support/FixedPhpConstraint.php`
- Test: `tests/Unit/Application/Config/PhpConstraintTest.php`, `tests/Unit/Application/Config/TargetResolverTest.php`, `tests/Unit/Application/Service/Config/LoadTest.php`, `tests/Integration/Infrastructure/ComposerJsonPhpConstraintTest.php`

**Interfaces:**
- Consumes: `GeneratorConfig`, `ConfigFactory` (Task 6), `DocumentLoader` (Task 5), `TargetProfile`, `Identifier::isPhp74Keyword`, `ClassName::reservedNamespaceSegments`.
- Produces:
  - `Capability::RESERVED_NAMESPACE_SEGMENTS` = `'reserved-namespace-segments'`, с PHP 8.0.
  - `PhpConstraint::lowestMinor(string): ?string` — нижняя граница `"X.Y"` из composer-ограничения.
  - Порт `ProjectPhpConstraint::find(string $directory): ?string`, адаптер `ComposerJsonPhpConstraint`. Ищет `require.php` в ближайшем `composer.json` вверх по дереву.
  - `TargetResolver::__construct(ProjectPhpConstraint)` и `resolve(GeneratorConfig, Diagnostics): ?TargetProfile`.
  - Use-case `Config\Load\Action(DocumentLoader, ConfigFactory, TargetResolver)` с `__invoke(Input): Output`.
    - `Input::__construct(string $configPath)` — путь абсолютный.
    - `Output`: `config(): ?GeneratorConfig`, `target(): ?TargetProfile`, `diagnostics(): Diagnostics`.

- [ ] **Step 1: Написать падающие тесты**

В `tests/Unit/Domain/Target/CapabilityTest.php` в `minimumVersions()` после строки `MIXED_TYPE` вставить:

```php
            Capability::RESERVED_NAMESPACE_SEGMENTS => [Capability::RESERVED_NAMESPACE_SEGMENTS, '8.0'],
```

`tests/Unit/Application/Config/PhpConstraintTest.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Application\Config;

use MSSTC4PHP\DtoGenerator\Application\Config\PhpConstraint;
use PHPUnit\Framework\TestCase;

final class PhpConstraintTest extends TestCase
{
    /**
     * @dataProvider constraints
     */
    public function testFindsTheLowestAdmittedMinor(string $constraint, ?string $expected): void
    {
        self::assertSame($expected, PhpConstraint::lowestMinor($constraint));
    }

    /**
     * @return array<string, array{string, string|null}>
     */
    public static function constraints(): array
    {
        return [
            'greater or equal' => ['>=7.4', '7.4'],
            'caret' => ['^8.1', '8.1'],
            'tilde with patch' => ['~8.2.0', '8.2'],
            'wildcard' => ['8.1.*', '8.1'],
            'or' => ['^7.4 || ^8.0', '7.4'],
            'single pipe, higher first' => ['^8.0|^7.4', '7.4'],
            'major only' => ['>=8', '8.0'],
            'range' => ['>=7.4 <8.3', '7.4'],
            'any' => ['*', null],
            'empty' => ['', null],
        ];
    }
}
```

`tests/Support/FixedPhpConstraint.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Support;

use MSSTC4PHP\DtoGenerator\Application\Port\ProjectPhpConstraint;

final class FixedPhpConstraint implements ProjectPhpConstraint
{
    private ?string $constraint;

    public function __construct(?string $constraint)
    {
        $this->constraint = $constraint;
    }

    public function find(string $directory): ?string
    {
        return $this->constraint;
    }
}
```

`tests/Unit/Application/Config/TargetResolverTest.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Application\Config;

use MSSTC4PHP\DtoGenerator\Application\Config\DtoSettings;
use MSSTC4PHP\DtoGenerator\Application\Config\ExtensionSettings;
use MSSTC4PHP\DtoGenerator\Application\Config\GeneratorConfig;
use MSSTC4PHP\DtoGenerator\Application\Config\TargetResolver;
use MSSTC4PHP\DtoGenerator\Application\Config\TargetSettings;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostic;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;
use MSSTC4PHP\DtoGenerator\Domain\Target\AccessorStyle;
use MSSTC4PHP\DtoGenerator\Domain\Target\AllOfStrategy;
use MSSTC4PHP\DtoGenerator\Domain\Target\DateTimeClass;
use MSSTC4PHP\DtoGenerator\Domain\Target\MetadataMode;
use MSSTC4PHP\DtoGenerator\Domain\Target\Mutability;
use MSSTC4PHP\DtoGenerator\Domain\Target\PhpVersion;
use MSSTC4PHP\DtoGenerator\Domain\Target\TargetProfile;
use MSSTC4PHP\DtoGenerator\Tests\Support\ConfigMother;
use MSSTC4PHP\DtoGenerator\Tests\Support\FixedPhpConstraint;
use PHPUnit\Framework\TestCase;

final class TargetResolverTest extends TestCase
{
    public function testUsesAnExplicitVersion(): void
    {
        $profile = $this->resolve($this->config('8.3'), '^7.4');

        self::assertNotNull($profile);
        self::assertSame('8.3', $profile->php()->toString());
        self::assertTrue($profile->metadata()->isAttributes());
    }

    /**
     * @dataProvider detected
     */
    public function testDetectsTheVersionFromComposer(?string $constraint, string $expected, string $metadata): void
    {
        $profile = $this->resolve($this->config(null), $constraint);

        self::assertNotNull($profile);
        self::assertSame($expected, $profile->php()->toString());
        self::assertSame($metadata, $profile->metadata()->value());
    }

    /**
     * @return array<string, array{string|null, string, string}>
     */
    public static function detected(): array
    {
        return [
            'caret 8.1' => ['^8.1', '8.1', 'attributes'],
            'no composer.json' => [null, '7.4', 'annotations'],
            'wildcard' => ['*', '7.4', 'annotations'],
        ];
    }

    /**
     * @dataProvider clamped
     */
    public function testClampsUnsupportedDetectedVersionsWithAWarning(string $constraint, string $expected): void
    {
        $diagnostics = new Diagnostics();
        $profile = (new TargetResolver(new FixedPhpConstraint($constraint)))->resolve($this->config(null), $diagnostics);

        self::assertNotNull($profile);
        self::assertSame($expected, $profile->php()->toString());
        self::assertFalse($diagnostics->hasErrors());
        self::assertCount(1, $diagnostics);
        self::assertStringContainsString(sprintf('generating for PHP %s instead', $expected), $diagnostics->all()[0]->message());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function clamped(): array
    {
        return [
            'too old' => ['^7.2', '7.4'],
            'too new' => ['^9.0', '8.5'],
        ];
    }

    public function testReportsAnIncompatibleTargetAsAConfigError(): void
    {
        $diagnostics = new Diagnostics();
        $config = $this->config('7.4', MetadataMode::ATTRIBUTES);

        self::assertNull((new TargetResolver(new FixedPhpConstraint(null)))->resolve($config, $diagnostics));
        self::assertSame(
            ['error /project/dto-generator.yaml#/target: Metadata mode "attributes" requires attributes (PHP 8.0+), but the target is PHP 7.4.'],
            array_map(static fn (Diagnostic $d): string => $d->toString(), $diagnostics->all()),
        );
    }

    public function testRejectsReservedNamespaceSegmentsBeforePhp80(): void
    {
        $diagnostics = new Diagnostics();
        $config = $this->config('7.4', null, 'App\List\Dto', ['uuid' => ClassName::fromFqcn('Vendor\Fn\Uuid')]);

        self::assertNull((new TargetResolver(new FixedPhpConstraint(null)))->resolve($config, $diagnostics));
        self::assertSame(
            [
                'error /project/dto-generator.yaml#/sources/0/namespace: Namespace "App\List\Dto" contains the reserved word "List", which PHP 7.4 cannot parse in a namespace (allowed from PHP 8.0).',
                'error /project/dto-generator.yaml#/formats/uuid/type: Namespace "Vendor\Fn" contains the reserved word "Fn", which PHP 7.4 cannot parse in a namespace (allowed from PHP 8.0).',
            ],
            array_map(static fn (Diagnostic $d): string => $d->toString(), $diagnostics->all()),
        );
    }

    public function testAllowsReservedNamespaceSegmentsFromPhp80(): void
    {
        self::assertNotNull($this->resolve($this->config('8.0', null, 'App\List\Dto'), null));
    }

    private function resolve(GeneratorConfig $config, ?string $constraint): ?TargetProfile
    {
        $diagnostics = new Diagnostics();
        $profile = (new TargetResolver(new FixedPhpConstraint($constraint)))->resolve($config, $diagnostics);
        self::assertSame([], array_map(static fn (Diagnostic $d): string => $d->toString(), $diagnostics->all()));

        return $profile;
    }

    /**
     * @param array<string, ClassName> $formats
     */
    private function config(?string $php, ?string $metadata = null, string $namespace = 'App\Dto', array $formats = []): GeneratorConfig
    {
        return new GeneratorConfig(
            ConfigMother::PATH,
            new TargetSettings($php === null ? null : PhpVersion::fromString($php), $metadata === null ? null : MetadataMode::from($metadata), true),
            new DtoSettings(
                Mutability::from(Mutability::IMMUTABLE),
                AccessorStyle::from(AccessorStyle::AUTO),
                DateTimeClass::from(DateTimeClass::IMMUTABLE),
                AllOfStrategy::from(AllOfStrategy::EXTENDS),
            ),
            $formats,
            new ExtensionSettings([], true, [], [], null),
            [ConfigMother::source('/project/api/openapi.yaml', ['*'], [], $namespace)],
        );
    }
}
```

`tests/Unit/Application/Service/Config/LoadTest.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Application\Service\Config;

use InvalidArgumentException;
use MSSTC4PHP\DtoGenerator\Application\Config\ConfigFactory;
use MSSTC4PHP\DtoGenerator\Application\Config\TargetResolver;
use MSSTC4PHP\DtoGenerator\Application\Service\Config\Load\Action;
use MSSTC4PHP\DtoGenerator\Application\Service\Config\Load\Input;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostic;
use MSSTC4PHP\DtoGenerator\Tests\Support\FixedPhpConstraint;
use MSSTC4PHP\DtoGenerator\Tests\Support\InMemoryDocumentLoader;
use PHPUnit\Framework\TestCase;

final class LoadTest extends TestCase
{
    public function testLoadsConfigAndTarget(): void
    {
        $output = $this->action([
            '/project/dto-generator.yaml' => [
                'version' => 1,
                'sources' => [['spec' => 'api/openapi.yaml', 'namespace' => 'App\Dto', 'outputDir' => 'src/Dto']],
            ],
        ])(new Input('/project/dto-generator.yaml'));

        self::assertSame([], $output->diagnostics()->all());
        self::assertNotNull($output->config());
        self::assertNotNull($output->target());
        self::assertSame('8.1', $output->target()->php()->toString());
    }

    public function testReportsAMissingConfigFile(): void
    {
        $output = $this->action([])(new Input('/project/dto-generator.yaml'));

        self::assertNull($output->config());
        self::assertNull($output->target());
        self::assertSame(
            ['error: File "/project/dto-generator.yaml" does not exist.'],
            array_map(static fn (Diagnostic $d): string => $d->toString(), $output->diagnostics()->all()),
        );
    }

    public function testStopsAfterConfigErrors(): void
    {
        $output = $this->action(['/project/dto-generator.yaml' => ['version' => 2]])(new Input('/project/dto-generator.yaml'));

        self::assertNull($output->config());
        self::assertNull($output->target());
        self::assertTrue($output->diagnostics()->hasErrors());
    }

    public function testRequiresAnAbsolutePath(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be absolute');

        new Input('dto-generator.yaml');
    }

    /**
     * @param array<string, array<array-key, mixed>> $documents
     */
    private function action(array $documents): Action
    {
        return new Action(
            new InMemoryDocumentLoader($documents),
            new ConfigFactory(),
            new TargetResolver(new FixedPhpConstraint('^8.1')),
        );
    }
}
```

`tests/Integration/Infrastructure/ComposerJsonPhpConstraintTest.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Integration\Infrastructure;

use MSSTC4PHP\DtoGenerator\Infrastructure\Environment\ComposerJsonPhpConstraint;
use PHPUnit\Framework\TestCase;

final class ComposerJsonPhpConstraintTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/dto-generator-composer-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/nested/deeper', 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (['/nested/deeper/composer.json', '/composer.json'] as $file) {
            if (is_file($this->root . $file)) {
                unlink($this->root . $file);
            }
        }

        rmdir($this->root . '/nested/deeper');
        rmdir($this->root . '/nested');
        rmdir($this->root);
    }

    public function testFindsTheNearestComposerJsonUpTheTree(): void
    {
        file_put_contents($this->root . '/composer.json', '{"require": {"php": "^8.1"}}');

        self::assertSame('^8.1', (new ComposerJsonPhpConstraint())->find($this->root . '/nested/deeper'));
    }

    public function testStopsAtTheFirstComposerJsonEvenWithoutPhp(): void
    {
        file_put_contents($this->root . '/composer.json', '{"require": {"php": "^8.1"}}');
        file_put_contents($this->root . '/nested/deeper/composer.json', '{"require": {"psr/log": "^3.0"}}');

        self::assertNull((new ComposerJsonPhpConstraint())->find($this->root . '/nested/deeper'));
    }

    public function testIgnoresInvalidJson(): void
    {
        file_put_contents($this->root . '/composer.json', '{"require":');

        self::assertNull((new ComposerJsonPhpConstraint())->find($this->root));
    }
}
```

- [ ] **Step 2: Убедиться, что тесты падают**

Run: `vendor/bin/phpunit --filter 'CapabilityTest|PhpConstraintTest|TargetResolverTest|LoadTest|ComposerJsonPhpConstraintTest'`
Expected: FAIL — `Undefined constant ...Capability::RESERVED_NAMESPACE_SEGMENTS` и `Class ... not found`.

- [ ] **Step 3: Реализовать**

`src/Domain/Target/Capability.php`:
- константу добавить после `MIXED_TYPE`:

  ```php
      public const RESERVED_NAMESPACE_SEGMENTS = 'reserved-namespace-segments';
  ```

- строку в `MINIMUM_VERSION` добавить после строки `MIXED_TYPE`:

  ```php
          self::RESERVED_NAMESPACE_SEGMENTS => '8.0',
  ```

`src/Application/Config/PhpConstraint.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Config;

final class PhpConstraint
{
    private function __construct()
    {
    }

    /**
     * Lowest "major.minor" a Composer constraint admits; the first number of each alternative is its lower
     * bound for every operator users put on "php" (>=, ^, ~, x.y.*). Null when no version is named.
     */
    public static function lowestMinor(string $constraint): ?string
    {
        $lowest = null;
        foreach (preg_split('/\s*\|\|?\s*/', trim($constraint)) ?: [] as $alternative) {
            if (preg_match('/(\d+)(?:\.(\d+))?/', $alternative, $matches) !== 1) {
                continue;
            }

            $candidate = [(int) $matches[1], isset($matches[2]) && $matches[2] !== '' ? (int) $matches[2] : 0];
            if ($lowest === null || $candidate < $lowest) {
                $lowest = $candidate;
            }
        }

        return $lowest === null ? null : $lowest[0] . '.' . $lowest[1];
    }
}
```

`src/Application/Port/ProjectPhpConstraint.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Port;

interface ProjectPhpConstraint
{
    /**
     * The `require.php` constraint of the nearest composer.json at or above $directory, if any.
     */
    public function find(string $directory): ?string;
}
```

`src/Application/Config/TargetResolver.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Config;

use MSSTC4PHP\DtoGenerator\Application\Port\ProjectPhpConstraint;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Exception\IncompatibleTarget;
use MSSTC4PHP\DtoGenerator\Domain\Exception\UnsupportedPhpVersion;
use MSSTC4PHP\DtoGenerator\Domain\Model\Identifier;
use MSSTC4PHP\DtoGenerator\Domain\Target\Capability;
use MSSTC4PHP\DtoGenerator\Domain\Target\MetadataMode;
use MSSTC4PHP\DtoGenerator\Domain\Target\PhpVersion;
use MSSTC4PHP\DtoGenerator\Domain\Target\TargetProfile;

/**
 * Resolves the "auto" settings of spec §4 and checks what only becomes checkable once the target is known.
 */
final class TargetResolver
{
    private ProjectPhpConstraint $constraints;

    public function __construct(ProjectPhpConstraint $constraints)
    {
        $this->constraints = $constraints;
    }

    public function resolve(GeneratorConfig $config, Diagnostics $diagnostics): ?TargetProfile
    {
        $errors = count($diagnostics->errors());
        $settings = $config->target();
        $php = $settings->php() ?? $this->detectPhp($config, $diagnostics);

        try {
            $profile = new TargetProfile(
                $php,
                $settings->metadata() ?? MetadataMode::defaultFor($php),
                $config->dto()->mutability(),
                $config->dto()->accessors(),
                $config->dto()->dateTimeClass(),
                $settings->isStrict(),
            );
        } catch (IncompatibleTarget $exception) {
            $diagnostics->error($exception->getMessage(), $config->location()->child('target'));

            return null;
        }

        $this->checkNamespaces($config, $profile, $diagnostics);

        return count($diagnostics->errors()) > $errors ? null : $profile;
    }

    private function detectPhp(GeneratorConfig $config, Diagnostics $diagnostics): PhpVersion
    {
        $constraint = $this->constraints->find($config->baseDir());
        $lowest = $constraint === null ? null : PhpConstraint::lowestMinor($constraint);
        if ($lowest === null) {
            return PhpVersion::oldest();
        }

        try {
            return PhpVersion::fromString($lowest);
        } catch (UnsupportedPhpVersion $exception) {
            $fallback = version_compare($lowest, PhpVersion::oldest()->toString(), '<') ? PhpVersion::oldest() : PhpVersion::newest();
            $diagnostics->warning(
                sprintf('composer.json requires PHP "%s"; generating for PHP %s instead.', (string) $constraint, $fallback->toString()),
                $config->location()->child('target', 'php'),
            );

            return $fallback;
        }
    }

    private function checkNamespaces(GeneratorConfig $config, TargetProfile $profile, Diagnostics $diagnostics): void
    {
        if ($profile->supports(Capability::from(Capability::RESERVED_NAMESPACE_SEGMENTS))) {
            return;
        }

        foreach ($config->sources() as $index => $source) {
            foreach (explode('\\', $source->namespace()) as $segment) {
                if (Identifier::isPhp74Keyword($segment)) {
                    $diagnostics->error(
                        self::reservedSegment($source->namespace(), $segment, $profile),
                        $config->location()->child('sources', (string) $index, 'namespace'),
                    );
                }
            }
        }

        foreach ($config->formats() as $format => $class) {
            foreach ($class->reservedNamespaceSegments() as $segment) {
                $diagnostics->error(
                    self::reservedSegment($class->namespace(), $segment, $profile),
                    $config->location()->child('formats', (string) $format, 'type'),
                );
            }
        }
    }

    private static function reservedSegment(string $namespace, string $segment, TargetProfile $profile): string
    {
        return sprintf(
            'Namespace "%s" contains the reserved word "%s", which PHP %s cannot parse in a namespace (allowed from PHP 8.0).',
            $namespace,
            $segment,
            $profile->php()->toString(),
        );
    }
}
```

`src/Application/Service/Config/Load/Input.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Service\Config\Load;

use InvalidArgumentException;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Path;

final class Input
{
    private string $configPath;

    public function __construct(string $configPath)
    {
        if (!Path::isAbsolute($configPath)) {
            throw new InvalidArgumentException(sprintf('Config path "%s" must be absolute; resolve it against the working directory first.', $configPath));
        }

        $this->configPath = Path::normalize($configPath);
    }

    public function configPath(): string
    {
        return $this->configPath;
    }
}
```

`src/Application/Service/Config/Load/Output.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Service\Config\Load;

use MSSTC4PHP\DtoGenerator\Application\Config\GeneratorConfig;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Target\TargetProfile;

final class Output
{
    private ?GeneratorConfig $config;

    private ?TargetProfile $target;

    private Diagnostics $diagnostics;

    public function __construct(?GeneratorConfig $config, ?TargetProfile $target, Diagnostics $diagnostics)
    {
        $this->config = $config;
        $this->target = $target;
        $this->diagnostics = $diagnostics;
    }

    public function config(): ?GeneratorConfig
    {
        return $this->config;
    }

    public function target(): ?TargetProfile
    {
        return $this->target;
    }

    public function diagnostics(): Diagnostics
    {
        return $this->diagnostics;
    }
}
```

`src/Application/Service/Config/Load/Action.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Service\Config\Load;

use MSSTC4PHP\DtoGenerator\Application\Config\ConfigFactory;
use MSSTC4PHP\DtoGenerator\Application\Config\TargetResolver;
use MSSTC4PHP\DtoGenerator\Application\Port\DocumentLoader;
use MSSTC4PHP\DtoGenerator\Application\Port\DocumentLoadFailed;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;

final class Action
{
    private DocumentLoader $loader;

    private ConfigFactory $factory;

    private TargetResolver $targets;

    public function __construct(DocumentLoader $loader, ConfigFactory $factory, TargetResolver $targets)
    {
        $this->loader = $loader;
        $this->factory = $factory;
        $this->targets = $targets;
    }

    public function __invoke(Input $input): Output
    {
        $diagnostics = new Diagnostics();

        try {
            $document = $this->loader->load($input->configPath());
        } catch (DocumentLoadFailed $exception) {
            $diagnostics->error($exception->getMessage());

            return new Output(null, null, $diagnostics);
        }

        $config = $this->factory->create($document->root(), $document->path(), $diagnostics);
        $target = $config === null ? null : $this->targets->resolve($config, $diagnostics);

        return new Output($config, $target, $diagnostics);
    }
}
```

`src/Infrastructure/Environment/ComposerJsonPhpConstraint.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Infrastructure\Environment;

use JsonException;
use MSSTC4PHP\DtoGenerator\Application\Port\ProjectPhpConstraint;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Path;

final class ComposerJsonPhpConstraint implements ProjectPhpConstraint
{
    public function find(string $directory): ?string
    {
        $current = Path::normalize($directory);
        while (true) {
            $file = rtrim($current, '/') . '/composer.json';
            if (is_file($file)) {
                return $this->phpRequirement($file);
            }

            $parent = Path::directory($current);
            if ($parent === $current) {
                return null;
            }

            $current = $parent;
        }
    }

    /**
     * The nearest composer.json describes the project even when it does not pin PHP, so the search stops there.
     */
    private function phpRequirement(string $file): ?string
    {
        $content = file_get_contents($file);
        if ($content === false) {
            return null;
        }

        try {
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            return null;
        }

        $require = is_array($data) ? ($data['require'] ?? null) : null;
        $php = is_array($require) ? ($require['php'] ?? null) : null;

        return is_string($php) ? $php : null;
    }
}
```

- [ ] **Step 4: Прогнать тесты**

Run: `vendor/bin/phpunit`
Expected: PASS, включая `CapabilityTest`: порядок ключей провайдера совпадает с `MINIMUM_VERSION`.

- [ ] **Step 5: Гейт и коммит**

Run: `make fix && make verify`
Expected: зелёное.

```bash
git add src/Domain/Target/Capability.php src/Application src/Infrastructure/Environment tests/Support/FixedPhpConstraint.php tests/Unit tests/Integration
git commit -m "feat(config): target resolution with composer.json PHP detection and Config/Load use-case

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 8: Граф схем и use-case `Schemas/Load`

**Files:**
- Create: `src/Domain/Schema/ResolvedSchema.php`, `src/Domain/Schema/SchemaGraph.php`
- Create: `src/Application/Service/Schemas/Load/Action.php`, `Input.php`, `Output.php`, `GraphBuilder.php`
- Test: `tests/Unit/Domain/Schema/SchemaGraphTest.php`, `tests/Unit/Application/Service/Schemas/LoadTest.php`

**Interfaces:**
- Consumes:
  - `SchemaParser` (Task 4), `DocumentLoader`/`Document`/`DocumentLoadFailed` (Task 5);
  - `GeneratorConfig`/`SourceConfig` (Task 6);
  - `Reference`/`JsonPointer`/`ReferenceUse`/`Schema::references` (Task 3);
  - `Diagnostics`, `Json`; `ConfigMother` и `InMemoryDocumentLoader` в тестах.
- Produces:
  - `ResolvedSchema::__construct(Schema, ?int $source, string $name, bool $selected)`.
    Методы: `schema()`, `location()`, `source()`, `name()`, `isSelected()`, `withSource(int)`.
  - `SchemaGraph::__construct(list<ResolvedSchema>)` — дубль ключа бросает `InvalidModel`.
    Методы: `get(SchemaLocation): ?ResolvedSchema`, `resolve(string $ref, SchemaLocation $from): ?ResolvedSchema`, `all(): list<ResolvedSchema>` (в порядке добавления).
  - Use-case `Schemas\Load\Action(DocumentLoader, SchemaParser)` с `__invoke(Input): Output`.
    - `Input::__construct(GeneratorConfig)`, `config()`.
    - `Output`: `graph(): SchemaGraph`, `diagnostics(): Diagnostics`.

- [ ] **Step 1: Написать падающие тесты**

`tests/Unit/Domain/Schema/SchemaGraphTest.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Domain\Schema;

use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;
use MSSTC4PHP\DtoGenerator\Domain\Schema\ResolvedSchema;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaBuilder;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaGraph;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;
use PHPUnit\Framework\TestCase;

final class SchemaGraphTest extends TestCase
{
    public function testFindsSchemasByLocationAndReference(): void
    {
        $user = $this->resolved('/api/openapi.yaml', '/components/schemas/User', 'User', 0);
        $money = $this->resolved('/api/common.json', '/Money', 'Money', null);
        $graph = new SchemaGraph([$user, $money]);

        self::assertSame([$user, $money], $graph->all());
        self::assertSame($user, $graph->get(new SchemaLocation('/api/openapi.yaml', '/components/schemas/User')));
        self::assertSame($money, $graph->resolve('common.json#/Money', $user->location()));
        self::assertSame($user, $graph->resolve('#/components/schemas/User', $user->location()));
        self::assertNull($graph->resolve('#/components/schemas/Missing', $user->location()));
        self::assertNull($graph->resolve('https://example.com/x.json', $user->location()));
    }

    public function testExposesResolvedSchemaParts(): void
    {
        $money = $this->resolved('/api/common.json', '/Money', 'Money', null);
        $owned = $money->withSource(2);

        self::assertNull($money->source());
        self::assertSame(2, $owned->source());
        self::assertSame('Money', $owned->name());
        self::assertFalse($owned->isSelected());
        self::assertSame($money->schema(), $owned->schema());
    }

    public function testRejectsDuplicates(): void
    {
        $this->expectException(InvalidModel::class);
        $this->expectExceptionMessage('is registered twice');

        $user = $this->resolved('/api/openapi.yaml', '/components/schemas/User', 'User', 0);
        new SchemaGraph([$user, $user]);
    }

    public function testRejectsAnEmptyName(): void
    {
        $this->expectException(InvalidModel::class);
        $this->expectExceptionMessage('needs a name');

        $this->resolved('/api/openapi.yaml', '', '', 0);
    }

    private function resolved(string $file, string $pointer, string $name, ?int $source): ResolvedSchema
    {
        return new ResolvedSchema((new SchemaBuilder(new SchemaLocation($file, $pointer)))->build(), $source, $name, $source !== null);
    }
}
```

`tests/Unit/Application/Service/Schemas/LoadTest.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Application\Service\Schemas;

use MSSTC4PHP\DtoGenerator\Application\Config\SourceConfig;
use MSSTC4PHP\DtoGenerator\Application\Service\Schemas\Load\Action;
use MSSTC4PHP\DtoGenerator\Application\Service\Schemas\Load\Input;
use MSSTC4PHP\DtoGenerator\Application\Service\Schemas\Load\Output;
use MSSTC4PHP\DtoGenerator\Domain\Builder\SchemaParser;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostic;
use MSSTC4PHP\DtoGenerator\Domain\Schema\ResolvedSchema;
use MSSTC4PHP\DtoGenerator\Tests\Support\ConfigMother;
use MSSTC4PHP\DtoGenerator\Tests\Support\InMemoryDocumentLoader;
use PHPUnit\Framework\TestCase;

final class LoadTest extends TestCase
{
    private const SPEC = '/project/api/openapi.yaml';

    private const OTHER = '/project/other/openapi.yaml';

    private const SHARED = '/project/shared/common.json';

    public function testLoadsSelectedComponents(): void
    {
        $output = $this->load([self::SPEC => self::spec(['User' => ['type' => 'object'], 'Tag' => ['type' => 'object']])]);

        self::assertSame([], $this->messages($output));
        self::assertSame([['User', 0, true], ['Tag', 0, true]], $this->summary($output));
        self::assertSame('/project/api/openapi.yaml#/components/schemas/User', $output->graph()->all()[0]->location()->toString());
    }

    public function testAppliesIncludeAndExcludeGlobs(): void
    {
        $output = $this->load(
            [self::SPEC => self::spec(['User' => [], 'UserInternal' => [], 'Tag' => [], 'Order' => []])],
            ConfigMother::source(self::SPEC, ['U*', 'Tag'], ['*Internal']),
        );

        self::assertSame([['User', 0, true], ['Tag', 0, true]], $this->summary($output));
    }

    public function testLoadsReferencedButUnselectedComponents(): void
    {
        $output = $this->load(
            [self::SPEC => self::spec([
                'User' => ['properties' => ['tag' => ['$ref' => '#/components/schemas/Tag']]],
                'Tag' => ['type' => 'object'],
            ])],
            ConfigMother::source(self::SPEC, ['User']),
        );

        self::assertSame([['User', 0, true], ['Tag', 0, false]], $this->summary($output));
    }

    public function testExternalFilesBelongToTheReferencingSource(): void
    {
        $output = $this->load([
            self::SPEC => self::spec(['User' => ['properties' => ['salary' => ['$ref' => '../shared/common.json#/Money']]]]),
            self::SHARED => [
                'Money' => ['properties' => ['currency' => ['$ref' => '#/Currency']]],
                'Currency' => ['type' => 'string'],
            ],
        ]);

        self::assertSame([], $this->messages($output));
        self::assertSame([['User', 0, true], ['Money', 0, false], ['Currency', 0, false]], $this->summary($output));
    }

    public function testAFileOfAnotherSourceKeepsItsOwnerAndIsLoadedOnce(): void
    {
        $output = $this->load(
            [
                self::SPEC => self::spec(['User' => ['properties' => ['pet' => ['$ref' => '../other/openapi.yaml#/components/schemas/Pet']]]]),
                self::OTHER => self::spec(['Pet' => ['type' => 'object']]),
            ],
            ConfigMother::source(self::SPEC),
            ConfigMother::source('/project/./other/../other/openapi.yaml', ['*'], [], 'App\Other'),
        );

        self::assertSame([], $this->messages($output));
        self::assertSame([['User', 0, true], ['Pet', 1, true]], $this->summary($output));
    }

    public function testASchemaSharedByTwoSourcesOutsideBothIsAnError(): void
    {
        $output = $this->load(
            [
                self::SPEC => self::spec(['User' => ['$ref' => '../shared/common.json#/Money']]),
                self::OTHER => self::spec(['Pet' => ['$ref' => '../shared/common.json#/Money']]),
                self::SHARED => ['Money' => ['type' => 'object']],
            ],
            ConfigMother::source(self::SPEC),
            ConfigMother::source(self::OTHER, ['*'], [], 'App\Other'),
        );

        self::assertSame(
            ['error /project/shared/common.json#/Money: Schema is referenced from sources #0, #1, so its namespace is ambiguous; add its file as a source.'],
            $this->messages($output),
        );
    }

    public function testFollowsCyclesOnce(): void
    {
        $output = $this->load([self::SPEC => self::spec([
            'User' => ['properties' => ['group' => ['$ref' => '#/components/schemas/Group'], 'self' => ['$ref' => '#/components/schemas/User']]],
            'Group' => ['properties' => ['members' => ['items' => ['$ref' => '#/components/schemas/User']]]],
        ])]);

        self::assertSame([], $this->messages($output));
        self::assertSame([['User', 0, true], ['Group', 0, true]], $this->summary($output));
    }

    public function testResolvesEscapedReferences(): void
    {
        $output = $this->load(
            [self::SPEC => self::spec([
                'User' => ['properties' => ['a' => ['$ref' => '#/components/schemas/My%20Type'], 'b' => ['$ref' => '#/components/schemas/a~1b']]],
                'My Type' => ['type' => 'string'],
                'a/b' => ['type' => 'string'],
            ])],
            ConfigMother::source(self::SPEC, ['User']),
        );

        self::assertSame([], $this->messages($output));
        self::assertSame([['User', 0, true], ['My Type', 0, false], ['a/b', 0, false]], $this->summary($output));
    }

    public function testFollowsBareDiscriminatorNames(): void
    {
        $output = $this->load(
            [self::SPEC => self::spec([
                'Pet' => ['discriminator' => ['propertyName' => 'kind', 'mapping' => ['cat' => 'Cat']]],
                'Cat' => ['type' => 'object'],
            ])],
            ConfigMother::source(self::SPEC, ['Pet']),
        );

        self::assertSame([['Pet', 0, true], ['Cat', 0, false]], $this->summary($output));
    }

    public function testNamesWholeFileTargetsAfterTheFile(): void
    {
        $output = $this->load([
            self::SPEC => self::spec(['User' => ['$ref' => '../shared/address.yaml']]),
            '/project/shared/address.yaml' => ['type' => 'object'],
        ]);

        self::assertSame([['User', 0, true], ['address', 0, false]], $this->summary($output));
    }

    /**
     * @dataProvider brokenReferences
     *
     * @param array<array-key, mixed> $user
     */
    public function testReportsBrokenReferencesWhereTheyAreWritten(array $user, string $expected): void
    {
        $output = $this->load([self::SPEC => self::spec(['User' => $user])]);

        self::assertSame([$expected], $this->messages($output));
    }

    /**
     * @return array<string, array{array<array-key, mixed>, string}>
     */
    public static function brokenReferences(): array
    {
        $at = 'error /project/api/openapi.yaml#/components/schemas/User/properties/x: ';

        return [
            'remote' => [
                ['properties' => ['x' => ['$ref' => 'https://example.com/x.json']]],
                $at . 'Remote $ref "https://example.com/x.json" is not supported; save the document next to the specification and refer to it by path.',
            ],
            'missing pointer' => [
                ['properties' => ['x' => ['$ref' => '#/components/schemas/Nope']]],
                $at . '$ref "#/components/schemas/Nope" does not resolve: /project/api/openapi.yaml has nothing at "/components/schemas/Nope".',
            ],
            'missing file' => [
                ['properties' => ['x' => ['$ref' => 'missing.yaml#/X']]],
                $at . 'File "/project/api/missing.yaml" does not exist.',
            ],
            'anchor' => [
                ['properties' => ['x' => ['$ref' => '#User']]],
                $at . '$ref "#User": only JSON pointer fragments are supported, not anchors.',
            ],
        ];
    }

    public function testReportsAMissingSpecificationAtTheConfig(): void
    {
        $output = $this->load([]);

        self::assertSame(['error /project/dto-generator.yaml#/sources/0/spec: File "/project/api/openapi.yaml" does not exist.'], $this->messages($output));
    }

    public function testReportsTheSameSpecificationUsedTwice(): void
    {
        $output = $this->load(
            [self::SPEC => self::spec(['User' => []])],
            ConfigMother::source(self::SPEC),
            ConfigMother::source('/project/api/../api/openapi.yaml', ['*'], [], 'App\Again'),
        );

        self::assertSame(['error /project/dto-generator.yaml#/sources/1/spec: The specification is already used by source #0.'], $this->messages($output));
    }

    public function testWarnsAboutOtherOpenApiVersions(): void
    {
        $output = $this->load([self::SPEC => ['openapi' => '3.0.3', 'components' => ['schemas' => []]]]);

        self::assertSame(
            ['warning /project/api/openapi.yaml#/openapi: Expected OpenAPI 3.1, found "3.0.3"; keywords specific to that version (such as "nullable") are not understood.'],
            $this->messages($output),
        );
    }

    public function testWarnsAboutAMissingVersionAndMissingSchemas(): void
    {
        $output = $this->load([self::SPEC => ['info' => []]]);

        self::assertSame(
            [
                'warning /project/api/openapi.yaml#/openapi: No "openapi" version; the document is read as OpenAPI 3.1.',
                'warning /project/api/openapi.yaml#/components/schemas: The specification has no components/schemas; nothing to generate.',
            ],
            $this->messages($output),
        );
    }

    public function testRejectsSchemasThatAreNotAnObject(): void
    {
        $output = $this->load([self::SPEC => ['openapi' => '3.1.0', 'components' => ['schemas' => [['type' => 'object']]]]]);

        self::assertSame(['error /project/api/openapi.yaml#/components/schemas: "schemas" must be an object.'], $this->messages($output));
    }

    /**
     * @param array<string, array<array-key, mixed>> $documents
     */
    private function load(array $documents, SourceConfig ...$sources): Output
    {
        $config = $sources === [] ? ConfigMother::config(ConfigMother::source(self::SPEC)) : ConfigMother::config(...$sources);

        return (new Action(new InMemoryDocumentLoader($documents), new SchemaParser()))(new Input($config));
    }

    /**
     * @param array<string, array<array-key, mixed>> $schemas
     *
     * @return array<string, mixed>
     */
    private static function spec(array $schemas): array
    {
        return ['openapi' => '3.1.0', 'components' => ['schemas' => $schemas]];
    }

    /**
     * @return list<string>
     */
    private function messages(Output $output): array
    {
        return array_map(static fn (Diagnostic $d): string => $d->toString(), $output->diagnostics()->all());
    }

    /**
     * @return list<array{string, int|null, bool}>
     */
    private function summary(Output $output): array
    {
        return array_map(
            static fn (ResolvedSchema $schema): array => [$schema->name(), $schema->source(), $schema->isSelected()],
            $output->graph()->all(),
        );
    }
}
```

- [ ] **Step 2: Убедиться, что тесты падают**

Run: `vendor/bin/phpunit --filter 'SchemaGraphTest|Schemas\\LoadTest'`
Expected: FAIL — `Class "...\Schema\ResolvedSchema" not found`.

- [ ] **Step 3: Реализовать доменную часть**

`src/Domain/Schema/ResolvedSchema.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Schema;

use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;

final class ResolvedSchema
{
    private Schema $schema;

    private ?int $source;

    private string $name;

    private bool $selected;

    /**
     * @param int|null $source index of the owning config source; null while its file belongs to none
     * @param string $name component name, or the last pointer segment / file name for other targets
     * @param bool $selected picked by a source's include/exclude filter rather than only referenced
     */
    public function __construct(Schema $schema, ?int $source, string $name, bool $selected)
    {
        if ($name === '') {
            throw new InvalidModel(sprintf('Resolved schema %s needs a name.', $schema->location()->toString()));
        }

        $this->schema = $schema;
        $this->source = $source;
        $this->name = $name;
        $this->selected = $selected;
    }

    public function schema(): Schema
    {
        return $this->schema;
    }

    public function location(): SchemaLocation
    {
        return $this->schema->location();
    }

    public function source(): ?int
    {
        return $this->source;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function isSelected(): bool
    {
        return $this->selected;
    }

    public function withSource(int $source): self
    {
        return new self($this->schema, $source, $this->name, $this->selected);
    }
}
```

`src/Domain/Schema/SchemaGraph.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Schema;

use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;

/**
 * Every schema a generation run needs, keyed by location: selected components and all their $ref targets.
 */
final class SchemaGraph
{
    /** @var array<string, ResolvedSchema> */
    private array $schemas = [];

    /**
     * @param list<ResolvedSchema> $schemas
     */
    public function __construct(array $schemas)
    {
        foreach ($schemas as $schema) {
            $key = $schema->location()->toString();
            if (isset($this->schemas[$key])) {
                throw new InvalidModel(sprintf('Schema %s is registered twice.', $key));
            }

            $this->schemas[$key] = $schema;
        }
    }

    public function get(SchemaLocation $location): ?ResolvedSchema
    {
        return $this->schemas[$location->toString()] ?? null;
    }

    /**
     * Null for remote references and for targets that could not be loaded.
     */
    public function resolve(string $ref, SchemaLocation $from): ?ResolvedSchema
    {
        $target = Reference::target($ref, $from);

        return $target instanceof SchemaLocation ? $this->get($target) : null;
    }

    /**
     * @return list<ResolvedSchema>
     */
    public function all(): array
    {
        return array_values($this->schemas);
    }
}
```

- [ ] **Step 4: Реализовать use-case**

`src/Application/Service/Schemas/Load/Input.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Service\Schemas\Load;

use MSSTC4PHP\DtoGenerator\Application\Config\GeneratorConfig;

final class Input
{
    private GeneratorConfig $config;

    public function __construct(GeneratorConfig $config)
    {
        $this->config = $config;
    }

    public function config(): GeneratorConfig
    {
        return $this->config;
    }
}
```

`src/Application/Service/Schemas/Load/Output.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Service\Schemas\Load;

use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaGraph;

final class Output
{
    private SchemaGraph $graph;

    private Diagnostics $diagnostics;

    public function __construct(SchemaGraph $graph, Diagnostics $diagnostics)
    {
        $this->graph = $graph;
        $this->diagnostics = $diagnostics;
    }

    public function graph(): SchemaGraph
    {
        return $this->graph;
    }

    public function diagnostics(): Diagnostics
    {
        return $this->diagnostics;
    }
}
```

`src/Application/Service/Schemas/Load/GraphBuilder.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Service\Schemas\Load;

use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Schema\ResolvedSchema;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaGraph;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;

/**
 * Mutable accumulator for one load run.
 */
final class GraphBuilder
{
    /** @var array<string, ResolvedSchema> */
    private array $schemas = [];

    /** @var array<string, true> */
    private array $failed = [];

    /** @var array<string, list<string>> target key → keys of the schemas that reference it */
    private array $referrers = [];

    public function add(ResolvedSchema $schema): void
    {
        $this->schemas[$schema->location()->toString()] = $schema;
    }

    public function knows(SchemaLocation $location): bool
    {
        $key = $location->toString();

        return isset($this->schemas[$key]) || isset($this->failed[$key]);
    }

    public function markFailed(SchemaLocation $location): void
    {
        $this->failed[$location->toString()] = true;
    }

    public function addReference(SchemaLocation $from, SchemaLocation $to): void
    {
        $this->referrers[$to->toString()][] = $from->toString();
    }

    /**
     * A schema from a file outside every source belongs to the sources that reference it (spec §4);
     * more than one owner leaves its namespace undecidable.
     */
    public function build(Diagnostics $diagnostics): SchemaGraph
    {
        $owners = $this->propagateOwners();
        $schemas = [];
        foreach ($this->schemas as $key => $schema) {
            if ($schema->source() === null) {
                $candidates = array_keys($owners[$key] ?? []);
                if (count($candidates) === 1) {
                    $schema = $schema->withSource($candidates[0]);
                } elseif (count($candidates) > 1) {
                    $diagnostics->error(
                        sprintf(
                            'Schema is referenced from sources %s, so its namespace is ambiguous; add its file as a source.',
                            implode(', ', array_map(static fn (int $index): string => '#' . $index, $candidates)),
                        ),
                        $schema->location(),
                    );
                }
            }

            $schemas[] = $schema;
        }

        return new SchemaGraph($schemas);
    }

    /**
     * @return array<string, array<int, true>>
     */
    private function propagateOwners(): array
    {
        $owners = [];
        foreach ($this->schemas as $key => $schema) {
            if ($schema->source() !== null) {
                $owners[$key] = [$schema->source() => true];
            }
        }

        do {
            $changed = false;
            foreach ($this->referrers as $target => $referrerKeys) {
                if (!isset($this->schemas[$target]) || $this->schemas[$target]->source() !== null) {
                    continue;
                }

                foreach ($referrerKeys as $referrerKey) {
                    foreach (array_keys($owners[$referrerKey] ?? []) as $owner) {
                        if (!isset($owners[$target][$owner])) {
                            $owners[$target][$owner] = true;
                            $changed = true;
                        }
                    }
                }
            }
        } while ($changed);

        return $owners;
    }
}
```

`src/Application/Service/Schemas/Load/Action.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Service\Schemas\Load;

use MSSTC4PHP\DtoGenerator\Application\Config\SourceConfig;
use MSSTC4PHP\DtoGenerator\Application\Port\DocumentLoader;
use MSSTC4PHP\DtoGenerator\Application\Port\DocumentLoadFailed;
use MSSTC4PHP\DtoGenerator\Application\ValueObject\Document;
use MSSTC4PHP\DtoGenerator\Domain\Builder\SchemaParser;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;
use MSSTC4PHP\DtoGenerator\Domain\Schema\JsonPointer;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Reference;
use MSSTC4PHP\DtoGenerator\Domain\Schema\ReferenceUse;
use MSSTC4PHP\DtoGenerator\Domain\Schema\ResolvedSchema;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Json;

/**
 * Loads every selected component schema of every source, then follows `$ref`s until the graph is closed.
 *
 * @phpstan-import-type JsonValue from Json
 */
final class Action
{
    private DocumentLoader $loader;

    private SchemaParser $parser;

    public function __construct(DocumentLoader $loader, SchemaParser $parser)
    {
        $this->loader = $loader;
        $this->parser = $parser;
    }

    public function __invoke(Input $input): Output
    {
        $config = $input->config();
        $diagnostics = new Diagnostics();
        $graph = new GraphBuilder();
        /** @var array<string, int> $owners spec path → source index */
        $owners = [];
        $queue = [];

        foreach ($config->sources() as $index => $source) {
            $specAt = $config->location()->child('sources', (string) $index, 'spec');
            if (isset($owners[$source->spec()])) {
                $diagnostics->error(sprintf('The specification is already used by source #%d.', $owners[$source->spec()]), $specAt);

                continue;
            }

            $document = $this->load($source->spec(), $specAt, $diagnostics);
            if (!$document instanceof Document) {
                continue;
            }

            $owners[$document->path()] = $index;
            $this->checkOpenApiVersion($document, $diagnostics);
            foreach ($this->componentSchemas($document, $diagnostics) as [$name, $node]) {
                if (!$this->isSelected($name, $source)) {
                    continue;
                }

                $location = (new SchemaLocation($document->path()))->child('components', 'schemas', $name);
                $schema = new ResolvedSchema($this->parser->parse($node, $location, $diagnostics), $index, $name, true);
                $graph->add($schema);
                $queue[] = $schema;
            }
        }

        while ($queue !== []) {
            $current = array_shift($queue);
            foreach ($current->schema()->references() as $use) {
                $target = $this->target($use, $diagnostics);
                if (!$target instanceof SchemaLocation) {
                    continue;
                }

                $graph->addReference($current->location(), $target);
                if ($graph->knows($target)) {
                    continue;
                }

                $resolved = $this->resolve($target, $use, $owners, $diagnostics);
                if (!$resolved instanceof ResolvedSchema) {
                    $graph->markFailed($target);

                    continue;
                }

                $graph->add($resolved);
                $queue[] = $resolved;
            }
        }

        return new Output($graph->build($diagnostics), $diagnostics);
    }

    private function load(string $path, SchemaLocation $requestedAt, Diagnostics $diagnostics): ?Document
    {
        try {
            return $this->loader->load($path);
        } catch (DocumentLoadFailed $exception) {
            $diagnostics->error($exception->getMessage(), $requestedAt);

            return null;
        }
    }

    private function checkOpenApiVersion(Document $document, Diagnostics $diagnostics): void
    {
        $version = $document->root()['openapi'] ?? null;
        $at = (new SchemaLocation($document->path()))->child('openapi');
        if (!is_string($version)) {
            $diagnostics->warning('No "openapi" version; the document is read as OpenAPI 3.1.', $at);

            return;
        }

        if (preg_match('/^3\.1(?:\.\d+)?\z/', $version) !== 1) {
            $diagnostics->warning(
                sprintf('Expected OpenAPI 3.1, found "%s"; keywords specific to that version (such as "nullable") are not understood.', $version),
                $at,
            );
        }
    }

    /**
     * @return list<array{string, JsonValue}> name and node, in document order; numeric names stay strings
     */
    private function componentSchemas(Document $document, Diagnostics $diagnostics): array
    {
        $at = (new SchemaLocation($document->path()))->child('components', 'schemas');
        $components = $document->root()['components'] ?? null;
        $schemas = is_array($components) ? ($components['schemas'] ?? null) : null;
        if ($schemas === null) {
            $diagnostics->warning('The specification has no components/schemas; nothing to generate.', $at);

            return [];
        }

        if (!is_array($schemas) || ($schemas !== [] && Json::isList($schemas))) {
            $diagnostics->error('"schemas" must be an object.', $at);

            return [];
        }

        $pairs = [];
        foreach ($schemas as $name => $node) {
            $pairs[] = [(string) $name, Json::value($node)];
        }

        return $pairs;
    }

    private function isSelected(string $name, SourceConfig $source): bool
    {
        $matches = static fn (string $pattern): bool => fnmatch($pattern, $name);

        return array_filter($source->include(), $matches) !== [] && array_filter($source->exclude(), $matches) === [];
    }

    private function target(ReferenceUse $use, Diagnostics $diagnostics): ?SchemaLocation
    {
        try {
            $target = Reference::target($use->ref(), $use->location());
        } catch (InvalidModel $exception) {
            $diagnostics->error($exception->getMessage(), $use->location());

            return null;
        }

        if (!$target instanceof SchemaLocation) {
            $diagnostics->error(
                sprintf('Remote $ref "%s" is not supported; save the document next to the specification and refer to it by path.', $use->ref()),
                $use->location(),
            );
        }

        return $target;
    }

    /**
     * @param array<string, int> $owners
     */
    private function resolve(SchemaLocation $target, ReferenceUse $use, array $owners, Diagnostics $diagnostics): ?ResolvedSchema
    {
        $document = $this->load($target->file(), $use->location(), $diagnostics);
        if (!$document instanceof Document) {
            return null;
        }

        if (!JsonPointer::has($document->root(), $target->pointer())) {
            $diagnostics->error(
                sprintf('$ref "%s" does not resolve: %s has nothing at "%s".', $use->ref(), $document->path(), $target->pointer()),
                $use->location(),
            );

            return null;
        }

        $node = JsonPointer::get($document->root(), $target->pointer());

        return new ResolvedSchema($this->parser->parse($node, $target, $diagnostics), $owners[$document->path()] ?? null, self::nameOf($target), false);
    }

    private static function nameOf(SchemaLocation $location): string
    {
        $segments = JsonPointer::segments($location->pointer());
        $name = $segments === [] ? '' : $segments[count($segments) - 1];
        if ($name === '') {
            $name = pathinfo($location->file(), PATHINFO_FILENAME);
        }

        return $name === '' ? 'Schema' : $name;
    }
}
```

- [ ] **Step 5: Прогнать тесты**

Run: `vendor/bin/phpunit`
Expected: PASS.

- [ ] **Step 6: Гейт и коммит**

Run: `make fix && make verify`
Expected: зелёное.

```bash
git add src/Domain/Schema src/Application/Service/Schemas tests/Unit/Domain/Schema/SchemaGraphTest.php tests/Unit/Application/Service/Schemas
git commit -m "feat(schemas): Schemas/Load use-case building a closed \$ref graph with source ownership

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 9: Сквозной интеграционный тест и закрытие этапа

**Files:**
- Create: `tests/Fixtures/Projects/petstore/dto-generator.yaml`, `tests/Fixtures/Projects/petstore/api/openapi.yaml`, `tests/Fixtures/Projects/petstore/api/shared/common.json`
- Test: `tests/Integration/PetstoreLoadingTest.php`
- Modify: `README.md`, `.claude/docs/architecture.md`, `.claude/docs/domain-model.md`, `.claude/docs/known-issues.md`, `.claude/docs/conventions.md`, `.claude/docs/README.md`
- Create: `.claude/docs/integrations.md`

**Interfaces:**
- Consumes: всё из Tasks 1–8: `FileDocumentLoader`, `ComposerJsonPhpConstraint`, `ConfigFactory`, `TargetResolver`, `Config\Load\Action`, `Schemas\Load\Action`, `SchemaParser`.
- Produces: зелёный сквозной сценарий и актуальная база знаний.

- [ ] **Step 1: Фикстуры**

`tests/Fixtures/Projects/petstore/dto-generator.yaml`:

```yaml
version: 1
target:
  php: 8.2
sources:
  - spec: api/openapi.yaml
    namespace: App\Dto
    outputDir: src/Dto
```

`tests/Fixtures/Projects/petstore/api/openapi.yaml`:

```yaml
openapi: 3.1.0
info:
  title: Petstore
  version: 1.0.0
paths: {}
components:
  schemas:
    Pet:
      type: object
      required: [id, name]
      properties:
        id:
          type: integer
          format: int64
        name:
          type: string
          minLength: 1
        tag:
          $ref: '#/components/schemas/Tag'
        price:
          $ref: 'shared/common.json#/definitions/Money'
    Tag:
      type: object
      properties:
        label:
          type: [string, 'null']
```

`tests/Fixtures/Projects/petstore/api/shared/common.json`:

```json
{
    "definitions": {
        "Money": {
            "type": "object",
            "required": ["amount", "currency"],
            "properties": {
                "amount": {"type": "string"},
                "currency": {"$ref": "#/definitions/Currency"}
            }
        },
        "Currency": {"type": "string", "enum": ["EUR", "USD"]}
    }
}
```

- [ ] **Step 2: Написать тест**

`tests/Integration/PetstoreLoadingTest.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Integration;

use MSSTC4PHP\DtoGenerator\Application\Config\ConfigFactory;
use MSSTC4PHP\DtoGenerator\Application\Config\TargetResolver;
use MSSTC4PHP\DtoGenerator\Application\Service\Config\Load\Action as LoadConfig;
use MSSTC4PHP\DtoGenerator\Application\Service\Config\Load\Input as ConfigInput;
use MSSTC4PHP\DtoGenerator\Application\Service\Schemas\Load\Action as LoadSchemas;
use MSSTC4PHP\DtoGenerator\Application\Service\Schemas\Load\Input as SchemasInput;
use MSSTC4PHP\DtoGenerator\Domain\Builder\SchemaParser;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostic;
use MSSTC4PHP\DtoGenerator\Domain\Schema\ResolvedSchema;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Path;
use MSSTC4PHP\DtoGenerator\Infrastructure\Document\FileDocumentLoader;
use MSSTC4PHP\DtoGenerator\Infrastructure\Environment\ComposerJsonPhpConstraint;
use PHPUnit\Framework\TestCase;

final class PetstoreLoadingTest extends TestCase
{
    public function testLoadsTheProjectEndToEnd(): void
    {
        $loader = new FileDocumentLoader();
        $configPath = Path::normalize(__DIR__ . '/../Fixtures/Projects/petstore/dto-generator.yaml');

        $loaded = (new LoadConfig($loader, new ConfigFactory(), new TargetResolver(new ComposerJsonPhpConstraint())))(new ConfigInput($configPath));
        self::assertSame([], array_map(static fn (Diagnostic $d): string => $d->toString(), $loaded->diagnostics()->all()));
        $config = $loaded->config();
        $target = $loaded->target();
        self::assertNotNull($config);
        self::assertNotNull($target);
        self::assertSame('8.2', $target->php()->toString());

        $schemas = (new LoadSchemas($loader, new SchemaParser()))(new SchemasInput($config));

        self::assertSame([], array_map(static fn (Diagnostic $d): string => $d->toString(), $schemas->diagnostics()->all()));
        self::assertSame(
            [['Pet', 0, true], ['Tag', 0, true], ['Money', 0, false], ['Currency', 0, false]],
            array_map(
                static fn (ResolvedSchema $schema): array => [$schema->name(), $schema->source(), $schema->isSelected()],
                $schemas->graph()->all(),
            ),
        );

        $pet = $schemas->graph()->all()[0];
        $price = $pet->schema()->property('price');
        self::assertNotNull($price);
        self::assertNotNull($price->ref());
        $money = $schemas->graph()->resolve($price->ref(), $price->location());
        self::assertNotNull($money);
        self::assertStringEndsWith('api/shared/common.json#/definitions/Money', $money->location()->toString());
    }
}
```

- [ ] **Step 3: Прогнать**

Run: `vendor/bin/phpunit --testsuite integration`
Expected: PASS.

- [ ] **Step 4: Полный гейт и мутации**

Run: `make fix && make verify && make infection`
Expected:
- всё зелёное, MSI ≥ 99 %;
- выжившие мутанты либо убиваются новыми тестами в тест-классах соответствующих задач, либо эквивалентны;
- эквивалентные заносятся точечно, по методу, в `infection.json5` с причиной (`(string) substr(...)` в `Path`, `JsonPointer`, `Reference` — по образцу `ClassName::fromFqcn`).

- [ ] **Step 5: Документация**

В `README.md` заменить строку статуса:

```markdown
> **Статус:** в разработке. Готовы этапы 1–2a: доменная модель, загрузка конфига (`dto-generator.yaml`), YAML/JSON-спецификаций и граф `$ref` с диагностикой.
> Построение IR, генерация файлов, CLI, Composer-плагин и Docker-образ появятся в следующих этапах.
```

`.claude/docs/integrations.md`:

```markdown
# Интеграции

| Что | Порт (Application) | Адаптер (Infrastructure) | Заметки |
|---|---|---|---|
| Файлы спецификаций и конфига | `Port\DocumentLoader` | `Document\FileDocumentLoader` | `.json` — `json_decode` с `JSON_THROW_ON_ERROR`; `.yaml/.yml` — `symfony/yaml` (на 7.4 ставится 5.4). Идентичность документа — лексически нормализованный **запрошенный** путь; кеш разобранного — по `realpath`. |
| `require.php` проекта | `Port\ProjectPhpConstraint` | `Environment\ComposerJsonPhpConstraint` | Ближайший `composer.json` вверх от каталога конфига; поиск останавливается на нём, даже если `php` там нет. |

Тестовые двойники: `tests/Support/InMemoryDocumentLoader`, `tests/Support/FixedPhpConstraint`.
```

В `.claude/docs/README.md` добавить строку таблицы:

```markdown
| [integrations.md](integrations.md) | Порты, адаптеры, внешние библиотеки |
```

В `.claude/docs/architecture.md` заменить абзац «Состояние на …» на:

```markdown
Состояние на 2026-10-01 (UTC): этапы 1 и 2a. Реализованы Domain, `Domain/Builder/SchemaParser`, Application (`Config/*`, use-case'ы `Service/Config/Load` и `Service/Schemas/Load`, порты `DocumentLoader`, `ProjectPhpConstraint`) и Infrastructure (`Document/FileDocumentLoader`, `Environment/ComposerJsonPhpConstraint`).

Поток этапа 2a: `Config/Load` (файл → `GeneratorConfig` → `TargetResolver` → `TargetProfile`) → `Schemas/Load` (спецификации источников → выбранные `components/schemas` → обход `$ref` до замыкания → `SchemaGraph`). Ошибки ввода не бросаются, а копятся в `Diagnostics`.
```

В `.claude/docs/domain-model.md` добавить раздел:

```markdown
## Диагностика и граф (этап 2a)
- `Diagnostics` — изменяемый сборщик (collecting parameter); `Diagnostic` = severity + сообщение + `SchemaLocation|null`. Ошибки конфига адресуются тем же `SchemaLocation` (файл конфига + JSON pointer).
- `SchemaParser` всегда возвращает `Schema`; неверный keyword → диагностика и пропуск.
- `Reference::target()` — `$ref` → `SchemaLocation` (лексически, без ФС); `null` для `scheme://`; якоря (`#Name`) не поддерживаются; фрагмент и путь проходят `rawurldecode`.
- `Discriminator` хранит mapping нормализованным: голое имя → `#/components/schemas/<имя>`.
- `SchemaGraph` — ключ `file#pointer`; `ResolvedSchema.source` — индекс источника-владельца; файл вне всех источников наследует владельца от ссылающихся, двое и более → ошибка неоднозначного namespace. `selected=false` — схема нужна только как цель ссылки (генерировать её всё равно придётся, иначе ссылка повиснет — решение этапа 2b).
- `Capability::RESERVED_NAMESPACE_SEGMENTS` (8.0): ниже — `TargetResolver` отклоняет токены PHP 7.4 в namespace источников и типах `formats`.
```

В `.claude/docs/known-issues.md` добавить пункты:

```markdown
- **YAML без кавычек.** `default: 2020-01-01` → int-timestamp (`symfony/yaml` без `PARSE_DATETIME`); `php: 8.2` → float (конфиг это обрабатывает). В спецификациях даты и версии нужно кавычить — Builder (этап 2b) должен предупреждать о int-default у `format: date/date-time`.
- **Объект с ключами `"0"`, `"1"`…** (например, `properties` с такими именами) неотличим от списка после декодирования и сообщается как «must be an object».
- **Пути лексические.** Symlink'и не раскрываются: один файл под двумя разными путями даст две схемы. `..` и `.` нормализуются.
- **Удалённые `$ref` и якоря** (`$anchor`, `#Name`) не поддерживаются — ошибка с подсказкой.
- **`target.php: auto`** берёт нижнюю границу `require.php` ближайшего `composer.json` (только PHP; версии пакетов — этап 6 / мост). Вне диапазона 7.4–8.5 — предупреждение и ближайшая поддерживаемая версия.
- **Ссылочные схемы с `selected=false`** всё равно должны генерироваться (иначе тип свойства повиснет) — этап 2b решает именование и namespace по `source`.
```

В `.claude/docs/conventions.md` добавить пункты:

```markdown
- Use-case'ы Application — вертикальные слайсы `Service/<Area>/<UseCase>/{Action,Input,Output}.php`; вспомогательные классы слайса лежат рядом (`Schemas/Load/GraphBuilder`).
- Ошибки пользовательского ввода (конфиг, спецификации) — только `Diagnostics` с `SchemaLocation`, без исключений; исключения — для нарушений инвариантов кода. Порт загрузки бросает `DocumentLoadFailed`, use-case превращает его в диагностику.
- Интеграционные тесты (`tests/Integration`, suite `integration`) работают с реальной ФС и фикстурами `tests/Fixtures`; модульные используют двойники из `tests/Support`.
```

- [ ] **Step 6: Финальный гейт и коммит**

Run: `make fix && make verify && make infection`
Expected: зелёное, MSI ≥ 99 %.

```bash
git add tests/Fixtures/Projects tests/Integration/PetstoreLoadingTest.php infection.json5 README.md .claude/docs
git commit -m "test: petstore end-to-end loading; docs for stage 2a

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

- [ ] **Step 7: Закрытие итерации по глобальным правилам**

`/acc:code-review high` по ветке (новый модуль). Исправить все находки, включая Suggestions. После нетривиальных исправлений — повторный ревью. Дополнить `.claude/docs/`.

---

## Следующий план

**2b — Builder: `SchemaGraph` + `TargetProfile` + `GeneratorConfig` → IR (`ClassModel`/`PropertyModel`).** Охват spec §5.1 (скаляры, `format`, `x-php-type`, `type: [T, null]`, массивы, `$ref`), §5.2 (обязательность и default), §5.4 (именование и коллизии), §5.5 (`description`/`deprecated`), словарь `x-` ядра (§7) без атрибутов. Композиция, enum, `additionalProperties` и инлайн-объекты — этап 4; до него они дают диагностику «not supported yet».
