# DTO Generator — этап 1: фундамент. План реализации

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Поднять репозиторий `msstc4php/dto-generator` с полным набором инструментов и реализовать доменный слой: разобранную модель JSON Schema (`Domain/Schema`), промежуточное представление (IR, `Domain/Model`) и `TargetProfile` с матрицей возможностей PHP (`Domain/Target`).

**Architecture:** На этом этапе — только слой Domain: неизменяемые объекты-значения без ввода-вывода и без зависимостей. Генератор работает на PHP 7.4, поэтому нативные enum заменены на `AbstractEnum`, который интернирует экземпляры: `===` означает равенство значений. Инструменты (PHPStan, CS-Fixer, Rector, deptrac, Infection) живут в `tools/` с отдельным `composer.json`, чтобы основной `require-dev` ставился на 7.4. Совместимость исходников с 7.4 проверяют `php -l` и PHPUnit в контейнере `php:7.4-cli`.

**Tech Stack:** PHP ≥ 7.4 (локально 8.4), PHPUnit 9.6, PHPStan 2 (max, strict-rules, phpstan-phpunit, disallowed-calls), PHP-CS-Fixer 3, Rector 2, Deptrac 3, Infection 0.29, Docker.

**Spec:** `docs/specs/2026-10-01-dto-generator-design.md` — этот план реализует §13 этап 1: каркас, инструменты, deptrac (§3, §11.2), Domain — Schema, IR, `TargetProfile` (§3, §5, §6.1). Загрузка документов, Builder, Emitter, конфиг, SPI, CLI — в следующих планах.

> **Примечание после ревью (2026-10-01 UTC):** API изменён по итогам code review — `withAttributes()` → `withAddedAttributes()`, `ArgumentValue::items()` → `listItems()`/`mapItems()`, `SchemaBuilder::defaultValue()` → `default()`, `TargetProfile::accessors()` → `configuredAccessors()`. Также `MapType` описывается как `array<array-key, T>` (решение от 2026-10-01 UTC, spec §5.1, сноска ¹). Код в задачах ниже — исторический снимок; актуальный API — в `src/` и `.claude/docs/`.

## Global Constraints

- Исходники и тесты должны парситься и работать на **PHP 7.4**. Запрещены: `enum`, `readonly`, атрибуты `#[...]`, `match`, union-типы в сигнатурах, promoted-свойства, именованные аргументы, `?->`, нативный тип `mixed`, возвращаемый тип `static`, функции 8.0+ (`str_contains`, `str_starts_with`, `str_ends_with`, `array_is_list`, `get_debug_type`).
- Разрешены возможности 7.4: типизированные свойства, стрелочные функции, `??=`, висячая запятая в вызовах.
- Namespace: `MSSTC4PHP\DtoGenerator\` → `src/`, `MSSTC4PHP\DtoGenerator\Tests\` → `tests/`.
- `composer.json`: `"php": ">=7.4"`, `config.platform.php = "7.4.33"`.
- PHPStan: `level: max`, `phpVersion: 70400`, без baseline. Новые ignore запрещены.
- PHPUnit 9.6: data provider'ы через аннотацию `@dataProvider`, методы провайдеров `public static`.
- Все классы `final`, кроме `AbstractEnum`. Объекты-значения неизменяемы, изменения — через `with*()`, которые возвращают новый экземпляр.
- Нарушение инварианта модели → `MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel` (наследник `\InvalidArgumentException`). Обращение к accessor'у не того вида → `\LogicException`.
- Комментарии в коде — на английском, минимальные (только «почему»). Документация — на русском.
- Даты — UTC.
- Каждый коммит заканчивается строкой `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- Завершение итерации по глобальным правилам: `make check` + `make test` + `make test-74` проходят. Затем `/acc:code-review` и обновление `.claude/docs/` (Task 9).

## Review Focus

1. **Числовые ключи.** Имя свойства `"200"` или значение discriminator `"1"`: PHP превращает такие ключи массива в `int`. Ожидается, что API возвращает строки `"200"` / `"1"`, а поиск по строке работает. Тесты — Task 5 (`Schema`), Task 4 (`Discriminator`), Task 8 (`DiscriminatorModel`, `ClassModel`).
2. **Зарезервированные слова в именах классов** (`App\Model\List`, `Object`, `App\Enum\Status`): `ClassName` отклоняет их с понятным сообщением, а не порождает непарсящийся PHP. Тест — Task 6.
3. **`/` и `~` в именах свойств** (`a/b`, `x~y`): JSON pointer экранируется как `~1` / `~0`, иначе диагностика укажет неверное место. Тест — Task 4.
4. **Нечисловые float** (`INF`, `NAN`) как литерал аргумента атрибута: отклоняются, у них нет PHP-литерала. Тест — Task 7.
5. **Строковое значение в int-enum** (`'1'` при `int`-backing) и дубли значений: отклоняются, иначе сгенерированный `enum` не скомпилируется. Тест — Task 8.

---

## Карта файлов

```
dto-generator/
├── composer.json                 # пакет, php >=7.4, phpunit ^9.6, platform 7.4.33
├── tools/composer.json           # phpstan, cs-fixer, rector, deptrac, infection
├── .gitignore  LICENSE  README.md  Makefile
├── phpunit.xml.dist  phpstan.dist.neon  .php-cs-fixer.dist.php  rector.php  deptrac.yaml  infection.json5
├── src/Domain/
│   ├── Exception/   InvalidModel, UnsupportedPhpVersion, IncompatibleTarget
│   ├── Shared/      AbstractEnum, Json (алиасы типов), DefaultValue
│   ├── Target/      PhpVersion, Capability, MetadataMode, Mutability, AccessorStyle, DateTimeClass, TargetProfile
│   ├── Schema/      SchemaLocation, SchemaType, Extensions, Discriminator, Schema, SchemaBuilder
│   └── Model/       Identifier, ClassName, DocModel,
│                    TypeModel + ScalarType, ClassType, ListType, MapType, UnionType, NullableType, MixedType,
│                    AttributeArgument, ArgumentValue, ImportAlias, AttributeModel,
│                    PropertyModel, ClassKind, DiscriminatorModel, ClassModel, EnumBacking, EnumCase, EnumModel
├── tests/Unit/Domain/...         # зеркалит src/
└── .claude/docs/                 # база знаний (Task 9)
```

---

### Task 1: Каркас репозитория, инструменты и `AbstractEnum`

**Files:**
- Create: `composer.json`, `tools/composer.json`, `.gitignore`, `LICENSE`, `phpunit.xml.dist`, `phpstan.dist.neon`, `.php-cs-fixer.dist.php`, `rector.php`, `deptrac.yaml`, `infection.json5`, `Makefile`
- Create: `src/Domain/Shared/AbstractEnum.php`
- Test: `tests/Unit/Domain/Shared/AbstractEnumTest.php`, `tests/Unit/Domain/Shared/Fixture/Colour.php`, `tests/Unit/Domain/Shared/Fixture/Shade.php`

**Interfaces:**
- Consumes: —
- Produces:
  - `abstract class AbstractEnum` с методами `static from(string $value): static`, `static tryFrom(string $value): ?static`, `static cases(): list<static>`, `value(): string`, `equals(AbstractEnum $other): bool`.
  - Абстрактный `protected static function values(): array` с типом `non-empty-list<non-empty-string>`.
  - Нативный возвращаемый тип — `self`, PHPDoc — `@return static`.

- [ ] **Step 1: Создать `composer.json`**

```json
{
    "name": "msstc4php/dto-generator",
    "description": "Generates immutable or mutable PHP DTO classes from OpenAPI 3.1 schemas, tailored to the target PHP version.",
    "type": "library",
    "license": "MIT",
    "keywords": [
        "openapi",
        "json-schema",
        "dto",
        "code-generator"
    ],
    "minimum-stability": "stable",
    "prefer-stable": true,
    "require": {
        "php": ">=7.4"
    },
    "require-dev": {
        "phpunit/phpunit": "^9.6"
    },
    "autoload": {
        "psr-4": {
            "MSSTC4PHP\\DtoGenerator\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "MSSTC4PHP\\DtoGenerator\\Tests\\": "tests/"
        }
    },
    "config": {
        "sort-packages": true,
        "platform": {
            "php": "7.4.33"
        }
    }
}
```

- [ ] **Step 2: Создать `tools/composer.json`**

```json
{
    "name": "msstc4php/dto-generator-tools",
    "description": "Dev tooling for msstc4php/dto-generator; kept apart so the package itself installs on PHP 7.4.",
    "type": "project",
    "license": "MIT",
    "require": {
        "deptrac/deptrac": "^3.0",
        "friendsofphp/php-cs-fixer": "^3.64",
        "infection/infection": "^0.29",
        "phpstan/extension-installer": "^1.4",
        "phpstan/phpstan": "^2.1",
        "phpstan/phpstan-phpunit": "^2.0",
        "phpstan/phpstan-strict-rules": "^2.0",
        "rector/rector": "^2.0",
        "spaze/phpstan-disallowed-calls": "^4.0"
    },
    "config": {
        "sort-packages": true,
        "allow-plugins": {
            "infection/extension-installer": true,
            "phpstan/extension-installer": true
        }
    }
}
```

- [ ] **Step 3: Создать `.gitignore` и `LICENSE`**

`.gitignore`:

```gitignore
/vendor/
/composer.lock
/tools/vendor/
/tools/composer.lock
/coverage/
/var/

/phpunit.xml
/.phpunit.cache/
.phpunit.result.cache

/phpstan.neon
/.php-cs-fixer.php
/.php-cs-fixer.cache
/.rector.cache
/.deptrac.cache

/.claude/settings.local.json
/.claude/**/*.tmp.*
```

`LICENSE`:

```text
MIT License

Copyright (c) 2026 Maxim Shamaev

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.
```

- [ ] **Step 4: Создать `phpunit.xml.dist`**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="vendor/autoload.php"
         cacheResultFile=".phpunit.cache/test-results"
         executionOrder="depends,defects"
         beStrictAboutOutputDuringTests="true"
         beStrictAboutTodoAnnotatedTests="true"
         convertDeprecationsToExceptions="true"
         failOnRisky="true"
         failOnWarning="true"
         colors="true">
    <testsuites>
        <testsuite name="unit">
            <directory>tests/Unit</directory>
        </testsuite>
    </testsuites>

    <coverage>
        <include>
            <directory suffix=".php">src</directory>
        </include>
    </coverage>
</phpunit>
```

- [ ] **Step 5: Создать `phpstan.dist.neon`**

```neon
parameters:
    level: max
    phpVersion: 70400
    treatPhpDocTypesAsCertain: false
    reportUnmatchedIgnoredErrors: true
    editorUrl: 'phpstorm://open?file=%%file%%&line=%%line%%'
    bootstrapFiles:
        - vendor/autoload.php
    paths:
        - src/
        - tests/
    disallowedFunctionCalls:
        -
            function:
                - 'var_dump()'
                - 'print_r()'
                - 'dump()'
                - 'dd()'
            message: 'debug artefact'
        -
            function: 'error_log()'
            message: 'the library reports through diagnostics, not the PHP error log'
```

- [ ] **Step 6: Создать `.php-cs-fixer.dist.php`**

```php
<?php declare(strict_types=1);

$finder = (new PhpCsFixer\Finder())
    ->in(__DIR__ . '/src')
    ->in(__DIR__ . '/tests')
    ->append([__FILE__, __DIR__ . '/rector.php'])
;

return (new PhpCsFixer\Config())
    ->setRules([
        '@Symfony' => true,
        '@PHP74Migration' => true,
        '@PHP74Migration:risky' => true,
        'declare_strict_types' => true,
        'phpdoc_align' => ['align' => 'left'],
        'method_argument_space' => ['on_multiline' => 'ensure_fully_multiline'],
        'phpdoc_to_comment' => false,
        'linebreak_after_opening_tag' => false,
        'blank_line_after_opening_tag' => false,
        'concat_space' => ['spacing' => 'one'],
        'increment_style' => ['style' => 'post'],
        'yoda_style' => ['equal' => false, 'identical' => false, 'less_and_greater' => false],
        'class_attributes_separation' => true,
        // Trailing commas in parameter lists are PHP 8.0 syntax; the package runs on 7.4.
        'trailing_comma_in_multiline' => ['elements' => ['arguments', 'arrays']],
        'multiline_whitespace_before_semicolons' => ['strategy' => 'new_line_for_chained_calls'],
        'global_namespace_import' => ['import_classes' => true],
        'blank_line_before_statement' => ['statements' => ['declare', 'return']],
    ])
    ->setFinder($finder)
    ->setRiskyAllowed(true)
;
```

- [ ] **Step 7: Создать `rector.php`**

```php
<?php

declare(strict_types=1);

use Rector\CodingStyle\Rector\Catch_\CatchExceptionNameMatchingTypeRector;
use Rector\CodingStyle\Rector\PostInc\PostIncDecToPreIncDecRector;
use Rector\CodingStyle\Rector\Stmt\NewlineAfterStatementRector;
use Rector\Config\RectorConfig;
use Rector\EarlyReturn\Rector\If_\ChangeOrIfContinueToMultiContinueRector;
use Rector\EarlyReturn\Rector\Return_\ReturnBinaryOrToEarlyReturnRector;
use Rector\Strict\Rector\Empty_\DisallowedEmptyRuleFixerRector;
use Rector\ValueObject\PhpVersion;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->withAutoloadPaths([__DIR__ . '/vendor/autoload.php'])
    ->withoutParallel()
    // The package runs on 7.4: Rector must never upgrade sources past it.
    ->withPhpVersion(PhpVersion::PHP_74)
    ->withPhpSets(php74: true)
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        codingStyle: true,
        typeDeclarations: true,
        privatization: true,
        instanceOf: true,
        earlyReturn: true,
    )
    ->withImportNames(removeUnusedImports: true)
    ->withSkip([
        ChangeOrIfContinueToMultiContinueRector::class,
        ReturnBinaryOrToEarlyReturnRector::class,
        PostIncDecToPreIncDecRector::class,
        DisallowedEmptyRuleFixerRector::class,
        NewlineAfterStatementRector::class,
        CatchExceptionNameMatchingTypeRector::class,
    ]);
```

- [ ] **Step 8: Создать `deptrac.yaml`**

```yaml
deptrac:
  paths:
    - ./src
  layers:
    - name: Domain
      collectors:
        - type: directory
          value: src/Domain/(Schema|Model|Target|Shared|Exception)/.*
    - name: DomainService
      collectors:
        - type: directory
          value: src/Domain/Builder/.*
    - name: Contract
      collectors:
        - type: directory
          value: src/Contract/.*
    - name: Application
      collectors:
        - type: directory
          value: src/Application/.*
    - name: Infrastructure
      collectors:
        - type: directory
          value: src/Infrastructure/.*
    - name: Presentation
      collectors:
        - type: directory
          value: src/Presentation/.*
    - name: Extension
      collectors:
        - type: directory
          value: src/Extension/.*
    - name: Symfony
      collectors:
        - type: classNameRegex
          value: '#^Symfony\\#'
    - name: PhpParser
      collectors:
        - type: classNameRegex
          value: '#^PhpParser\\#'
    - name: Composer
      collectors:
        - type: classNameRegex
          value: '#^Composer\\#'
  ruleset:
    Domain: ~
    DomainService: [Domain]
    Contract: [Domain]
    Application: [Domain, DomainService, Contract]
    Infrastructure: [Domain, Application, Contract, Symfony, PhpParser, Composer]
    Presentation: [Application, Domain, Contract, Symfony, Composer]
    Extension: [Contract, Domain]
    Symfony: ~
    PhpParser: ~
    Composer: ~
```

- [ ] **Step 9: Создать `infection.json5`**

```json5
{
    "$schema": "tools/vendor/infection/infection/resources/schema.json",
    "source": {
        "directories": ["src"]
    },
    "timeout": 10,
    // Raised to just below the measured level in Task 9.
    "minMsi": 0,
    "minCoveredMsi": 0,
    "logs": {
        "text": "var/infection/infection.log",
        "json": "var/infection/infection.json"
    },
    "mutators": {
        "@default": true
    },
    "phpUnit": {
        "customPath": "vendor/bin/phpunit"
    },
    "testFramework": "phpunit",
    "testFrameworkOptions": "--testsuite=unit"
}
```

- [ ] **Step 10: Создать `Makefile`**

```makefile
TOOLS := tools/vendor/bin
PHP74 := docker run --rm --user "$$(id -u):$$(id -g)" -v "$(CURDIR):/app" -w /app php:7.4-cli

install: ## Install package and tool dependencies
	composer install
	composer install --working-dir=tools

check: ## Static checks (incl. PHP 7.4 syntax lint)
	find ./src ./tests -name '*.php' -print0 | xargs -0 -r -n1 php -l > /dev/null
	$(TOOLS)/phpstan analyse --memory-limit=512M -c phpstan.dist.neon
	$(TOOLS)/php-cs-fixer check
	composer validate --strict --no-check-publish
	$(TOOLS)/rector process -n
	$(TOOLS)/deptrac analyse --config-file=deptrac.yaml --no-progress
	$(MAKE) lint-74

lint-74: ## Lint sources with the PHP 7.4 parser
	$(PHP74) sh -c "find src tests -name '*.php' -print0 | xargs -0 -r -n1 php -l > /dev/null"

test: ## Run tests on the local PHP
	vendor/bin/phpunit

test-74: ## Run tests on PHP 7.4
	$(PHP74) vendor/bin/phpunit

infection: ## Mutation testing
	XDEBUG_MODE=coverage $(TOOLS)/infection --threads=$(shell nproc) --no-interaction

fix: ## Apply code style and Rector
	$(TOOLS)/php-cs-fixer fix
	$(TOOLS)/rector process

help: ## List commands
	@grep -E '^[a-zA-Z_0-9-]+:.*?## ' Makefile | awk 'BEGIN {FS = ":.*?## "}; {printf "%-12s %s\n", $$1, $$2}'

.DEFAULT_GOAL := help
.PHONY: install check lint-74 test test-74 infection fix help
```

- [ ] **Step 11: Установить зависимости**

Run: `make install`
Expected: обе установки завершаются без ошибок, в `vendor/` есть PHPUnit 9.6.x (`vendor/bin/phpunit --version` → `PHPUnit 9.6.`).

- [ ] **Step 12: Написать фикстуры и падающий тест**

`tests/Unit/Domain/Shared/Fixture/Colour.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Domain\Shared\Fixture;

use MSSTC4PHP\DtoGenerator\Domain\Shared\AbstractEnum;

final class Colour extends AbstractEnum
{
    public const RED = 'red';
    public const GREEN = 'green';

    protected static function values(): array
    {
        return [self::RED, self::GREEN];
    }
}
```

`tests/Unit/Domain/Shared/Fixture/Shade.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Domain\Shared\Fixture;

use MSSTC4PHP\DtoGenerator\Domain\Shared\AbstractEnum;

final class Shade extends AbstractEnum
{
    public const RED = 'red';

    protected static function values(): array
    {
        return [self::RED];
    }
}
```

`tests/Unit/Domain/Shared/AbstractEnumTest.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Domain\Shared;

use InvalidArgumentException;
use MSSTC4PHP\DtoGenerator\Tests\Unit\Domain\Shared\Fixture\Colour;
use MSSTC4PHP\DtoGenerator\Tests\Unit\Domain\Shared\Fixture\Shade;
use PHPUnit\Framework\TestCase;

final class AbstractEnumTest extends TestCase
{
    public function testFromReturnsTheSameInstanceForTheSameValue(): void
    {
        self::assertSame(Colour::from(Colour::RED), Colour::from(Colour::RED));
    }

    public function testValueRoundTrips(): void
    {
        self::assertSame('green', Colour::from(Colour::GREEN)->value());
    }

    public function testEqualsComparesValues(): void
    {
        self::assertTrue(Colour::from(Colour::RED)->equals(Colour::from(Colour::RED)));
        self::assertFalse(Colour::from(Colour::RED)->equals(Colour::from(Colour::GREEN)));
    }

    public function testFromRejectsAnUnknownValue(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"blue" is not a valid');

        Colour::from('blue');
    }

    public function testTryFromReturnsNullForAnUnknownValue(): void
    {
        self::assertNull(Colour::tryFrom('blue'));
    }

    public function testInstancesAreSeparatedPerClass(): void
    {
        $shade = Shade::from(Shade::RED);

        self::assertInstanceOf(Shade::class, $shade);
        self::assertFalse($shade->equals(Colour::from(Colour::RED)));
    }

    public function testCasesListsEveryValueInDeclarationOrder(): void
    {
        self::assertSame(
            ['red', 'green'],
            array_map(static fn (Colour $colour): string => $colour->value(), Colour::cases()),
        );
    }
}
```

- [ ] **Step 13: Убедиться, что тест падает**

Run: `vendor/bin/phpunit --filter AbstractEnumTest`
Expected: FAIL — `Class "MSSTC4PHP\DtoGenerator\Domain\Shared\AbstractEnum" not found`.

- [ ] **Step 14: Реализовать `AbstractEnum`**

`src/Domain/Shared/AbstractEnum.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Shared;

use InvalidArgumentException;
use LogicException;

/**
 * Stand-in for native enums, which need PHP 8.1 while the generator runs on 7.4.
 * Instances are interned per class and value, so `===` is value equality.
 */
abstract class AbstractEnum
{
    /** @var array<class-string<self>, array<string, self>> */
    private static array $instances = [];

    private string $value;

    final protected function __construct(string $value)
    {
        $this->value = $value;
    }

    private function __clone()
    {
    }

    /**
     * @return static
     */
    public static function from(string $value): self
    {
        $instance = static::tryFrom($value);
        if ($instance === null) {
            throw new InvalidArgumentException(sprintf(
                '"%s" is not a valid %s value; expected one of: %s.',
                $value,
                static::class,
                implode(', ', static::values()),
            ));
        }

        return $instance;
    }

    /**
     * @return static|null
     */
    public static function tryFrom(string $value): ?self
    {
        if (!in_array($value, static::values(), true)) {
            return null;
        }

        if (!isset(self::$instances[static::class][$value])) {
            self::$instances[static::class][$value] = new static($value);
        }

        $instance = self::$instances[static::class][$value];
        if (!($instance instanceof static)) {
            throw new LogicException(sprintf('Enum cache for %s holds a foreign instance.', static::class));
        }

        return $instance;
    }

    /**
     * @return list<static>
     */
    public static function cases(): array
    {
        $cases = [];
        foreach (static::values() as $value) {
            $cases[] = static::from($value);
        }

        return $cases;
    }

    public function value(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this === $other;
    }

    /**
     * @return non-empty-list<non-empty-string>
     */
    abstract protected static function values(): array;
}
```

- [ ] **Step 15: Прогнать тесты на 8.4 и 7.4**

Run: `vendor/bin/phpunit --filter AbstractEnumTest && make test-74`
Expected: PASS — 7 тестов в обоих прогонах.

- [ ] **Step 16: Полная статическая проверка**

Run: `make fix && make check`
Expected: все шаги проходят: `[OK] No errors` у PHPStan, deptrac без нарушений, `lint-74` без вывода. Если `make fix` что-то поменял, ещё раз прогоните `vendor/bin/phpunit`.

- [ ] **Step 17: Commit**

```bash
git add composer.json tools/composer.json .gitignore LICENSE phpunit.xml.dist phpstan.dist.neon .php-cs-fixer.dist.php rector.php deptrac.yaml infection.json5 Makefile src tests
git commit -m "chore: scaffold package, tooling and AbstractEnum

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: `PhpVersion` и `Capability`

**Files:**
- Create: `src/Domain/Exception/UnsupportedPhpVersion.php`, `src/Domain/Target/PhpVersion.php`, `src/Domain/Target/Capability.php`
- Test: `tests/Unit/Domain/Target/PhpVersionTest.php`, `tests/Unit/Domain/Target/CapabilityTest.php`

**Interfaces:**
- Consumes: `AbstractEnum` (Task 1).
- Produces:
  - `PhpVersion`:
    - `static fromString(string): PhpVersion` — принимает `X.Y` и `X.Y.Z`; иначе бросает `UnsupportedPhpVersion`;
    - `static oldest(): PhpVersion` (7.4), `static newest(): PhpVersion` (8.5), `static supported(): non-empty-list<PhpVersion>`;
    - `major(): int`, `minor(): int`, `id(): int` (80200), `isAtLeast(PhpVersion): bool`, `equals(PhpVersion): bool`, `toString(): string`.
  - `Capability extends AbstractEnum`:
    - константы `TYPED_PROPERTIES`, `CONSTRUCTOR_PROMOTION`, `UNION_TYPES`, `ATTRIBUTES`, `MIXED_TYPE`, `READONLY_PROPERTIES`, `ENUMS`, `NEW_IN_INITIALIZERS`, `READONLY_CLASSES`, `STANDALONE_NULL_FALSE`, `TYPED_CLASS_CONSTANTS`, `ASYMMETRIC_VISIBILITY`, `PROPERTY_HOOKS`, `CLONE_WITH`;
    - `minimumVersion(): PhpVersion`.

- [ ] **Step 1: Написать падающие тесты**

`tests/Unit/Domain/Target/PhpVersionTest.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Domain\Target;

use MSSTC4PHP\DtoGenerator\Domain\Exception\UnsupportedPhpVersion;
use MSSTC4PHP\DtoGenerator\Domain\Target\PhpVersion;
use PHPUnit\Framework\TestCase;

final class PhpVersionTest extends TestCase
{
    /**
     * @dataProvider validVersions
     */
    public function testParsesSupportedVersions(string $input, int $major, int $minor, int $id): void
    {
        $version = PhpVersion::fromString($input);

        self::assertSame($major, $version->major());
        self::assertSame($minor, $version->minor());
        self::assertSame($id, $version->id());
        self::assertSame($major . '.' . $minor, $version->toString());
    }

    /**
     * @return array<string, array{string, int, int, int}>
     */
    public static function validVersions(): array
    {
        return [
            'oldest' => ['7.4', 7, 4, 70400],
            'newest' => ['8.5', 8, 5, 80500],
            'patch is ignored' => ['8.2.15', 8, 2, 80200],
        ];
    }

    /**
     * @dataProvider malformedVersions
     */
    public function testRejectsMalformedInput(string $input): void
    {
        $this->expectException(UnsupportedPhpVersion::class);
        $this->expectExceptionMessage('is not a PHP version');

        PhpVersion::fromString($input);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function malformedVersions(): array
    {
        return [
            'empty' => [''],
            'major only' => ['8'],
            'letters' => ['8.x'],
            'prefix' => ['v8.2'],
            'composer constraint' => ['^8.1'],
            'comparison constraint' => ['>=7.4'],
            'leading zero' => ['08.2'],
            'four parts' => ['8.2.1.0'],
        ];
    }

    /**
     * @dataProvider unsupportedVersions
     */
    public function testRejectsUnsupportedVersions(string $input): void
    {
        $this->expectException(UnsupportedPhpVersion::class);
        $this->expectExceptionMessage('is not supported');

        PhpVersion::fromString($input);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unsupportedVersions(): array
    {
        return [
            'too old' => ['7.3'],
            'ancient' => ['5.6'],
            'too new minor' => ['8.6'],
            'too new major' => ['9.0'],
        ];
    }

    public function testComparesVersions(): void
    {
        $php80 = PhpVersion::fromString('8.0');
        $php81 = PhpVersion::fromString('8.1');

        self::assertTrue($php81->isAtLeast($php80));
        self::assertTrue($php81->isAtLeast(PhpVersion::fromString('8.1.3')));
        self::assertFalse($php80->isAtLeast($php81));
        self::assertTrue($php81->equals(PhpVersion::fromString('8.1.9')));
        self::assertFalse($php81->equals($php80));
    }

    public function testExposesTheSupportedRange(): void
    {
        self::assertSame('7.4', PhpVersion::oldest()->toString());
        self::assertSame('8.5', PhpVersion::newest()->toString());
        self::assertSame(
            ['7.4', '8.0', '8.1', '8.2', '8.3', '8.4', '8.5'],
            array_map(static fn (PhpVersion $version): string => $version->toString(), PhpVersion::supported()),
        );
    }
}
```

`tests/Unit/Domain/Target/CapabilityTest.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Domain\Target;

use MSSTC4PHP\DtoGenerator\Domain\Target\Capability;
use PHPUnit\Framework\TestCase;

final class CapabilityTest extends TestCase
{
    /**
     * @dataProvider minimumVersions
     */
    public function testKnowsTheVersionThatIntroducedIt(string $capability, string $version): void
    {
        self::assertSame($version, Capability::from($capability)->minimumVersion()->toString());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function minimumVersions(): array
    {
        return [
            Capability::TYPED_PROPERTIES => [Capability::TYPED_PROPERTIES, '7.4'],
            Capability::CONSTRUCTOR_PROMOTION => [Capability::CONSTRUCTOR_PROMOTION, '8.0'],
            Capability::UNION_TYPES => [Capability::UNION_TYPES, '8.0'],
            Capability::ATTRIBUTES => [Capability::ATTRIBUTES, '8.0'],
            Capability::MIXED_TYPE => [Capability::MIXED_TYPE, '8.0'],
            Capability::READONLY_PROPERTIES => [Capability::READONLY_PROPERTIES, '8.1'],
            Capability::ENUMS => [Capability::ENUMS, '8.1'],
            Capability::NEW_IN_INITIALIZERS => [Capability::NEW_IN_INITIALIZERS, '8.1'],
            Capability::READONLY_CLASSES => [Capability::READONLY_CLASSES, '8.2'],
            Capability::STANDALONE_NULL_FALSE => [Capability::STANDALONE_NULL_FALSE, '8.2'],
            Capability::TYPED_CLASS_CONSTANTS => [Capability::TYPED_CLASS_CONSTANTS, '8.3'],
            Capability::ASYMMETRIC_VISIBILITY => [Capability::ASYMMETRIC_VISIBILITY, '8.4'],
            Capability::PROPERTY_HOOKS => [Capability::PROPERTY_HOOKS, '8.4'],
            Capability::CLONE_WITH => [Capability::CLONE_WITH, '8.5'],
        ];
    }

    public function testTheMatrixCoversEveryCapability(): void
    {
        self::assertCount(count(self::minimumVersions()), Capability::cases());
    }
}
```

- [ ] **Step 2: Убедиться, что тесты падают**

Run: `vendor/bin/phpunit --filter 'PhpVersionTest|CapabilityTest'`
Expected: FAIL — `Class "MSSTC4PHP\DtoGenerator\Domain\Target\PhpVersion" not found`.

- [ ] **Step 3: Реализовать**

`src/Domain/Exception/UnsupportedPhpVersion.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Exception;

use InvalidArgumentException;

final class UnsupportedPhpVersion extends InvalidArgumentException
{
    public static function malformed(string $version): self
    {
        return new self(sprintf('"%s" is not a PHP version; expected "<major>.<minor>" such as "8.2".', $version));
    }

    /**
     * @param list<string> $supported
     */
    public static function notSupported(string $version, array $supported): self
    {
        return new self(sprintf('PHP %s is not supported; supported versions: %s.', $version, implode(', ', $supported)));
    }
}
```

`src/Domain/Target/PhpVersion.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Target;

use MSSTC4PHP\DtoGenerator\Domain\Exception\UnsupportedPhpVersion;

final class PhpVersion
{
    private const SUPPORTED = ['7.4', '8.0', '8.1', '8.2', '8.3', '8.4', '8.5'];

    private int $major;

    private int $minor;

    private function __construct(int $major, int $minor)
    {
        $this->major = $major;
        $this->minor = $minor;
    }

    public static function fromString(string $version): self
    {
        if (preg_match('/^(0|[1-9]\d*)\.(0|[1-9]\d*)(?:\.(?:0|[1-9]\d*))?$/', $version, $matches) !== 1) {
            throw UnsupportedPhpVersion::malformed($version);
        }

        if (!in_array($matches[1] . '.' . $matches[2], self::SUPPORTED, true)) {
            throw UnsupportedPhpVersion::notSupported($version, self::SUPPORTED);
        }

        return new self((int) $matches[1], (int) $matches[2]);
    }

    public static function oldest(): self
    {
        return self::supported()[0];
    }

    public static function newest(): self
    {
        $supported = self::supported();

        return $supported[count($supported) - 1];
    }

    /**
     * @return non-empty-list<self>
     */
    public static function supported(): array
    {
        return array_map(static fn (string $version): self => self::fromString($version), self::SUPPORTED);
    }

    public function major(): int
    {
        return $this->major;
    }

    public function minor(): int
    {
        return $this->minor;
    }

    /**
     * Same encoding as PHP_VERSION_ID with the patch part zeroed.
     */
    public function id(): int
    {
        return $this->major * 10000 + $this->minor * 100;
    }

    public function isAtLeast(self $other): bool
    {
        return $this->id() >= $other->id();
    }

    public function equals(self $other): bool
    {
        return $this->id() === $other->id();
    }

    public function toString(): string
    {
        return $this->major . '.' . $this->minor;
    }
}
```

`src/Domain/Target/Capability.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Target;

use LogicException;
use MSSTC4PHP\DtoGenerator\Domain\Shared\AbstractEnum;

final class Capability extends AbstractEnum
{
    public const TYPED_PROPERTIES = 'typed-properties';
    public const CONSTRUCTOR_PROMOTION = 'constructor-promotion';
    public const UNION_TYPES = 'union-types';
    public const ATTRIBUTES = 'attributes';
    public const MIXED_TYPE = 'mixed-type';
    public const READONLY_PROPERTIES = 'readonly-properties';
    public const ENUMS = 'enums';
    public const NEW_IN_INITIALIZERS = 'new-in-initializers';
    public const READONLY_CLASSES = 'readonly-classes';
    public const STANDALONE_NULL_FALSE = 'standalone-null-false';
    public const TYPED_CLASS_CONSTANTS = 'typed-class-constants';
    public const ASYMMETRIC_VISIBILITY = 'asymmetric-visibility';
    public const PROPERTY_HOOKS = 'property-hooks';
    public const CLONE_WITH = 'clone-with';

    private const MINIMUM_VERSION = [
        self::TYPED_PROPERTIES => '7.4',
        self::CONSTRUCTOR_PROMOTION => '8.0',
        self::UNION_TYPES => '8.0',
        self::ATTRIBUTES => '8.0',
        self::MIXED_TYPE => '8.0',
        self::READONLY_PROPERTIES => '8.1',
        self::ENUMS => '8.1',
        self::NEW_IN_INITIALIZERS => '8.1',
        self::READONLY_CLASSES => '8.2',
        self::STANDALONE_NULL_FALSE => '8.2',
        self::TYPED_CLASS_CONSTANTS => '8.3',
        self::ASYMMETRIC_VISIBILITY => '8.4',
        self::PROPERTY_HOOKS => '8.4',
        self::CLONE_WITH => '8.5',
    ];

    public function minimumVersion(): PhpVersion
    {
        $version = self::MINIMUM_VERSION[$this->value()] ?? null;
        if ($version === null) {
            throw new LogicException(sprintf('Capability "%s" has no minimum PHP version.', $this->value()));
        }

        return PhpVersion::fromString($version);
    }

    protected static function values(): array
    {
        return array_keys(self::MINIMUM_VERSION);
    }
}
```

- [ ] **Step 4: Прогнать тесты**

Run: `vendor/bin/phpunit --filter 'PhpVersionTest|CapabilityTest' && make test-74`
Expected: PASS.

- [ ] **Step 5: Статическая проверка**

Run: `make fix && make check`
Expected: без ошибок.

- [ ] **Step 6: Commit**

```bash
git add src/Domain/Exception/UnsupportedPhpVersion.php src/Domain/Target tests/Unit/Domain/Target
git commit -m "feat(target): PhpVersion and the capability matrix

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: Режимы генерации и `TargetProfile`

**Files:**
- Create: `src/Domain/Exception/IncompatibleTarget.php`, `src/Domain/Target/MetadataMode.php`, `src/Domain/Target/Mutability.php`, `src/Domain/Target/AccessorStyle.php`, `src/Domain/Target/DateTimeClass.php`, `src/Domain/Target/TargetProfile.php`
- Test: `tests/Unit/Domain/Target/TargetProfileTest.php`, `tests/Unit/Domain/Target/ModesTest.php`

**Interfaces:**
- Consumes: `PhpVersion`, `Capability` (Task 2), `AbstractEnum` (Task 1).
- Produces:
  - `MetadataMode` (`ATTRIBUTES` / `ANNOTATIONS` / `NONE`), `static defaultFor(PhpVersion): MetadataMode`.
  - `Mutability` (`IMMUTABLE` / `MUTABLE`), `isImmutable(): bool`.
  - `AccessorStyle` (`AUTO` / `GETTERS` / `PUBLIC_PROPERTIES` = `'public-properties'`).
  - `DateTimeClass` (`IMMUTABLE` = `'DateTimeImmutable'` / `MUTABLE` = `'DateTime'`), `className(): class-string<\DateTimeInterface>`.
  - `TargetProfile::__construct(PhpVersion $php, MetadataMode $metadata, Mutability $mutability, AccessorStyle $accessors, DateTimeClass $dateTimeClass, bool $strict)`.
  - Методы `TargetProfile`: `supports(Capability): bool`, `accessorsFor(Mutability): AccessorStyle` (никогда не возвращает `AUTO`); геттеры `php()`, `metadata()`, `mutability()`, `accessors()`, `dateTimeClass()`, `isStrict()`.
  - `IncompatibleTarget::capabilityMissing(Capability, PhpVersion, string $feature): IncompatibleTarget`.

- [ ] **Step 1: Написать падающие тесты**

`tests/Unit/Domain/Target/ModesTest.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Domain\Target;

use DateTime;
use DateTimeImmutable;
use MSSTC4PHP\DtoGenerator\Domain\Target\DateTimeClass;
use MSSTC4PHP\DtoGenerator\Domain\Target\MetadataMode;
use MSSTC4PHP\DtoGenerator\Domain\Target\Mutability;
use MSSTC4PHP\DtoGenerator\Domain\Target\PhpVersion;
use PHPUnit\Framework\TestCase;

final class ModesTest extends TestCase
{
    public function testMetadataDefaultsToAnnotationsBeforePhp80(): void
    {
        self::assertSame(MetadataMode::from(MetadataMode::ANNOTATIONS), MetadataMode::defaultFor(PhpVersion::fromString('7.4')));
    }

    public function testMetadataDefaultsToAttributesFromPhp80(): void
    {
        self::assertSame(MetadataMode::from(MetadataMode::ATTRIBUTES), MetadataMode::defaultFor(PhpVersion::fromString('8.0')));
        self::assertSame(MetadataMode::from(MetadataMode::ATTRIBUTES), MetadataMode::defaultFor(PhpVersion::fromString('8.5')));
    }

    public function testMutabilityKnowsWhetherItIsImmutable(): void
    {
        self::assertTrue(Mutability::from(Mutability::IMMUTABLE)->isImmutable());
        self::assertFalse(Mutability::from(Mutability::MUTABLE)->isImmutable());
    }

    public function testDateTimeClassMapsToTheRealClass(): void
    {
        self::assertSame(DateTimeImmutable::class, DateTimeClass::from(DateTimeClass::IMMUTABLE)->className());
        self::assertSame(DateTime::class, DateTimeClass::from(DateTimeClass::MUTABLE)->className());
    }
}
```

`tests/Unit/Domain/Target/TargetProfileTest.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Domain\Target;

use MSSTC4PHP\DtoGenerator\Domain\Exception\IncompatibleTarget;
use MSSTC4PHP\DtoGenerator\Domain\Target\AccessorStyle;
use MSSTC4PHP\DtoGenerator\Domain\Target\Capability;
use MSSTC4PHP\DtoGenerator\Domain\Target\DateTimeClass;
use MSSTC4PHP\DtoGenerator\Domain\Target\MetadataMode;
use MSSTC4PHP\DtoGenerator\Domain\Target\Mutability;
use MSSTC4PHP\DtoGenerator\Domain\Target\PhpVersion;
use MSSTC4PHP\DtoGenerator\Domain\Target\TargetProfile;
use PHPUnit\Framework\TestCase;

final class TargetProfileTest extends TestCase
{
    public function testSupportsCapabilitiesUpToItsVersion(): void
    {
        $profile = self::profile('8.1');

        self::assertTrue($profile->supports(Capability::from(Capability::ENUMS)));
        self::assertTrue($profile->supports(Capability::from(Capability::TYPED_PROPERTIES)));
        self::assertFalse($profile->supports(Capability::from(Capability::READONLY_CLASSES)));
    }

    public function testRejectsAttributeMetadataOnPhp74(): void
    {
        $this->expectException(IncompatibleTarget::class);
        $this->expectExceptionMessage('Metadata mode "attributes" requires attributes (PHP 8.0+), but the target is PHP 7.4.');

        self::profile('7.4', MetadataMode::ATTRIBUTES);
    }

    public function testAllowsAnnotationsOnModernPhp(): void
    {
        self::assertSame(MetadataMode::from(MetadataMode::ANNOTATIONS), self::profile('8.2', MetadataMode::ANNOTATIONS)->metadata());
    }

    /**
     * @dataProvider autoAccessors
     */
    public function testResolvesAutoAccessors(string $php, string $mutability, string $expected): void
    {
        self::assertSame(
            AccessorStyle::from($expected),
            self::profile($php)->accessorsFor(Mutability::from($mutability)),
        );
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function autoAccessors(): array
    {
        return [
            'immutable on 8.1 uses readonly properties' => ['8.1', Mutability::IMMUTABLE, AccessorStyle::PUBLIC_PROPERTIES],
            'immutable on 8.0 needs getters' => ['8.0', Mutability::IMMUTABLE, AccessorStyle::GETTERS],
            'immutable on 7.4 needs getters' => ['7.4', Mutability::IMMUTABLE, AccessorStyle::GETTERS],
            'mutable always uses getters' => ['8.5', Mutability::MUTABLE, AccessorStyle::GETTERS],
        ];
    }

    public function testRejectsPublicPropertiesForImmutableDefaultsWithoutReadonly(): void
    {
        $this->expectException(IncompatibleTarget::class);
        $this->expectExceptionMessage('Immutable DTOs with public properties requires readonly-properties (PHP 8.1+), but the target is PHP 8.0.');

        self::profile('8.0', MetadataMode::NONE, Mutability::IMMUTABLE, AccessorStyle::PUBLIC_PROPERTIES);
    }

    public function testRejectsPublicPropertiesForAnImmutableOverrideWithoutReadonly(): void
    {
        $profile = self::profile('7.4', MetadataMode::NONE, Mutability::MUTABLE, AccessorStyle::PUBLIC_PROPERTIES);

        self::assertSame(AccessorStyle::from(AccessorStyle::PUBLIC_PROPERTIES), $profile->accessorsFor(Mutability::from(Mutability::MUTABLE)));

        $this->expectException(IncompatibleTarget::class);
        $profile->accessorsFor(Mutability::from(Mutability::IMMUTABLE));
    }

    public function testExplicitGettersAreKeptAsConfigured(): void
    {
        $profile = self::profile('8.4', MetadataMode::NONE, Mutability::IMMUTABLE, AccessorStyle::GETTERS);

        self::assertSame(AccessorStyle::from(AccessorStyle::GETTERS), $profile->accessorsFor(Mutability::from(Mutability::IMMUTABLE)));
    }

    public function testExposesItsSettings(): void
    {
        $profile = new TargetProfile(
            PhpVersion::fromString('8.2'),
            MetadataMode::from(MetadataMode::ATTRIBUTES),
            Mutability::from(Mutability::MUTABLE),
            AccessorStyle::from(AccessorStyle::AUTO),
            DateTimeClass::from(DateTimeClass::MUTABLE),
            false,
        );

        self::assertSame('8.2', $profile->php()->toString());
        self::assertSame(MetadataMode::from(MetadataMode::ATTRIBUTES), $profile->metadata());
        self::assertSame(Mutability::from(Mutability::MUTABLE), $profile->mutability());
        self::assertSame(AccessorStyle::from(AccessorStyle::AUTO), $profile->accessors());
        self::assertSame(DateTimeClass::from(DateTimeClass::MUTABLE), $profile->dateTimeClass());
        self::assertFalse($profile->isStrict());
    }

    private static function profile(
        string $php,
        string $metadata = MetadataMode::NONE,
        string $mutability = Mutability::IMMUTABLE,
        string $accessors = AccessorStyle::AUTO
    ): TargetProfile {
        return new TargetProfile(
            PhpVersion::fromString($php),
            MetadataMode::from($metadata),
            Mutability::from($mutability),
            AccessorStyle::from($accessors),
            DateTimeClass::from(DateTimeClass::IMMUTABLE),
            true,
        );
    }
}
```

- [ ] **Step 2: Убедиться, что тесты падают**

Run: `vendor/bin/phpunit --filter 'ModesTest|TargetProfileTest'`
Expected: FAIL — `Class "MSSTC4PHP\DtoGenerator\Domain\Target\MetadataMode" not found`.

- [ ] **Step 3: Реализовать**

`src/Domain/Exception/IncompatibleTarget.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Exception;

use InvalidArgumentException;
use MSSTC4PHP\DtoGenerator\Domain\Target\Capability;
use MSSTC4PHP\DtoGenerator\Domain\Target\PhpVersion;

final class IncompatibleTarget extends InvalidArgumentException
{
    public static function capabilityMissing(Capability $capability, PhpVersion $php, string $feature): self
    {
        return new self(sprintf(
            '%s requires %s (PHP %s+), but the target is PHP %s.',
            $feature,
            $capability->value(),
            $capability->minimumVersion()->toString(),
            $php->toString(),
        ));
    }
}
```

`src/Domain/Target/MetadataMode.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Target;

use MSSTC4PHP\DtoGenerator\Domain\Shared\AbstractEnum;

final class MetadataMode extends AbstractEnum
{
    public const ATTRIBUTES = 'attributes';
    public const ANNOTATIONS = 'annotations';
    public const NONE = 'none';

    public static function defaultFor(PhpVersion $php): self
    {
        return $php->isAtLeast(Capability::from(Capability::ATTRIBUTES)->minimumVersion())
            ? self::from(self::ATTRIBUTES)
            : self::from(self::ANNOTATIONS);
    }

    protected static function values(): array
    {
        return [self::ATTRIBUTES, self::ANNOTATIONS, self::NONE];
    }
}
```

`src/Domain/Target/Mutability.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Target;

use MSSTC4PHP\DtoGenerator\Domain\Shared\AbstractEnum;

final class Mutability extends AbstractEnum
{
    public const IMMUTABLE = 'immutable';
    public const MUTABLE = 'mutable';

    public function isImmutable(): bool
    {
        return $this->value() === self::IMMUTABLE;
    }

    protected static function values(): array
    {
        return [self::IMMUTABLE, self::MUTABLE];
    }
}
```

`src/Domain/Target/AccessorStyle.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Target;

use MSSTC4PHP\DtoGenerator\Domain\Shared\AbstractEnum;

final class AccessorStyle extends AbstractEnum
{
    public const AUTO = 'auto';
    public const GETTERS = 'getters';
    public const PUBLIC_PROPERTIES = 'public-properties';

    protected static function values(): array
    {
        return [self::AUTO, self::GETTERS, self::PUBLIC_PROPERTIES];
    }
}
```

`src/Domain/Target/DateTimeClass.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Target;

use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use MSSTC4PHP\DtoGenerator\Domain\Shared\AbstractEnum;

final class DateTimeClass extends AbstractEnum
{
    public const IMMUTABLE = 'DateTimeImmutable';
    public const MUTABLE = 'DateTime';

    /**
     * @return class-string<DateTimeInterface>
     */
    public function className(): string
    {
        return $this->value() === self::MUTABLE ? DateTime::class : DateTimeImmutable::class;
    }

    protected static function values(): array
    {
        return [self::IMMUTABLE, self::MUTABLE];
    }
}
```

`src/Domain/Target/TargetProfile.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Target;

use MSSTC4PHP\DtoGenerator\Domain\Exception\IncompatibleTarget;

final class TargetProfile
{
    private PhpVersion $php;

    private MetadataMode $metadata;

    private Mutability $mutability;

    private AccessorStyle $accessors;

    private DateTimeClass $dateTimeClass;

    private bool $strict;

    public function __construct(
        PhpVersion $php,
        MetadataMode $metadata,
        Mutability $mutability,
        AccessorStyle $accessors,
        DateTimeClass $dateTimeClass,
        bool $strict
    ) {
        $this->php = $php;
        $this->metadata = $metadata;
        $this->mutability = $mutability;
        $this->accessors = $accessors;
        $this->dateTimeClass = $dateTimeClass;
        $this->strict = $strict;

        if ($metadata->equals(MetadataMode::from(MetadataMode::ATTRIBUTES))) {
            $this->assertSupports(Capability::from(Capability::ATTRIBUTES), 'Metadata mode "attributes"');
        }

        $this->accessorsFor($mutability);
    }

    public function supports(Capability $capability): bool
    {
        return $this->php->isAtLeast($capability->minimumVersion());
    }

    /**
     * Mutability can be overridden per schema, so the style is resolved per class, never globally.
     */
    public function accessorsFor(Mutability $mutability): AccessorStyle
    {
        $readonly = Capability::from(Capability::READONLY_PROPERTIES);
        $publicProperties = AccessorStyle::from(AccessorStyle::PUBLIC_PROPERTIES);

        if ($this->accessors->equals(AccessorStyle::from(AccessorStyle::AUTO))) {
            return $mutability->isImmutable() && $this->supports($readonly)
                ? $publicProperties
                : AccessorStyle::from(AccessorStyle::GETTERS);
        }

        if ($this->accessors->equals($publicProperties) && $mutability->isImmutable()) {
            $this->assertSupports($readonly, 'Immutable DTOs with public properties');
        }

        return $this->accessors;
    }

    public function php(): PhpVersion
    {
        return $this->php;
    }

    public function metadata(): MetadataMode
    {
        return $this->metadata;
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

    public function isStrict(): bool
    {
        return $this->strict;
    }

    private function assertSupports(Capability $capability, string $feature): void
    {
        if (!$this->supports($capability)) {
            throw IncompatibleTarget::capabilityMissing($capability, $this->php, $feature);
        }
    }
}
```

- [ ] **Step 4: Прогнать тесты**

Run: `vendor/bin/phpunit --filter 'ModesTest|TargetProfileTest' && make test-74`
Expected: PASS.

- [ ] **Step 5: Статическая проверка**

Run: `make fix && make check`
Expected: без ошибок.

- [ ] **Step 6: Commit**

```bash
git add src/Domain/Exception/IncompatibleTarget.php src/Domain/Target tests/Unit/Domain/Target
git commit -m "feat(target): generation modes and TargetProfile

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Строительные блоки схемы — `SchemaLocation`, `SchemaType`, `Extensions`, `Discriminator`

**Files:**
- Create: `src/Domain/Exception/InvalidModel.php`, `src/Domain/Shared/Json.php`, `src/Domain/Schema/SchemaLocation.php`, `src/Domain/Schema/SchemaType.php`, `src/Domain/Schema/Extensions.php`, `src/Domain/Schema/Discriminator.php`
- Test: `tests/Unit/Domain/Schema/SchemaLocationTest.php`, `tests/Unit/Domain/Schema/ExtensionsTest.php`, `tests/Unit/Domain/Schema/DiscriminatorTest.php`

**Interfaces:**
- Consumes: `AbstractEnum` (Task 1).
- Produces:
  - `InvalidModel extends \InvalidArgumentException`.
  - `Json` хранит алиасы `@phpstan-type JsonScalar null|bool|int|float|string` и `@phpstan-type JsonValue JsonScalar|array<array-key, mixed>`. Импортируются через `@phpstan-import-type JsonValue from Json`.
  - `SchemaLocation::__construct(string $file, string $pointer = '')`, `file()`, `pointer()`, `child(string ...$segments): SchemaLocation`, `toString(): string` (вида `file#pointer`), `equals()`.
  - `SchemaType extends AbstractEnum`: `STRING`, `INTEGER`, `NUMBER`, `BOOLEAN`, `ARRAY`, `OBJECT`, `NULL`.
  - `Extensions::__construct(array<string, JsonValue> $values = [])`, `has(string): bool`, `get(string): JsonValue`, `all(): array<string, JsonValue>`, `keys(): list<string>`, `isEmpty(): bool`.
  - `Discriminator::__construct(string $propertyName, array<int|string, string> $mapping = [])`, `propertyName(): string`, `values(): list<string>`, `refFor(string $value): ?string`.

- [ ] **Step 1: Написать падающие тесты**

`tests/Unit/Domain/Schema/SchemaLocationTest.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Domain\Schema;

use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;
use PHPUnit\Framework\TestCase;

final class SchemaLocationTest extends TestCase
{
    public function testRendersFileAndPointer(): void
    {
        $location = (new SchemaLocation('public.yaml'))->child('components', 'schemas', 'User');

        self::assertSame('public.yaml', $location->file());
        self::assertSame('/components/schemas/User', $location->pointer());
        self::assertSame('public.yaml#/components/schemas/User', $location->toString());
    }

    public function testTheDocumentRootHasAnEmptyPointer(): void
    {
        self::assertSame('public.yaml#', (new SchemaLocation('public.yaml'))->toString());
    }

    public function testEscapesSlashAndTildeInSegments(): void
    {
        $location = (new SchemaLocation('a.json'))->child('properties', 'a/b', 'x~y');

        self::assertSame('/properties/a~1b/x~0y', $location->pointer());
    }

    public function testComparesByValue(): void
    {
        self::assertTrue((new SchemaLocation('a.json', '/x'))->equals((new SchemaLocation('a.json'))->child('x')));
        self::assertFalse((new SchemaLocation('a.json', '/x'))->equals(new SchemaLocation('b.json', '/x')));
    }

    public function testRejectsAnEmptyFile(): void
    {
        $this->expectException(InvalidModel::class);

        new SchemaLocation('');
    }

    public function testRejectsARelativePointer(): void
    {
        $this->expectException(InvalidModel::class);
        $this->expectExceptionMessage('must be empty or start with "/"');

        new SchemaLocation('a.json', 'components');
    }
}
```

`tests/Unit/Domain/Schema/ExtensionsTest.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Domain\Schema;

use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Extensions;
use PHPUnit\Framework\TestCase;

final class ExtensionsTest extends TestCase
{
    public function testExposesValuesInDocumentOrder(): void
    {
        $extensions = new Extensions(['x-php-name' => 'userId', 'x-audit' => ['level' => 2]]);

        self::assertTrue($extensions->has('x-audit'));
        self::assertFalse($extensions->has('x-missing'));
        self::assertSame('userId', $extensions->get('x-php-name'));
        self::assertSame(['level' => 2], $extensions->get('x-audit'));
        self::assertSame(['x-php-name', 'x-audit'], $extensions->keys());
        self::assertFalse($extensions->isEmpty());
    }

    public function testKeepsAnExplicitNull(): void
    {
        $extensions = new Extensions(['x-nothing' => null]);

        self::assertTrue($extensions->has('x-nothing'));
        self::assertNull($extensions->get('x-nothing'));
    }

    public function testIsEmptyByDefault(): void
    {
        self::assertTrue((new Extensions())->isEmpty());
        self::assertSame([], (new Extensions())->all());
    }

    /**
     * @dataProvider invalidKeys
     */
    public function testRejectsKeysThatAreNotExtensions(string $key): void
    {
        $this->expectException(InvalidModel::class);

        new Extensions([$key => true]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidKeys(): array
    {
        return [
            'plain keyword' => ['format'],
            'prefix only' => ['x-'],
            'uppercase prefix' => ['X-foo'],
        ];
    }

    public function testGetRejectsAMissingKey(): void
    {
        $this->expectException(InvalidModel::class);
        $this->expectExceptionMessage('Extension "x-missing" is not set.');

        (new Extensions())->get('x-missing');
    }
}
```

`tests/Unit/Domain/Schema/DiscriminatorTest.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Domain\Schema;

use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Discriminator;
use PHPUnit\Framework\TestCase;

final class DiscriminatorTest extends TestCase
{
    public function testMapsValuesToReferences(): void
    {
        $discriminator = new Discriminator('kind', ['cat' => '#/components/schemas/Cat']);

        self::assertSame('kind', $discriminator->propertyName());
        self::assertSame(['cat'], $discriminator->values());
        self::assertSame('#/components/schemas/Cat', $discriminator->refFor('cat'));
        self::assertNull($discriminator->refFor('dog'));
    }

    public function testNumericValuesStayStrings(): void
    {
        $discriminator = new Discriminator('version', ['1' => '#/components/schemas/V1']);

        self::assertSame(['1'], $discriminator->values());
        self::assertSame('#/components/schemas/V1', $discriminator->refFor('1'));
    }

    public function testRejectsAnEmptyPropertyName(): void
    {
        $this->expectException(InvalidModel::class);

        new Discriminator('');
    }

    public function testRejectsAnEmptyReference(): void
    {
        $this->expectException(InvalidModel::class);

        new Discriminator('kind', ['cat' => '']);
    }
}
```

- [ ] **Step 2: Убедиться, что тесты падают**

Run: `vendor/bin/phpunit --filter 'SchemaLocationTest|ExtensionsTest|DiscriminatorTest'`
Expected: FAIL — `Class ... not found`.

- [ ] **Step 3: Реализовать**

`src/Domain/Exception/InvalidModel.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Exception;

use InvalidArgumentException;

final class InvalidModel extends InvalidArgumentException
{
}
```

`src/Domain/Shared/Json.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Shared;

/**
 * Host for the JSON value type aliases; never instantiated.
 *
 * A decoded JSON scalar:
 * @phpstan-type JsonScalar null|bool|int|float|string
 *
 * A decoded JSON value. Reason for `mixed`: PHPStan type aliases cannot be recursive.
 * @phpstan-type JsonValue JsonScalar|array<array-key, mixed>
 */
final class Json
{
    private function __construct()
    {
    }
}
```

`src/Domain/Schema/SchemaLocation.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Schema;

use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;

final class SchemaLocation
{
    private string $file;

    private string $pointer;

    public function __construct(string $file, string $pointer = '')
    {
        if ($file === '') {
            throw new InvalidModel('Schema location file must not be empty.');
        }

        if ($pointer !== '' && $pointer[0] !== '/') {
            throw new InvalidModel(sprintf('JSON pointer "%s" must be empty or start with "/".', $pointer));
        }

        $this->file = $file;
        $this->pointer = $pointer;
    }

    public function file(): string
    {
        return $this->file;
    }

    public function pointer(): string
    {
        return $this->pointer;
    }

    public function child(string ...$segments): self
    {
        $pointer = $this->pointer;
        foreach ($segments as $segment) {
            // RFC 6901: "~" must be escaped before "/".
            $pointer .= '/' . str_replace(['~', '/'], ['~0', '~1'], $segment);
        }

        return new self($this->file, $pointer);
    }

    public function toString(): string
    {
        return $this->file . '#' . $this->pointer;
    }

    public function equals(self $other): bool
    {
        return $this->file === $other->file && $this->pointer === $other->pointer;
    }
}
```

`src/Domain/Schema/SchemaType.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Schema;

use MSSTC4PHP\DtoGenerator\Domain\Shared\AbstractEnum;

final class SchemaType extends AbstractEnum
{
    public const STRING = 'string';
    public const INTEGER = 'integer';
    public const NUMBER = 'number';
    public const BOOLEAN = 'boolean';
    public const ARRAY = 'array';
    public const OBJECT = 'object';
    public const NULL = 'null';

    protected static function values(): array
    {
        return [self::STRING, self::INTEGER, self::NUMBER, self::BOOLEAN, self::ARRAY, self::OBJECT, self::NULL];
    }
}
```

`src/Domain/Schema/Extensions.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Schema;

use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Json;

/**
 * @phpstan-import-type JsonValue from Json
 */
final class Extensions
{
    /** @var array<string, JsonValue> */
    private array $values;

    /**
     * @param array<string, JsonValue> $values
     */
    public function __construct(array $values = [])
    {
        foreach (array_keys($values) as $key) {
            if (strncmp($key, 'x-', 2) !== 0 || strlen($key) === 2) {
                throw new InvalidModel(sprintf('Extension key "%s" must start with "x-" followed by a name.', $key));
            }
        }

        $this->values = $values;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    /**
     * @return JsonValue
     */
    public function get(string $key)
    {
        if (!$this->has($key)) {
            throw new InvalidModel(sprintf('Extension "%s" is not set.', $key));
        }

        return $this->values[$key];
    }

    /**
     * @return array<string, JsonValue>
     */
    public function all(): array
    {
        return $this->values;
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->values);
    }

    public function isEmpty(): bool
    {
        return $this->values === [];
    }
}
```

`src/Domain/Schema/Discriminator.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Schema;

use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;

final class Discriminator
{
    private string $propertyName;

    /**
     * Keys are discriminator values; PHP turns numeric ones into ints, hence `int|string`.
     *
     * @var array<int|string, string>
     */
    private array $mapping;

    /**
     * @param array<int|string, string> $mapping discriminator value → `$ref`
     */
    public function __construct(string $propertyName, array $mapping = [])
    {
        if ($propertyName === '') {
            throw new InvalidModel('Discriminator property name must not be empty.');
        }

        foreach ($mapping as $value => $ref) {
            if ($ref === '') {
                throw new InvalidModel(sprintf('Discriminator value "%s" maps to an empty reference.', $value));
            }
        }

        $this->propertyName = $propertyName;
        $this->mapping = $mapping;
    }

    public function propertyName(): string
    {
        return $this->propertyName;
    }

    /**
     * @return list<string>
     */
    public function values(): array
    {
        return array_map('strval', array_keys($this->mapping));
    }

    public function refFor(string $value): ?string
    {
        return $this->mapping[$value] ?? null;
    }
}
```

- [ ] **Step 4: Прогнать тесты**

Run: `vendor/bin/phpunit --filter 'SchemaLocationTest|ExtensionsTest|DiscriminatorTest' && make test-74`
Expected: PASS.

- [ ] **Step 5: Статическая проверка**

Run: `make fix && make check`
Expected: без ошибок.

- [ ] **Step 6: Commit**

```bash
git add src/Domain/Exception/InvalidModel.php src/Domain/Shared/Json.php src/Domain/Schema tests/Unit/Domain/Schema
git commit -m "feat(schema): location, types, extensions and discriminator

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: `Schema`, `SchemaBuilder` и `DefaultValue`

**Files:**
- Create: `src/Domain/Shared/DefaultValue.php`, `src/Domain/Schema/Schema.php`, `src/Domain/Schema/SchemaBuilder.php`
- Test: `tests/Unit/Domain/Schema/SchemaTest.php`

**Interfaces:**
- Consumes: `SchemaLocation`, `SchemaType`, `Extensions`, `Discriminator`, `InvalidModel`, `Json` (Task 4).
- Produces:
  - `DefaultValue::__construct(JsonValue $value)`, `value(): JsonValue`.
  - `Schema::STRUCTURAL_KEYWORDS` — `list<string>` ключевых слов, у которых есть отдельные accessor'ы. В `keywords` их класть нельзя.
  - Конструктор `Schema` (позиционные параметры, в таком порядке):
    `SchemaLocation $location, list<SchemaType> $types, ?string $ref, ?string $format, ?string $description, bool $deprecated, ?DefaultValue $default, ?list<JsonValue> $enum, array<int|string, Schema> $properties, list<string> $required, ?Schema $items, bool|Schema|null $additionalProperties, list<Schema> $allOf, list<Schema> $oneOf, list<Schema> $anyOf, ?Discriminator $discriminator, array<string, JsonValue> $keywords, Extensions $extensions`.
  - Accessor'ы `Schema`:
    - базовые: `location()`, `types()`, `hasType(SchemaType)`, `isNullable()`, `nonNullTypes()`, `ref()`, `format()`, `description()`, `isDeprecated()`, `default()`, `enum()`;
    - свойства: `propertyNames(): list<string>`, `property(string): ?Schema`, `isRequired(string)`, `required()`;
    - вложенные: `items()`, `additionalProperties()`, `allOf()`, `oneOf()`, `anyOf()`, `discriminator()`;
    - прочие: `hasKeyword(string)`, `keyword(string): JsonValue`, `keywords()`, `extensions()`.
  - `SchemaBuilder::__construct(SchemaLocation)` — fluent-сеттеры, каждый возвращает `self`:
    - `types(SchemaType ...)`, `ref(string)`, `format(string)`, `description(string)`, `deprecated(bool = true)`;
    - `defaultValue(JsonValue)`, `enum(array)`;
    - `property(string, Schema)`, `required(string ...)`, `items(Schema)`, `additionalProperties(bool|Schema)`;
    - `allOf(Schema ...)`, `oneOf(Schema ...)`, `anyOf(Schema ...)`, `discriminator(Discriminator)`;
    - `keyword(string, JsonValue)`, `extensions(Extensions)`;
    - итоговый метод `build(): Schema`.

- [ ] **Step 1: Написать падающий тест**

`tests/Unit/Domain/Schema/SchemaTest.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Domain\Schema;

use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Discriminator;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Extensions;
use MSSTC4PHP\DtoGenerator\Domain\Schema\Schema;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaBuilder;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaType;
use PHPUnit\Framework\TestCase;

final class SchemaTest extends TestCase
{
    public function testDetectsNullableTypes(): void
    {
        $schema = self::builder()->types(self::type(SchemaType::STRING), self::type(SchemaType::NULL))->build();

        self::assertTrue($schema->isNullable());
        self::assertTrue($schema->hasType(self::type(SchemaType::STRING)));
        self::assertSame([self::type(SchemaType::STRING)], $schema->nonNullTypes());
    }

    public function testASchemaWithoutTypesIsNotNullable(): void
    {
        $schema = self::builder()->build();

        self::assertFalse($schema->isNullable());
        self::assertSame([], $schema->types());
    }

    public function testKeepsPropertyOrderAndRequiredSet(): void
    {
        $string = self::builder('/p')->types(self::type(SchemaType::STRING))->build();
        $schema = self::builder()
            ->types(self::type(SchemaType::OBJECT))
            ->property('id', $string)
            ->property('email', $string)
            ->required('id')
            ->build();

        self::assertSame(['id', 'email'], $schema->propertyNames());
        self::assertSame($string, $schema->property('email'));
        self::assertNull($schema->property('missing'));
        self::assertTrue($schema->isRequired('id'));
        self::assertFalse($schema->isRequired('email'));
        self::assertSame(['id'], $schema->required());
    }

    public function testNumericPropertyNamesStayStrings(): void
    {
        $string = self::builder('/p')->types(self::type(SchemaType::STRING))->build();
        $schema = self::builder()->property('200', $string)->property('ok', $string)->build();

        self::assertSame(['200', 'ok'], $schema->propertyNames());
        self::assertSame($string, $schema->property('200'));
    }

    public function testDistinguishesANullDefaultFromNoDefault(): void
    {
        $withNull = self::builder()->defaultValue(null)->build();
        $withoutDefault = self::builder()->build();

        self::assertNotNull($withNull->default());
        self::assertNull($withNull->default()->value());
        self::assertNull($withoutDefault->default());
    }

    public function testExposesScalarKeywordsAndReferences(): void
    {
        $schema = self::builder()
            ->ref('#/components/schemas/User')
            ->format('email')
            ->description('Primary e-mail')
            ->deprecated()
            ->enum(['a', 'b'])
            ->keyword('minLength', 3)
            ->extensions(new Extensions(['x-php-name' => 'mail']))
            ->build();

        self::assertSame('#/components/schemas/User', $schema->ref());
        self::assertSame('email', $schema->format());
        self::assertSame('Primary e-mail', $schema->description());
        self::assertTrue($schema->isDeprecated());
        self::assertSame(['a', 'b'], $schema->enum());
        self::assertTrue($schema->hasKeyword('minLength'));
        self::assertSame(3, $schema->keyword('minLength'));
        self::assertSame(['minLength' => 3], $schema->keywords());
        self::assertSame('mail', $schema->extensions()->get('x-php-name'));
    }

    public function testExposesComposition(): void
    {
        $part = self::builder('/part')->build();
        $discriminator = new Discriminator('kind');
        $schema = self::builder()
            ->allOf($part)
            ->oneOf($part, $part)
            ->anyOf($part)
            ->items($part)
            ->additionalProperties($part)
            ->discriminator($discriminator)
            ->build();

        self::assertSame([$part], $schema->allOf());
        self::assertSame([$part, $part], $schema->oneOf());
        self::assertSame([$part], $schema->anyOf());
        self::assertSame($part, $schema->items());
        self::assertSame($part, $schema->additionalProperties());
        self::assertSame($discriminator, $schema->discriminator());
    }

    public function testAdditionalPropertiesAcceptsBooleans(): void
    {
        self::assertFalse(self::builder()->additionalProperties(false)->build()->additionalProperties());
        self::assertNull(self::builder()->build()->additionalProperties());
    }

    public function testRejectsDuplicateTypes(): void
    {
        $this->expectException(InvalidModel::class);
        $this->expectExceptionMessage('repeats type "string"');

        self::builder()->types(self::type(SchemaType::STRING), self::type(SchemaType::STRING))->build();
    }

    public function testRejectsAnEmptyEnum(): void
    {
        $this->expectException(InvalidModel::class);

        self::builder()->enum([])->build();
    }

    public function testRejectsDuplicateRequiredNames(): void
    {
        $this->expectException(InvalidModel::class);

        self::builder()->required('id', 'id')->build();
    }

    /**
     * @dataProvider reservedKeywords
     */
    public function testRejectsKeywordsThatHaveTheirOwnAccessor(string $keyword): void
    {
        $this->expectException(InvalidModel::class);

        self::builder()->keyword($keyword, 'x')->build();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function reservedKeywords(): array
    {
        return [
            'type' => ['type'],
            '$ref' => ['$ref'],
            'properties' => ['properties'],
            'extension' => ['x-php-name'],
        ];
    }

    public function testRejectsAnInvalidAdditionalPropertiesValue(): void
    {
        $this->expectException(InvalidModel::class);

        new Schema(
            new SchemaLocation('a.json'),
            [],
            null,
            null,
            null,
            false,
            null,
            null,
            [],
            [],
            null,
            'yes',
            [],
            [],
            [],
            null,
            [],
            new Extensions(),
        );
    }

    public function testKeywordRejectsAMissingName(): void
    {
        $this->expectException(InvalidModel::class);
        $this->expectExceptionMessage('Keyword "pattern" is not set');

        self::builder()->build()->keyword('pattern');
    }

    private static function builder(string $pointer = ''): SchemaBuilder
    {
        return new SchemaBuilder(new SchemaLocation('a.json', $pointer));
    }

    private static function type(string $type): SchemaType
    {
        return SchemaType::from($type);
    }
}
```

- [ ] **Step 2: Убедиться, что тест падает**

Run: `vendor/bin/phpunit --filter SchemaTest`
Expected: FAIL — `Class "MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaBuilder" not found`.

- [ ] **Step 3: Реализовать**

`src/Domain/Shared/DefaultValue.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Shared;

/**
 * Wraps a default so that "default is null" stays distinct from "no default".
 *
 * @phpstan-import-type JsonValue from Json
 */
final class DefaultValue
{
    /** @var JsonValue */
    private $value;

    /**
     * @param JsonValue $value
     */
    public function __construct($value)
    {
        $this->value = $value;
    }

    /**
     * @return JsonValue
     */
    public function value()
    {
        return $this->value;
    }
}
```

`src/Domain/Schema/Schema.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Schema;

use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;
use MSSTC4PHP\DtoGenerator\Domain\Shared\DefaultValue;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Json;

/**
 * A parsed JSON Schema 2020-12 node. `$ref` is kept verbatim; resolution happens outside the domain.
 *
 * @phpstan-import-type JsonValue from Json
 */
final class Schema
{
    public const STRUCTURAL_KEYWORDS = [
        'type', '$ref', 'format', 'description', 'deprecated', 'default', 'enum', 'properties', 'required',
        'items', 'additionalProperties', 'allOf', 'oneOf', 'anyOf', 'discriminator',
    ];

    private SchemaLocation $location;

    /** @var list<SchemaType> */
    private array $types;

    private ?string $ref;

    private ?string $format;

    private ?string $description;

    private bool $deprecated;

    private ?DefaultValue $default;

    /** @var non-empty-list<JsonValue>|null */
    private ?array $enum;

    /**
     * Keys are property names; PHP turns numeric ones into ints, hence `int|string`.
     *
     * @var array<int|string, Schema>
     */
    private array $properties;

    /** @var list<string> */
    private array $required;

    private ?Schema $items;

    /** @var bool|Schema|null */
    private $additionalProperties;

    /** @var list<Schema> */
    private array $allOf;

    /** @var list<Schema> */
    private array $oneOf;

    /** @var list<Schema> */
    private array $anyOf;

    private ?Discriminator $discriminator;

    /** @var array<string, JsonValue> */
    private array $keywords;

    private Extensions $extensions;

    /**
     * @param list<SchemaType> $types
     * @param list<JsonValue>|null $enum
     * @param array<int|string, Schema> $properties
     * @param list<string> $required
     * @param bool|Schema|null $additionalProperties
     * @param list<Schema> $allOf
     * @param list<Schema> $oneOf
     * @param list<Schema> $anyOf
     * @param array<string, JsonValue> $keywords validation keywords without a dedicated accessor
     */
    public function __construct(
        SchemaLocation $location,
        array $types,
        ?string $ref,
        ?string $format,
        ?string $description,
        bool $deprecated,
        ?DefaultValue $default,
        ?array $enum,
        array $properties,
        array $required,
        ?Schema $items,
        $additionalProperties,
        array $allOf,
        array $oneOf,
        array $anyOf,
        ?Discriminator $discriminator,
        array $keywords,
        Extensions $extensions
    ) {
        $seenTypes = [];
        foreach ($types as $type) {
            if (isset($seenTypes[$type->value()])) {
                throw new InvalidModel(sprintf('Schema %s repeats type "%s".', $location->toString(), $type->value()));
            }
            $seenTypes[$type->value()] = true;
        }

        if ($enum === []) {
            throw new InvalidModel(sprintf('Schema %s has an empty "enum".', $location->toString()));
        }

        if (count(array_unique($required)) !== count($required)) {
            throw new InvalidModel(sprintf('Schema %s repeats a name in "required".', $location->toString()));
        }

        if ($additionalProperties !== null && !is_bool($additionalProperties) && !$additionalProperties instanceof self) {
            throw new InvalidModel(sprintf('Schema %s: "additionalProperties" must be a boolean or a schema.', $location->toString()));
        }

        foreach (array_keys($keywords) as $keyword) {
            if (in_array($keyword, self::STRUCTURAL_KEYWORDS, true) || strncmp($keyword, 'x-', 2) === 0) {
                throw new InvalidModel(sprintf('Schema %s: "%s" has a dedicated field and cannot be a generic keyword.', $location->toString(), $keyword));
            }
        }

        $this->location = $location;
        $this->types = $types;
        $this->ref = $ref;
        $this->format = $format;
        $this->description = $description;
        $this->deprecated = $deprecated;
        $this->default = $default;
        $this->enum = $enum;
        $this->properties = $properties;
        $this->required = $required;
        $this->items = $items;
        $this->additionalProperties = $additionalProperties;
        $this->allOf = $allOf;
        $this->oneOf = $oneOf;
        $this->anyOf = $anyOf;
        $this->discriminator = $discriminator;
        $this->keywords = $keywords;
        $this->extensions = $extensions;
    }

    public function location(): SchemaLocation
    {
        return $this->location;
    }

    /**
     * @return list<SchemaType>
     */
    public function types(): array
    {
        return $this->types;
    }

    public function hasType(SchemaType $type): bool
    {
        return in_array($type, $this->types, true);
    }

    public function isNullable(): bool
    {
        return $this->hasType(SchemaType::from(SchemaType::NULL));
    }

    /**
     * @return list<SchemaType>
     */
    public function nonNullTypes(): array
    {
        $null = SchemaType::from(SchemaType::NULL);

        return array_values(array_filter($this->types, static fn (SchemaType $type): bool => $type !== $null));
    }

    public function ref(): ?string
    {
        return $this->ref;
    }

    public function format(): ?string
    {
        return $this->format;
    }

    public function description(): ?string
    {
        return $this->description;
    }

    public function isDeprecated(): bool
    {
        return $this->deprecated;
    }

    public function default(): ?DefaultValue
    {
        return $this->default;
    }

    /**
     * @return non-empty-list<JsonValue>|null
     */
    public function enum(): ?array
    {
        return $this->enum;
    }

    /**
     * @return list<string>
     */
    public function propertyNames(): array
    {
        return array_map('strval', array_keys($this->properties));
    }

    public function property(string $name): ?self
    {
        return $this->properties[$name] ?? null;
    }

    public function isRequired(string $name): bool
    {
        return in_array($name, $this->required, true);
    }

    /**
     * @return list<string>
     */
    public function required(): array
    {
        return $this->required;
    }

    public function items(): ?self
    {
        return $this->items;
    }

    /**
     * @return bool|Schema|null null when the keyword is absent
     */
    public function additionalProperties()
    {
        return $this->additionalProperties;
    }

    /**
     * @return list<Schema>
     */
    public function allOf(): array
    {
        return $this->allOf;
    }

    /**
     * @return list<Schema>
     */
    public function oneOf(): array
    {
        return $this->oneOf;
    }

    /**
     * @return list<Schema>
     */
    public function anyOf(): array
    {
        return $this->anyOf;
    }

    public function discriminator(): ?Discriminator
    {
        return $this->discriminator;
    }

    public function hasKeyword(string $name): bool
    {
        return array_key_exists($name, $this->keywords);
    }

    /**
     * @return JsonValue
     */
    public function keyword(string $name)
    {
        if (!$this->hasKeyword($name)) {
            throw new InvalidModel(sprintf('Keyword "%s" is not set on schema %s.', $name, $this->location->toString()));
        }

        return $this->keywords[$name];
    }

    /**
     * @return array<string, JsonValue>
     */
    public function keywords(): array
    {
        return $this->keywords;
    }

    public function extensions(): Extensions
    {
        return $this->extensions;
    }
}
```

`src/Domain/Schema/SchemaBuilder.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Schema;

use MSSTC4PHP\DtoGenerator\Domain\Shared\DefaultValue;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Json;

/**
 * Mutable assembly helper for {@see Schema}; the built schema is immutable.
 *
 * @phpstan-import-type JsonValue from Json
 */
final class SchemaBuilder
{
    private SchemaLocation $location;

    /** @var list<SchemaType> */
    private array $types = [];

    private ?string $ref = null;

    private ?string $format = null;

    private ?string $description = null;

    private bool $deprecated = false;

    private ?DefaultValue $default = null;

    /** @var list<JsonValue>|null */
    private ?array $enum = null;

    /** @var array<int|string, Schema> */
    private array $properties = [];

    /** @var list<string> */
    private array $required = [];

    private ?Schema $items = null;

    /** @var bool|Schema|null */
    private $additionalProperties;

    /** @var list<Schema> */
    private array $allOf = [];

    /** @var list<Schema> */
    private array $oneOf = [];

    /** @var list<Schema> */
    private array $anyOf = [];

    private ?Discriminator $discriminator = null;

    /** @var array<string, JsonValue> */
    private array $keywords = [];

    private Extensions $extensions;

    public function __construct(SchemaLocation $location)
    {
        $this->location = $location;
        $this->extensions = new Extensions();
    }

    public function types(SchemaType ...$types): self
    {
        $this->types = $types;

        return $this;
    }

    public function ref(string $ref): self
    {
        $this->ref = $ref;

        return $this;
    }

    public function format(string $format): self
    {
        $this->format = $format;

        return $this;
    }

    public function description(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function deprecated(bool $deprecated = true): self
    {
        $this->deprecated = $deprecated;

        return $this;
    }

    /**
     * @param JsonValue $value
     */
    public function defaultValue($value): self
    {
        $this->default = new DefaultValue($value);

        return $this;
    }

    /**
     * @param list<JsonValue> $values
     */
    public function enum(array $values): self
    {
        $this->enum = $values;

        return $this;
    }

    public function property(string $name, Schema $schema): self
    {
        $this->properties[$name] = $schema;

        return $this;
    }

    public function required(string ...$names): self
    {
        $this->required = $names;

        return $this;
    }

    public function items(Schema $items): self
    {
        $this->items = $items;

        return $this;
    }

    /**
     * @param bool|Schema $additionalProperties
     */
    public function additionalProperties($additionalProperties): self
    {
        $this->additionalProperties = $additionalProperties;

        return $this;
    }

    public function allOf(Schema ...$schemas): self
    {
        $this->allOf = $schemas;

        return $this;
    }

    public function oneOf(Schema ...$schemas): self
    {
        $this->oneOf = $schemas;

        return $this;
    }

    public function anyOf(Schema ...$schemas): self
    {
        $this->anyOf = $schemas;

        return $this;
    }

    public function discriminator(Discriminator $discriminator): self
    {
        $this->discriminator = $discriminator;

        return $this;
    }

    /**
     * @param JsonValue $value
     */
    public function keyword(string $name, $value): self
    {
        $this->keywords[$name] = $value;

        return $this;
    }

    public function extensions(Extensions $extensions): self
    {
        $this->extensions = $extensions;

        return $this;
    }

    public function build(): Schema
    {
        return new Schema(
            $this->location,
            $this->types,
            $this->ref,
            $this->format,
            $this->description,
            $this->deprecated,
            $this->default,
            $this->enum,
            $this->properties,
            $this->required,
            $this->items,
            $this->additionalProperties,
            $this->allOf,
            $this->oneOf,
            $this->anyOf,
            $this->discriminator,
            $this->keywords,
            $this->extensions,
        );
    }
}
```

> Примечание: `$part` в `testExposesComposition` используется в `oneOf` дважды. Дубли в композиции не запрещены — так и задумано.

- [ ] **Step 4: Прогнать тест**

Run: `vendor/bin/phpunit --filter SchemaTest && make test-74`
Expected: PASS.

- [ ] **Step 5: Статическая проверка**

Run: `make fix && make check`
Expected: без ошибок. Если PHPStan сообщает `Cannot call method value() on DefaultValue|null` в `testDistinguishesANullDefaultFromNoDefault`, значит phpstan-phpunit не сузил тип после `assertNotNull`. Тогда сохраните результат в переменную и сузьте его через `self::assertInstanceOf(DefaultValue::class, $default)`. Ignore добавлять нельзя.

- [ ] **Step 6: Commit**

```bash
git add src/Domain/Shared/DefaultValue.php src/Domain/Schema tests/Unit/Domain/Schema
git commit -m "feat(schema): immutable Schema node and SchemaBuilder

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: IR — имена, документация и типы

**Files:**
- Create: `src/Domain/Model/Identifier.php`, `src/Domain/Model/ClassName.php`, `src/Domain/Model/DocModel.php`
- Create: `src/Domain/Model/TypeModel.php`, `src/Domain/Model/ScalarType.php`, `src/Domain/Model/ClassType.php`, `src/Domain/Model/ListType.php`, `src/Domain/Model/MapType.php`, `src/Domain/Model/UnionType.php`, `src/Domain/Model/NullableType.php`, `src/Domain/Model/MixedType.php`
- Test: `tests/Unit/Domain/Model/ClassNameTest.php`, `tests/Unit/Domain/Model/DocModelTest.php`, `tests/Unit/Domain/Model/TypeModelTest.php`

**Interfaces:**
- Consumes: `InvalidModel` (Task 4).
- Produces:
  - `Identifier::isValid(string): bool`, `Identifier::isReserved(string): bool`.
  - `ClassName::fromFqcn(string): ClassName`, `fqcn(): string` (без ведущего `\`), `namespace(): string` (`''` для глобального), `shortName(): string`, `equals(ClassName): bool`.
  - `DocModel::__construct(?string $description = null, bool $deprecated = false)`, `static none()`, `description(): ?string`, `isDeprecated(): bool`, `isEmpty(): bool`.
  - Интерфейс `TypeModel` с методом `describe(): string`. Две модели типов равны, если равны их описания.
  - Реализации `TypeModel`:
    - `ScalarType::string(?string $phpDoc = null)`, `::int(?string)`, `::float(?string)`, `::bool()`; методы `kind(): 'string'|'int'|'float'|'bool'`, `phpDoc(): ?string`;
    - `ClassType::__construct(ClassName)` + `className()`;
    - `ListType::__construct(TypeModel)` + `item()`;
    - `MapType::__construct(TypeModel)` + `value()`;
    - `UnionType::__construct(TypeModel ...)` + `members(): non-empty-list<TypeModel>`;
    - `NullableType::__construct(TypeModel)` + `inner()`;
    - `MixedType`.

- [ ] **Step 1: Написать падающие тесты**

`tests/Unit/Domain/Model/ClassNameTest.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Domain\Model;

use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;
use PHPUnit\Framework\TestCase;

final class ClassNameTest extends TestCase
{
    public function testSplitsNamespaceAndShortName(): void
    {
        $name = ClassName::fromFqcn('App\Dto\Public\User');

        self::assertSame('App\Dto\Public\User', $name->fqcn());
        self::assertSame('App\Dto\Public', $name->namespace());
        self::assertSame('User', $name->shortName());
    }

    public function testDropsTheLeadingBackslash(): void
    {
        self::assertSame('App\User', ClassName::fromFqcn('\App\User')->fqcn());
    }

    public function testSupportsGlobalClasses(): void
    {
        $name = ClassName::fromFqcn('DateTimeImmutable');

        self::assertSame('', $name->namespace());
        self::assertSame('DateTimeImmutable', $name->fqcn());
    }

    public function testComparesByValue(): void
    {
        self::assertTrue(ClassName::fromFqcn('\App\User')->equals(ClassName::fromFqcn('App\User')));
        self::assertFalse(ClassName::fromFqcn('App\User')->equals(ClassName::fromFqcn('App\Admin')));
    }

    /**
     * @dataProvider invalidNames
     */
    public function testRejectsInvalidNames(string $fqcn, string $message): void
    {
        $this->expectException(InvalidModel::class);
        $this->expectExceptionMessage($message);

        ClassName::fromFqcn($fqcn);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function invalidNames(): array
    {
        return [
            'empty' => ['', 'must not be empty'],
            'only a backslash' => ['\\', 'must not be empty'],
            'starts with a digit' => ['App\1User', 'segment "1User" is not a PHP identifier'],
            'dash' => ['App\User-Profile', 'segment "User-Profile" is not a PHP identifier'],
            'empty segment' => ['App\\\\User', 'segment "" is not a PHP identifier'],
            'trailing backslash' => ['App\User\\', 'segment "" is not a PHP identifier'],
            'reserved short name' => ['App\Model\List', '"List" is a reserved word'],
            'reserved type name' => ['Object', '"Object" is a reserved word'],
            'reserved namespace segment' => ['App\Enum\Status', '"Enum" is a reserved word'],
        ];
    }
}
```

`tests/Unit/Domain/Model/DocModelTest.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Domain\Model;

use MSSTC4PHP\DtoGenerator\Domain\Model\DocModel;
use PHPUnit\Framework\TestCase;

final class DocModelTest extends TestCase
{
    public function testTrimsTheDescription(): void
    {
        self::assertSame('User e-mail', (new DocModel("  User e-mail \n"))->description());
    }

    public function testTreatsABlankDescriptionAsAbsent(): void
    {
        $doc = new DocModel("   \n");

        self::assertNull($doc->description());
        self::assertTrue($doc->isEmpty());
    }

    public function testDeprecationAloneIsNotEmpty(): void
    {
        $doc = new DocModel(null, true);

        self::assertTrue($doc->isDeprecated());
        self::assertFalse($doc->isEmpty());
    }

    public function testNoneIsEmpty(): void
    {
        self::assertTrue(DocModel::none()->isEmpty());
    }
}
```

`tests/Unit/Domain/Model/TypeModelTest.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Domain\Model;

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
use PHPUnit\Framework\TestCase;

final class TypeModelTest extends TestCase
{
    /**
     * @dataProvider descriptions
     */
    public function testDescribesItself(TypeModel $type, string $expected): void
    {
        self::assertSame($expected, $type->describe());
    }

    /**
     * @return array<string, array{TypeModel, string}>
     */
    public static function descriptions(): array
    {
        $user = new ClassType(ClassName::fromFqcn('App\User'));

        return [
            'string' => [ScalarType::string(), 'string'],
            'refined string' => [ScalarType::string('non-empty-string'), 'non-empty-string'],
            'refined int' => [ScalarType::int('int<1, 10>'), 'int<1, 10>'],
            'float' => [ScalarType::float(), 'float'],
            'bool' => [ScalarType::bool(), 'bool'],
            'class' => [$user, 'App\User'],
            'list' => [new ListType($user), 'list<App\User>'],
            'map' => [new MapType(ScalarType::int()), 'array<string, int>'],
            'union' => [new UnionType($user, ScalarType::string()), 'App\User|string'],
            'nullable' => [new NullableType(new ListType(ScalarType::string())), 'list<string>|null'],
            'mixed' => [new MixedType(), 'mixed'],
        ];
    }

    public function testScalarExposesKindAndRefinement(): void
    {
        $type = ScalarType::int('positive-int');

        self::assertSame('int', $type->kind());
        self::assertSame('positive-int', $type->phpDoc());
        self::assertNull(ScalarType::bool()->phpDoc());
    }

    public function testScalarRejectsABlankRefinement(): void
    {
        $this->expectException(InvalidModel::class);

        ScalarType::string('  ');
    }

    public function testUnionFlattensNestedUnionsAndDropsDuplicates(): void
    {
        $union = new UnionType(
            new UnionType(ScalarType::string(), ScalarType::int()),
            ScalarType::string(),
            ScalarType::bool(),
        );

        self::assertSame('string|int|bool', $union->describe());
        self::assertCount(3, $union->members());
    }

    public function testUnionNeedsTwoDistinctMembers(): void
    {
        $this->expectException(InvalidModel::class);
        $this->expectExceptionMessage('at least two distinct members');

        new UnionType(ScalarType::string(), ScalarType::string());
    }

    public function testUnionRejectsNullableMembers(): void
    {
        $this->expectException(InvalidModel::class);
        $this->expectExceptionMessage('wrap the whole union in NullableType');

        new UnionType(ScalarType::string(), new NullableType(ScalarType::int()));
    }

    public function testUnionRejectsMixed(): void
    {
        $this->expectException(InvalidModel::class);

        new UnionType(ScalarType::string(), new MixedType());
    }

    public function testNullableRejectsNesting(): void
    {
        $this->expectException(InvalidModel::class);

        new NullableType(new NullableType(ScalarType::string()));
    }

    public function testNullableRejectsMixed(): void
    {
        $this->expectException(InvalidModel::class);

        new NullableType(new MixedType());
    }

    public function testWrappersExposeTheirParts(): void
    {
        $string = ScalarType::string();
        $user = ClassName::fromFqcn('App\User');

        self::assertSame($string, (new ListType($string))->item());
        self::assertSame($string, (new MapType($string))->value());
        self::assertSame($string, (new NullableType($string))->inner());
        self::assertSame($user, (new ClassType($user))->className());
    }
}
```

- [ ] **Step 2: Убедиться, что тесты падают**

Run: `vendor/bin/phpunit --filter 'ClassNameTest|DocModelTest|TypeModelTest'`
Expected: FAIL — `Class ... not found`.

- [ ] **Step 3: Реализовать имена и документацию**

`src/Domain/Model/Identifier.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Model;

final class Identifier
{
    /**
     * Keywords plus reserved type names, lowercase. Conservative on purpose: generated code must also
     * parse on PHP 7.4, where namespace segments cannot be keywords either.
     */
    private const RESERVED = [
        'abstract', 'and', 'array', 'as', 'break', 'callable', 'case', 'catch', 'class', 'clone', 'const',
        'continue', 'declare', 'default', 'do', 'echo', 'else', 'elseif', 'empty', 'enddeclare', 'endfor',
        'endforeach', 'endif', 'endswitch', 'endwhile', 'enum', 'eval', 'exit', 'extends', 'final', 'finally',
        'fn', 'for', 'foreach', 'function', 'global', 'goto', 'if', 'implements', 'include', 'include_once',
        'instanceof', 'insteadof', 'interface', 'isset', 'list', 'match', 'namespace', 'new', 'or', 'print',
        'private', 'protected', 'public', 'readonly', 'require', 'require_once', 'return', 'static', 'switch',
        'throw', 'trait', 'try', 'unset', 'use', 'var', 'while', 'xor', 'yield',
        'bool', 'false', 'float', 'int', 'iterable', 'mixed', 'never', 'null', 'object', 'parent', 'self',
        'string', 'true', 'void',
    ];

    private function __construct()
    {
    }

    public static function isValid(string $name): bool
    {
        return preg_match('/^[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*$/', $name) === 1;
    }

    public static function isReserved(string $name): bool
    {
        return in_array(strtolower($name), self::RESERVED, true);
    }
}
```

`src/Domain/Model/ClassName.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Model;

use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;

final class ClassName
{
    private string $namespace;

    private string $shortName;

    private function __construct(string $namespace, string $shortName)
    {
        $this->namespace = $namespace;
        $this->shortName = $shortName;
    }

    public static function fromFqcn(string $fqcn): self
    {
        $normalized = ltrim($fqcn, '\\');
        if ($normalized === '') {
            throw new InvalidModel(sprintf('Class name "%s" must not be empty.', $fqcn));
        }

        foreach (explode('\\', $normalized) as $segment) {
            if (!Identifier::isValid($segment)) {
                throw new InvalidModel(sprintf('"%s" is not a valid class name: segment "%s" is not a PHP identifier.', $fqcn, $segment));
            }

            if (Identifier::isReserved($segment)) {
                throw new InvalidModel(sprintf('"%s" is not a valid class name: "%s" is a reserved word.', $fqcn, $segment));
            }
        }

        $position = strrpos($normalized, '\\');
        if ($position === false) {
            return new self('', $normalized);
        }

        return new self((string) substr($normalized, 0, $position), (string) substr($normalized, $position + 1));
    }

    public function fqcn(): string
    {
        return $this->namespace === '' ? $this->shortName : $this->namespace . '\\' . $this->shortName;
    }

    public function namespace(): string
    {
        return $this->namespace;
    }

    public function shortName(): string
    {
        return $this->shortName;
    }

    public function equals(self $other): bool
    {
        return $this->fqcn() === $other->fqcn();
    }
}
```

> Каст `(string) substr(...)` нужен из-за сигнатуры 7.4 (`string|false`). Здесь `false` невозможен: позиция найдена внутри непустой строки, а после ведущего `\` она всегда меньше длины — это гарантирует проверка сегментов выше.

`src/Domain/Model/DocModel.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Model;

final class DocModel
{
    private ?string $description;

    private bool $deprecated;

    public function __construct(?string $description = null, bool $deprecated = false)
    {
        $trimmed = $description === null ? '' : trim($description);
        $this->description = $trimmed === '' ? null : $trimmed;
        $this->deprecated = $deprecated;
    }

    public static function none(): self
    {
        return new self();
    }

    public function description(): ?string
    {
        return $this->description;
    }

    public function isDeprecated(): bool
    {
        return $this->deprecated;
    }

    public function isEmpty(): bool
    {
        return $this->description === null && !$this->deprecated;
    }
}
```

- [ ] **Step 4: Реализовать типы**

`src/Domain/Model/TypeModel.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Model;

interface TypeModel
{
    /**
     * Canonical PHPDoc-style form; two types are equal when their descriptions are equal.
     */
    public function describe(): string;
}
```

`src/Domain/Model/ScalarType.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Model;

use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;

final class ScalarType implements TypeModel
{
    /** @var 'string'|'int'|'float'|'bool' */
    private string $kind;

    private ?string $phpDoc;

    /**
     * @param 'string'|'int'|'float'|'bool' $kind
     */
    private function __construct(string $kind, ?string $phpDoc)
    {
        if ($phpDoc !== null && trim($phpDoc) === '') {
            throw new InvalidModel(sprintf('The PHPDoc refinement of a %s type must not be blank.', $kind));
        }

        $this->kind = $kind;
        $this->phpDoc = $phpDoc;
    }

    public static function string(?string $phpDoc = null): self
    {
        return new self('string', $phpDoc);
    }

    public static function int(?string $phpDoc = null): self
    {
        return new self('int', $phpDoc);
    }

    public static function float(?string $phpDoc = null): self
    {
        return new self('float', $phpDoc);
    }

    public static function bool(): self
    {
        return new self('bool', null);
    }

    /**
     * @return 'string'|'int'|'float'|'bool'
     */
    public function kind(): string
    {
        return $this->kind;
    }

    /**
     * A narrower PHPDoc type such as `non-empty-string` or `int<1, 10>`.
     */
    public function phpDoc(): ?string
    {
        return $this->phpDoc;
    }

    public function describe(): string
    {
        return $this->phpDoc ?? $this->kind;
    }
}
```

`src/Domain/Model/ClassType.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Model;

final class ClassType implements TypeModel
{
    private ClassName $className;

    public function __construct(ClassName $className)
    {
        $this->className = $className;
    }

    public function className(): ClassName
    {
        return $this->className;
    }

    public function describe(): string
    {
        return $this->className->fqcn();
    }
}
```

`src/Domain/Model/ListType.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Model;

final class ListType implements TypeModel
{
    private TypeModel $item;

    public function __construct(TypeModel $item)
    {
        $this->item = $item;
    }

    public function item(): TypeModel
    {
        return $this->item;
    }

    public function describe(): string
    {
        return sprintf('list<%s>', $this->item->describe());
    }
}
```

`src/Domain/Model/MapType.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Model;

final class MapType implements TypeModel
{
    private TypeModel $value;

    public function __construct(TypeModel $value)
    {
        $this->value = $value;
    }

    public function value(): TypeModel
    {
        return $this->value;
    }

    public function describe(): string
    {
        return sprintf('array<string, %s>', $this->value->describe());
    }
}
```

`src/Domain/Model/MixedType.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Model;

final class MixedType implements TypeModel
{
    public function describe(): string
    {
        return 'mixed';
    }
}
```

`src/Domain/Model/NullableType.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Model;

use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;

final class NullableType implements TypeModel
{
    private TypeModel $inner;

    public function __construct(TypeModel $inner)
    {
        if ($inner instanceof self || $inner instanceof MixedType) {
            throw new InvalidModel(sprintf('"%s" already admits null and cannot be made nullable.', $inner->describe()));
        }

        $this->inner = $inner;
    }

    public function inner(): TypeModel
    {
        return $this->inner;
    }

    public function describe(): string
    {
        return $this->inner->describe() . '|null';
    }
}
```

`src/Domain/Model/UnionType.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Model;

use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;

final class UnionType implements TypeModel
{
    /** @var non-empty-list<TypeModel> */
    private array $members;

    public function __construct(TypeModel ...$members)
    {
        $flat = [];
        $seen = [];
        foreach ($members as $member) {
            if ($member instanceof NullableType || $member instanceof MixedType) {
                throw new InvalidModel(sprintf(
                    'Union member "%s" must not be nullable or mixed; wrap the whole union in NullableType instead.',
                    $member->describe(),
                ));
            }

            foreach ($member instanceof self ? $member->members() : [$member] as $part) {
                $key = $part->describe();
                if (!isset($seen[$key])) {
                    $seen[$key] = true;
                    $flat[] = $part;
                }
            }
        }

        if (count($flat) < 2) {
            throw new InvalidModel('A union type needs at least two distinct members.');
        }

        $this->members = $flat;
    }

    /**
     * @return non-empty-list<TypeModel>
     */
    public function members(): array
    {
        return $this->members;
    }

    public function describe(): string
    {
        return implode('|', array_map(static fn (TypeModel $member): string => $member->describe(), $this->members));
    }
}
```

- [ ] **Step 5: Прогнать тесты**

Run: `vendor/bin/phpunit --filter 'ClassNameTest|DocModelTest|TypeModelTest' && make test-74`
Expected: PASS.

- [ ] **Step 6: Статическая проверка**

Run: `make fix && make check`
Expected: без ошибок. Если PHPStan не выводит `non-empty-list` для `$flat` после `count($flat) < 2`, замените проверку на `if (count($flat) < 2 || $flat === [])` — это не ignore, а явное сужение типа.

- [ ] **Step 7: Commit**

```bash
git add src/Domain/Model tests/Unit/Domain/Model
git commit -m "feat(model): class names, docs and the type model

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 7: IR — атрибуты (`ArgumentValue`, `AttributeArgument`, `ImportAlias`, `AttributeModel`)

**Files:**
- Create: `src/Domain/Model/ArgumentValue.php`, `src/Domain/Model/AttributeArgument.php`, `src/Domain/Model/ImportAlias.php`, `src/Domain/Model/AttributeModel.php`
- Test: `tests/Unit/Domain/Model/ArgumentValueTest.php`, `tests/Unit/Domain/Model/AttributeModelTest.php`

**Interfaces:**
- Consumes: `ClassName`, `Identifier` (Task 6), `InvalidModel`, `Json` (Task 4).
- Produces:
  - `ArgumentValue`, виды: `KIND_LITERAL`, `KIND_LIST`, `KIND_MAP`, `KIND_CONSTANT`, `KIND_CLASS_REFERENCE`, `KIND_NEW_INSTANCE`.
    - Фабрики: `literal(JsonValue)` (не-скаляры отклоняются), `listOf(ArgumentValue ...)`, `mapOf(array<int|string, ArgumentValue>)`, `constant(string $name, ?ClassName $class = null)`, `classReference(ClassName)`, `newInstance(ClassName, AttributeArgument ...)`.
    - Accessor'ы: `kind()`, `literalValue(): JsonScalar`, `items(): array<int|string, ArgumentValue>`, `constantName(): string`, `constantClass(): ?ClassName`, `className(): ClassName`, `arguments(): list<AttributeArgument>`. Вызов accessor'а у значения другого вида бросает `\LogicException`.
  - `AttributeArgument::positional(ArgumentValue)`, `::named(string, ArgumentValue)`, `name(): ?string`, `value()`, `isNamed()`, `static assertWellFormed(list<AttributeArgument>): void`.
  - `ImportAlias::__construct(string $namespace, string $alias)`, `namespace(): string`, `alias(): string`.
  - `AttributeModel::__construct(ClassName $className, list<AttributeArgument> $arguments = [], ?ImportAlias $importAlias = null)`, `className()`, `arguments()`, `importAlias()`.

- [ ] **Step 1: Написать падающие тесты**

`tests/Unit/Domain/Model/ArgumentValueTest.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Domain\Model;

use LogicException;
use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\ArgumentValue;
use MSSTC4PHP\DtoGenerator\Domain\Model\AttributeArgument;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;
use PHPUnit\Framework\TestCase;

final class ArgumentValueTest extends TestCase
{
    /**
     * @dataProvider literals
     *
     * @param scalar|null $value
     */
    public function testHoldsScalarLiterals($value): void
    {
        $argument = ArgumentValue::literal($value);

        self::assertSame(ArgumentValue::KIND_LITERAL, $argument->kind());
        self::assertSame($value, $argument->literalValue());
    }

    /**
     * @return array<string, array{scalar|null}>
     */
    public static function literals(): array
    {
        return [
            'null' => [null],
            'bool' => [true],
            'int' => [4],
            'float' => [1.5],
            'string' => ['tail'],
        ];
    }

    /**
     * @dataProvider nonFiniteFloats
     */
    public function testRejectsNonFiniteFloats(float $value): void
    {
        $this->expectException(InvalidModel::class);
        $this->expectExceptionMessage('finite');

        ArgumentValue::literal($value);
    }

    /**
     * @return array<string, array{float}>
     */
    public static function nonFiniteFloats(): array
    {
        return [
            'infinity' => [INF],
            'negative infinity' => [-INF],
            'not a number' => [NAN],
        ];
    }

    public function testRejectsNonScalarLiterals(): void
    {
        $this->expectException(InvalidModel::class);

        ArgumentValue::literal([1]);
    }

    public function testHoldsListsAndMaps(): void
    {
        $one = ArgumentValue::literal(1);
        $list = ArgumentValue::listOf($one, $one);
        $map = ArgumentValue::mapOf(['keep' => $one, '200' => $one]);

        self::assertSame(ArgumentValue::KIND_LIST, $list->kind());
        self::assertSame([$one, $one], $list->items());
        self::assertSame(ArgumentValue::KIND_MAP, $map->kind());
        self::assertSame(['keep', '200'], array_map('strval', array_keys($map->items())));
    }

    public function testHoldsConstants(): void
    {
        $classConstant = ArgumentValue::constant('TAIL', ClassName::fromFqcn('App\Mask'));
        $globalConstant = ArgumentValue::constant('PHP_INT_MAX');

        self::assertSame(ArgumentValue::KIND_CONSTANT, $classConstant->kind());
        self::assertSame('TAIL', $classConstant->constantName());
        self::assertNotNull($classConstant->constantClass());
        self::assertSame('App\Mask', $classConstant->constantClass()->fqcn());
        self::assertNull($globalConstant->constantClass());
    }

    /**
     * @dataProvider invalidConstantNames
     */
    public function testRejectsInvalidConstantNames(string $name): void
    {
        $this->expectException(InvalidModel::class);

        ArgumentValue::constant($name, ClassName::fromFqcn('App\Mask'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidConstantNames(): array
    {
        return [
            'class keyword' => ['class'],
            'dash' => ['TAIL-END'],
            'empty' => [''],
        ];
    }

    public function testHoldsClassReferences(): void
    {
        $reference = ArgumentValue::classReference(ClassName::fromFqcn('App\User'));

        self::assertSame(ArgumentValue::KIND_CLASS_REFERENCE, $reference->kind());
        self::assertSame('App\User', $reference->className()->fqcn());
    }

    public function testHoldsNestedInstances(): void
    {
        $argument = AttributeArgument::named('min', ArgumentValue::literal(1));
        $instance = ArgumentValue::newInstance(ClassName::fromFqcn('App\Rule'), $argument);

        self::assertSame(ArgumentValue::KIND_NEW_INSTANCE, $instance->kind());
        self::assertSame('App\Rule', $instance->className()->fqcn());
        self::assertSame([$argument], $instance->arguments());
    }

    public function testNestedInstancesValidateArgumentOrder(): void
    {
        $this->expectException(InvalidModel::class);

        ArgumentValue::newInstance(
            ClassName::fromFqcn('App\Rule'),
            AttributeArgument::named('min', ArgumentValue::literal(1)),
            AttributeArgument::positional(ArgumentValue::literal(2)),
        );
    }

    public function testAccessorsOfAnotherKindThrow(): void
    {
        $this->expectException(LogicException::class);

        ArgumentValue::literal(1)->className();
    }
}
```

`tests/Unit/Domain/Model/AttributeModelTest.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Domain\Model;

use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\ArgumentValue;
use MSSTC4PHP\DtoGenerator\Domain\Model\AttributeArgument;
use MSSTC4PHP\DtoGenerator\Domain\Model\AttributeModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;
use MSSTC4PHP\DtoGenerator\Domain\Model\ImportAlias;
use PHPUnit\Framework\TestCase;

final class AttributeModelTest extends TestCase
{
    public function testHoldsClassArgumentsAndAlias(): void
    {
        $alias = new ImportAlias('\Symfony\Component\Validator\Constraints', 'Assert');
        $arguments = [
            AttributeArgument::positional(ArgumentValue::literal(1)),
            AttributeArgument::named('max', ArgumentValue::literal(10)),
        ];
        $attribute = new AttributeModel(ClassName::fromFqcn('Symfony\Component\Validator\Constraints\Length'), $arguments, $alias);

        self::assertSame('Symfony\Component\Validator\Constraints\Length', $attribute->className()->fqcn());
        self::assertSame($arguments, $attribute->arguments());
        self::assertSame($alias, $attribute->importAlias());
        self::assertSame('Symfony\Component\Validator\Constraints', $alias->namespace());
        self::assertSame('Assert', $alias->alias());
    }

    public function testArgumentsDefaultToNone(): void
    {
        $attribute = new AttributeModel(ClassName::fromFqcn('App\Sensitive'));

        self::assertSame([], $attribute->arguments());
        self::assertNull($attribute->importAlias());
    }

    public function testRejectsAnAliasOutsideTheClassNamespace(): void
    {
        $this->expectException(InvalidModel::class);
        $this->expectExceptionMessage('is not inside the import alias namespace');

        new AttributeModel(ClassName::fromFqcn('App\Sensitive'), [], new ImportAlias('Symfony\Component\Validator\Constraints', 'Assert'));
    }

    public function testRejectsAnInvalidAlias(): void
    {
        $this->expectException(InvalidModel::class);

        new ImportAlias('Symfony\Component\Validator\Constraints', 'As-sert');
    }

    public function testRejectsPositionalAfterNamed(): void
    {
        $this->expectException(InvalidModel::class);
        $this->expectExceptionMessage('Positional argument after named');

        AttributeArgument::assertWellFormed([
            AttributeArgument::named('max', ArgumentValue::literal(10)),
            AttributeArgument::positional(ArgumentValue::literal(1)),
        ]);
    }

    public function testRejectsDuplicateNamedArguments(): void
    {
        $this->expectException(InvalidModel::class);
        $this->expectExceptionMessage('Named argument "max" is repeated');

        AttributeArgument::assertWellFormed([
            AttributeArgument::named('max', ArgumentValue::literal(10)),
            AttributeArgument::named('max', ArgumentValue::literal(11)),
        ]);
    }

    public function testRejectsAnInvalidArgumentName(): void
    {
        $this->expectException(InvalidModel::class);

        AttributeArgument::named('max-length', ArgumentValue::literal(1));
    }

    public function testExposesArgumentParts(): void
    {
        $value = ArgumentValue::literal(1);

        self::assertFalse(AttributeArgument::positional($value)->isNamed());
        self::assertNull(AttributeArgument::positional($value)->name());
        self::assertTrue(AttributeArgument::named('min', $value)->isNamed());
        self::assertSame('min', AttributeArgument::named('min', $value)->name());
        self::assertSame($value, AttributeArgument::named('min', $value)->value());
    }
}
```

- [ ] **Step 2: Убедиться, что тесты падают**

Run: `vendor/bin/phpunit --filter 'ArgumentValueTest|AttributeModelTest'`
Expected: FAIL — `Class ... not found`.

- [ ] **Step 3: Реализовать**

`src/Domain/Model/AttributeArgument.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Model;

use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;

final class AttributeArgument
{
    private ?string $name;

    private ArgumentValue $value;

    private function __construct(?string $name, ArgumentValue $value)
    {
        $this->name = $name;
        $this->value = $value;
    }

    public static function positional(ArgumentValue $value): self
    {
        return new self(null, $value);
    }

    public static function named(string $name, ArgumentValue $value): self
    {
        if (!Identifier::isValid($name)) {
            throw new InvalidModel(sprintf('Argument name "%s" is not a PHP identifier.', $name));
        }

        return new self($name, $value);
    }

    /**
     * PHP's own call rules: positional arguments first, each name once.
     *
     * @param list<self> $arguments
     */
    public static function assertWellFormed(array $arguments): void
    {
        $named = [];
        foreach ($arguments as $argument) {
            if ($argument->name === null) {
                if ($named !== []) {
                    throw new InvalidModel('Positional argument after named arguments.');
                }

                continue;
            }

            if (isset($named[$argument->name])) {
                throw new InvalidModel(sprintf('Named argument "%s" is repeated.', $argument->name));
            }

            $named[$argument->name] = true;
        }
    }

    public function name(): ?string
    {
        return $this->name;
    }

    public function value(): ArgumentValue
    {
        return $this->value;
    }

    public function isNamed(): bool
    {
        return $this->name !== null;
    }
}
```

`src/Domain/Model/ArgumentValue.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Model;

use LogicException;
use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Json;

/**
 * A value inside an attribute's argument list, renderable both as a PHP attribute and as an annotation.
 *
 * @phpstan-import-type JsonScalar from Json
 * @phpstan-import-type JsonValue from Json
 */
final class ArgumentValue
{
    public const KIND_LITERAL = 'literal';
    public const KIND_LIST = 'list';
    public const KIND_MAP = 'map';
    public const KIND_CONSTANT = 'constant';
    public const KIND_CLASS_REFERENCE = 'class-reference';
    public const KIND_NEW_INSTANCE = 'new-instance';

    /** @var self::KIND_* */
    private string $kind;

    /** @var JsonScalar */
    private $literal;

    /** @var array<int|string, ArgumentValue> */
    private array $items;

    private ?ClassName $class;

    private ?string $constant;

    /** @var list<AttributeArgument> */
    private array $arguments;

    /**
     * @param self::KIND_* $kind
     * @param JsonScalar $literal
     * @param array<int|string, ArgumentValue> $items
     * @param list<AttributeArgument> $arguments
     */
    private function __construct(string $kind, $literal, array $items, ?ClassName $class, ?string $constant, array $arguments)
    {
        $this->kind = $kind;
        $this->literal = $literal;
        $this->items = $items;
        $this->class = $class;
        $this->constant = $constant;
        $this->arguments = $arguments;
    }

    /**
     * Accepts any JSON value because callers feed decoded `x-` extensions; non-scalars are rejected.
     *
     * @param JsonValue $value
     */
    public static function literal($value): self
    {
        if ($value !== null && !is_scalar($value)) {
            throw new InvalidModel(sprintf('A literal argument must be a scalar or null, %s given.', gettype($value)));
        }

        if (is_float($value) && !is_finite($value)) {
            throw new InvalidModel('A float argument must be finite; INF and NAN have no PHP literal.');
        }

        return new self(self::KIND_LITERAL, $value, [], null, null, []);
    }

    public static function listOf(self ...$items): self
    {
        return new self(self::KIND_LIST, null, $items, null, null, []);
    }

    /**
     * @param array<int|string, self> $items
     */
    public static function mapOf(array $items): self
    {
        return new self(self::KIND_MAP, null, $items, null, null, []);
    }

    public static function constant(string $name, ?ClassName $class = null): self
    {
        if (!Identifier::isValid($name) || strtolower($name) === 'class') {
            throw new InvalidModel(sprintf('"%s" is not a valid constant name; use classReference() for ::class.', $name));
        }

        return new self(self::KIND_CONSTANT, null, [], $class, $name, []);
    }

    public static function classReference(ClassName $class): self
    {
        return new self(self::KIND_CLASS_REFERENCE, null, [], $class, null, []);
    }

    public static function newInstance(ClassName $class, AttributeArgument ...$arguments): self
    {
        AttributeArgument::assertWellFormed($arguments);

        return new self(self::KIND_NEW_INSTANCE, null, [], $class, null, $arguments);
    }

    /**
     * @return self::KIND_*
     */
    public function kind(): string
    {
        return $this->kind;
    }

    /**
     * @return JsonScalar
     */
    public function literalValue()
    {
        $this->assertKind(self::KIND_LITERAL);

        return $this->literal;
    }

    /**
     * @return array<int|string, ArgumentValue>
     */
    public function items(): array
    {
        $this->assertKind(self::KIND_LIST, self::KIND_MAP);

        return $this->items;
    }

    public function constantName(): string
    {
        $this->assertKind(self::KIND_CONSTANT);
        if ($this->constant === null) {
            throw new LogicException('A constant argument without a name.');
        }

        return $this->constant;
    }

    /**
     * @return ClassName|null null for a global constant
     */
    public function constantClass(): ?ClassName
    {
        $this->assertKind(self::KIND_CONSTANT);

        return $this->class;
    }

    public function className(): ClassName
    {
        $this->assertKind(self::KIND_CLASS_REFERENCE, self::KIND_NEW_INSTANCE);
        if ($this->class === null) {
            throw new LogicException(sprintf('A %s argument without a class.', $this->kind));
        }

        return $this->class;
    }

    /**
     * @return list<AttributeArgument>
     */
    public function arguments(): array
    {
        $this->assertKind(self::KIND_NEW_INSTANCE);

        return $this->arguments;
    }

    private function assertKind(string ...$kinds): void
    {
        if (!in_array($this->kind, $kinds, true)) {
            throw new LogicException(sprintf('Argument of kind "%s" is not one of: %s.', $this->kind, implode(', ', $kinds)));
        }
    }
}
```

`src/Domain/Model/ImportAlias.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Model;

use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;

/**
 * `use <namespace> as <alias>;` — e.g. Symfony constraints as `Assert`, which annotations rely on.
 */
final class ImportAlias
{
    private string $namespace;

    private string $alias;

    public function __construct(string $namespace, string $alias)
    {
        if (!Identifier::isValid($alias) || Identifier::isReserved($alias)) {
            throw new InvalidModel(sprintf('Import alias "%s" is not a usable PHP identifier.', $alias));
        }

        $this->namespace = ClassName::fromFqcn($namespace)->fqcn();
        $this->alias = $alias;
    }

    public function namespace(): string
    {
        return $this->namespace;
    }

    public function alias(): string
    {
        return $this->alias;
    }
}
```

`src/Domain/Model/AttributeModel.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Model;

use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;

final class AttributeModel
{
    private ClassName $className;

    /** @var list<AttributeArgument> */
    private array $arguments;

    private ?ImportAlias $importAlias;

    /**
     * @param list<AttributeArgument> $arguments
     */
    public function __construct(ClassName $className, array $arguments = [], ?ImportAlias $importAlias = null)
    {
        AttributeArgument::assertWellFormed($arguments);

        if ($importAlias !== null && strpos($className->fqcn(), $importAlias->namespace() . '\\') !== 0) {
            throw new InvalidModel(sprintf(
                'Attribute class %s is not inside the import alias namespace %s.',
                $className->fqcn(),
                $importAlias->namespace(),
            ));
        }

        $this->className = $className;
        $this->arguments = $arguments;
        $this->importAlias = $importAlias;
    }

    public function className(): ClassName
    {
        return $this->className;
    }

    /**
     * @return list<AttributeArgument>
     */
    public function arguments(): array
    {
        return $this->arguments;
    }

    public function importAlias(): ?ImportAlias
    {
        return $this->importAlias;
    }
}
```

- [ ] **Step 4: Прогнать тесты**

Run: `vendor/bin/phpunit --filter 'ArgumentValueTest|AttributeModelTest' && make test-74`
Expected: PASS.

- [ ] **Step 5: Статическая проверка**

Run: `make fix && make check`
Expected: без ошибок.

- [ ] **Step 6: Commit**

```bash
git add src/Domain/Model tests/Unit/Domain/Model
git commit -m "feat(model): attribute model with typed argument values

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 8: IR — свойства, классы и enum'ы

**Files:**
- Create: `src/Domain/Model/PropertyModel.php`, `src/Domain/Model/ClassKind.php`, `src/Domain/Model/DiscriminatorModel.php`, `src/Domain/Model/ClassModel.php`, `src/Domain/Model/EnumBacking.php`, `src/Domain/Model/EnumCase.php`, `src/Domain/Model/EnumModel.php`
- Test: `tests/Unit/Domain/Model/PropertyModelTest.php`, `tests/Unit/Domain/Model/ClassModelTest.php`, `tests/Unit/Domain/Model/EnumModelTest.php`

**Interfaces:**
- Consumes: `ClassName`, `DocModel`, `TypeModel`, `NullableType`, `Identifier` (Task 6); `AttributeModel` (Task 7); `SchemaLocation` (Task 4); `DefaultValue` (Task 5); `Mutability` (Task 3); `AbstractEnum` (Task 1).
- Produces:
  - `PropertyModel::__construct(string $name, string $wireName, TypeModel $type, bool $required, ?DefaultValue $default, DocModel $doc, SchemaLocation $source, list<AttributeModel> $attributes = [])`.
    Методы: `name()`, `wireName()`, `type()`, `isRequired()`, `default()`, `doc()`, `source()`, `attributes()`, `isNullable()`, `withAttributes(AttributeModel ...): PropertyModel`.
  - `ClassKind extends AbstractEnum`: `FINAL`, `OPEN`, `ABSTRACT`.
  - `DiscriminatorModel::__construct(string $propertyName, array<int|string, ClassName> $mapping)`, `propertyName()`, `values(): list<string>`, `classFor(string): ?ClassName`.
  - `ClassModel::__construct(ClassName $name, ClassKind $kind, ?ClassName $parent, list<PropertyModel> $properties, Mutability $mutability, DocModel $doc, SchemaLocation $source, list<AttributeModel> $attributes = [], ?DiscriminatorModel $discriminator = null)`.
    Методы: `name()`, `kind()`, `parent()`, `properties()`, `property(string): ?PropertyModel`, `mutability()`, `doc()`, `source()`, `attributes()`, `discriminator()`, `withAttributes(AttributeModel ...)`, `withProperties(PropertyModel ...)`.
  - `EnumBacking extends AbstractEnum`: `STRING`, `INT`.
  - `EnumCase::__construct(string $name, int|string $value, ?DocModel $doc = null)`, `name()`, `value()`, `doc()`.
  - `EnumModel::__construct(ClassName $name, EnumBacking $backing, list<EnumCase> $cases, DocModel $doc, SchemaLocation $source)`, `name()`, `backing()`, `cases(): non-empty-list<EnumCase>`, `doc()`, `source()`.

- [ ] **Step 1: Написать падающие тесты**

`tests/Unit/Domain/Model/PropertyModelTest.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Domain\Model;

use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\AttributeModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;
use MSSTC4PHP\DtoGenerator\Domain\Model\DocModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\NullableType;
use MSSTC4PHP\DtoGenerator\Domain\Model\PropertyModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\ScalarType;
use MSSTC4PHP\DtoGenerator\Domain\Model\TypeModel;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;
use MSSTC4PHP\DtoGenerator\Domain\Shared\DefaultValue;
use PHPUnit\Framework\TestCase;

final class PropertyModelTest extends TestCase
{
    public function testExposesItsParts(): void
    {
        $default = new DefaultValue('guest');
        $doc = new DocModel('Login name');
        $source = new SchemaLocation('a.json', '/properties/user_name');
        $property = new PropertyModel('userName', 'user_name', ScalarType::string(), false, $default, $doc, $source);

        self::assertSame('userName', $property->name());
        self::assertSame('user_name', $property->wireName());
        self::assertSame('string', $property->type()->describe());
        self::assertFalse($property->isRequired());
        self::assertSame($default, $property->default());
        self::assertSame($doc, $property->doc());
        self::assertSame($source, $property->source());
        self::assertSame([], $property->attributes());
        self::assertFalse($property->isNullable());
    }

    public function testKnowsWhenItIsNullable(): void
    {
        self::assertTrue(self::property('email', new NullableType(ScalarType::string()))->isNullable());
    }

    public function testWithAttributesAppendsWithoutTouchingTheOriginal(): void
    {
        $first = new AttributeModel(ClassName::fromFqcn('App\First'));
        $second = new AttributeModel(ClassName::fromFqcn('App\Second'));
        $original = self::property('email')->withAttributes($first);
        $extended = $original->withAttributes($second);

        self::assertSame([$first], $original->attributes());
        self::assertSame([$first, $second], $extended->attributes());
    }

    /**
     * @dataProvider invalidNames
     */
    public function testRejectsInvalidPropertyNames(string $name): void
    {
        $this->expectException(InvalidModel::class);

        self::property($name);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidNames(): array
    {
        return [
            'empty' => [''],
            'dash' => ['user-name'],
            'leading digit' => ['2fa'],
            'this' => ['this'],
        ];
    }

    public function testRejectsAnEmptyWireName(): void
    {
        $this->expectException(InvalidModel::class);

        new PropertyModel('email', '', ScalarType::string(), true, null, DocModel::none(), new SchemaLocation('a.json'));
    }

    private static function property(string $name, ?TypeModel $type = null): PropertyModel
    {
        return new PropertyModel(
            $name,
            $name === '' ? 'empty' : $name,
            $type ?? ScalarType::string(),
            true,
            null,
            DocModel::none(),
            new SchemaLocation('a.json'),
        );
    }
}
```

`tests/Unit/Domain/Model/ClassModelTest.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Domain\Model;

use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\AttributeModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassKind;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;
use MSSTC4PHP\DtoGenerator\Domain\Model\DiscriminatorModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\DocModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\PropertyModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\ScalarType;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;
use MSSTC4PHP\DtoGenerator\Domain\Target\Mutability;
use PHPUnit\Framework\TestCase;

final class ClassModelTest extends TestCase
{
    public function testExposesItsParts(): void
    {
        $id = self::property('id');
        $parent = ClassName::fromFqcn('App\Base');
        $class = new ClassModel(
            ClassName::fromFqcn('App\User'),
            ClassKind::from(ClassKind::FINAL),
            $parent,
            [$id],
            Mutability::from(Mutability::IMMUTABLE),
            new DocModel('A user'),
            new SchemaLocation('a.json', '/components/schemas/User'),
        );

        self::assertSame('App\User', $class->name()->fqcn());
        self::assertSame(ClassKind::from(ClassKind::FINAL), $class->kind());
        self::assertSame($parent, $class->parent());
        self::assertSame([$id], $class->properties());
        self::assertSame($id, $class->property('id'));
        self::assertNull($class->property('missing'));
        self::assertSame(Mutability::from(Mutability::IMMUTABLE), $class->mutability());
        self::assertSame('A user', $class->doc()->description());
        self::assertSame('a.json#/components/schemas/User', $class->source()->toString());
        self::assertSame([], $class->attributes());
        self::assertNull($class->discriminator());
    }

    public function testRejectsDuplicatePropertyNames(): void
    {
        $this->expectException(InvalidModel::class);
        $this->expectExceptionMessage('declares property "id" twice');

        self::classWith([self::property('id', 'id'), self::property('id', 'ID')]);
    }

    public function testRejectsDuplicateWireNames(): void
    {
        $this->expectException(InvalidModel::class);
        $this->expectExceptionMessage('maps wire name "200" twice');

        self::classWith([self::property('ok', '200'), self::property('success', '200')]);
    }

    public function testRejectsExtendingItself(): void
    {
        $this->expectException(InvalidModel::class);

        new ClassModel(
            ClassName::fromFqcn('App\User'),
            ClassKind::from(ClassKind::OPEN),
            ClassName::fromFqcn('\App\User'),
            [],
            Mutability::from(Mutability::MUTABLE),
            DocModel::none(),
            new SchemaLocation('a.json'),
        );
    }

    public function testOnlyAbstractClassesCarryADiscriminator(): void
    {
        $this->expectException(InvalidModel::class);
        $this->expectExceptionMessage('Only an abstract class');

        self::classWith([], ClassKind::FINAL, new DiscriminatorModel('kind', ['cat' => ClassName::fromFqcn('App\Cat')]));
    }

    public function testAbstractClassKeepsItsDiscriminator(): void
    {
        $discriminator = new DiscriminatorModel('kind', ['cat' => ClassName::fromFqcn('App\Cat'), '1' => ClassName::fromFqcn('App\One')]);
        $class = self::classWith([], ClassKind::ABSTRACT, $discriminator);

        self::assertSame($discriminator, $class->discriminator());
        self::assertSame('kind', $discriminator->propertyName());
        self::assertSame(['cat', '1'], $discriminator->values());
        self::assertNotNull($discriminator->classFor('1'));
        self::assertSame('App\One', $discriminator->classFor('1')->fqcn());
        self::assertNull($discriminator->classFor('dog'));
    }

    public function testDiscriminatorNeedsAMapping(): void
    {
        $this->expectException(InvalidModel::class);

        new DiscriminatorModel('kind', []);
    }

    public function testWithMethodsReturnValidatedCopies(): void
    {
        $class = self::classWith([self::property('id')]);
        $attribute = new AttributeModel(ClassName::fromFqcn('App\Marker'));

        self::assertSame([$attribute], $class->withAttributes($attribute)->attributes());
        self::assertSame([], $class->attributes());
        self::assertCount(2, $class->withProperties(self::property('id'), self::property('email'))->properties());

        $this->expectException(InvalidModel::class);
        $class->withProperties(self::property('id'), self::property('id'));
    }

    /**
     * @param list<PropertyModel> $properties
     */
    private static function classWith(array $properties, string $kind = ClassKind::FINAL, ?DiscriminatorModel $discriminator = null): ClassModel
    {
        return new ClassModel(
            ClassName::fromFqcn('App\User'),
            ClassKind::from($kind),
            null,
            $properties,
            Mutability::from(Mutability::IMMUTABLE),
            DocModel::none(),
            new SchemaLocation('a.json'),
            [],
            $discriminator,
        );
    }

    private static function property(string $name, ?string $wireName = null): PropertyModel
    {
        return new PropertyModel($name, $wireName ?? $name, ScalarType::string(), true, null, DocModel::none(), new SchemaLocation('a.json'));
    }
}
```

`tests/Unit/Domain/Model/EnumModelTest.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Domain\Model;

use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;
use MSSTC4PHP\DtoGenerator\Domain\Model\DocModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\EnumBacking;
use MSSTC4PHP\DtoGenerator\Domain\Model\EnumCase;
use MSSTC4PHP\DtoGenerator\Domain\Model\EnumModel;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;
use PHPUnit\Framework\TestCase;

final class EnumModelTest extends TestCase
{
    public function testExposesItsParts(): void
    {
        $active = new EnumCase('Active', 'active', new DocModel('Can log in'));
        $enum = self::enum(EnumBacking::STRING, [$active, new EnumCase('Blocked', 'blocked')]);

        self::assertSame('App\Status', $enum->name()->fqcn());
        self::assertSame(EnumBacking::from(EnumBacking::STRING), $enum->backing());
        self::assertCount(2, $enum->cases());
        self::assertSame('Active', $active->name());
        self::assertSame('active', $active->value());
        self::assertSame('Can log in', $active->doc()->description());
        self::assertTrue((new EnumCase('Blocked', 'blocked'))->doc()->isEmpty());
        self::assertSame('a.json#/components/schemas/Status', $enum->source()->toString());
        self::assertTrue($enum->doc()->isEmpty());
    }

    public function testAcceptsIntBackedCases(): void
    {
        $enum = self::enum(EnumBacking::INT, [new EnumCase('One', 1), new EnumCase('Two', 2)]);

        self::assertSame(1, $enum->cases()[0]->value());
    }

    public function testRejectsANumericStringInAnIntEnum(): void
    {
        $this->expectException(InvalidModel::class);
        $this->expectExceptionMessage('does not match the int backing');

        self::enum(EnumBacking::INT, [new EnumCase('One', '1')]);
    }

    public function testRejectsAnIntInAStringEnum(): void
    {
        $this->expectException(InvalidModel::class);

        self::enum(EnumBacking::STRING, [new EnumCase('One', 1)]);
    }

    public function testRejectsDuplicateValues(): void
    {
        $this->expectException(InvalidModel::class);
        $this->expectExceptionMessage('repeats value "active"');

        self::enum(EnumBacking::STRING, [new EnumCase('Active', 'active'), new EnumCase('Enabled', 'active')]);
    }

    public function testRejectsDuplicateNames(): void
    {
        $this->expectException(InvalidModel::class);
        $this->expectExceptionMessage('repeats case "Active"');

        self::enum(EnumBacking::STRING, [new EnumCase('Active', 'a'), new EnumCase('Active', 'b')]);
    }

    public function testRejectsAnEnumWithoutCases(): void
    {
        $this->expectException(InvalidModel::class);

        self::enum(EnumBacking::STRING, []);
    }

    /**
     * @dataProvider invalidCaseNames
     */
    public function testRejectsInvalidCaseNames(string $name): void
    {
        $this->expectException(InvalidModel::class);

        new EnumCase($name, 'x');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidCaseNames(): array
    {
        return [
            'class keyword' => ['class'],
            'class keyword uppercase' => ['CLASS'],
            'dash' => ['in-progress'],
            'leading digit' => ['1st'],
        ];
    }

    /**
     * @param list<EnumCase> $cases
     */
    private static function enum(string $backing, array $cases): EnumModel
    {
        return new EnumModel(
            ClassName::fromFqcn('App\Status'),
            EnumBacking::from($backing),
            $cases,
            DocModel::none(),
            new SchemaLocation('a.json', '/components/schemas/Status'),
        );
    }
}
```

- [ ] **Step 2: Убедиться, что тесты падают**

Run: `vendor/bin/phpunit --filter 'PropertyModelTest|ClassModelTest|EnumModelTest'`
Expected: FAIL — `Class ... not found`.

- [ ] **Step 3: Реализовать свойства и классы**

`src/Domain/Model/PropertyModel.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Model;

use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;
use MSSTC4PHP\DtoGenerator\Domain\Shared\DefaultValue;

final class PropertyModel
{
    private string $name;

    private string $wireName;

    private TypeModel $type;

    private bool $required;

    private ?DefaultValue $default;

    private DocModel $doc;

    private SchemaLocation $source;

    /** @var list<AttributeModel> */
    private array $attributes;

    /**
     * @param string $wireName the name in the schema, kept for serialization
     * @param list<AttributeModel> $attributes
     */
    public function __construct(
        string $name,
        string $wireName,
        TypeModel $type,
        bool $required,
        ?DefaultValue $default,
        DocModel $doc,
        SchemaLocation $source,
        array $attributes = []
    ) {
        if (!Identifier::isValid($name) || strtolower($name) === 'this') {
            throw new InvalidModel(sprintf('"%s" is not a usable PHP property name (%s).', $name, $source->toString()));
        }

        if ($wireName === '') {
            throw new InvalidModel(sprintf('Property "%s" has an empty wire name (%s).', $name, $source->toString()));
        }

        $this->name = $name;
        $this->wireName = $wireName;
        $this->type = $type;
        $this->required = $required;
        $this->default = $default;
        $this->doc = $doc;
        $this->source = $source;
        $this->attributes = $attributes;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function wireName(): string
    {
        return $this->wireName;
    }

    public function type(): TypeModel
    {
        return $this->type;
    }

    public function isRequired(): bool
    {
        return $this->required;
    }

    public function default(): ?DefaultValue
    {
        return $this->default;
    }

    public function doc(): DocModel
    {
        return $this->doc;
    }

    public function source(): SchemaLocation
    {
        return $this->source;
    }

    /**
     * @return list<AttributeModel>
     */
    public function attributes(): array
    {
        return $this->attributes;
    }

    public function isNullable(): bool
    {
        return $this->type instanceof NullableType;
    }

    public function withAttributes(AttributeModel ...$attributes): self
    {
        $copy = clone $this;
        $copy->attributes = array_merge($this->attributes, $attributes);

        return $copy;
    }
}
```

`src/Domain/Model/ClassKind.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Model;

use MSSTC4PHP\DtoGenerator\Domain\Shared\AbstractEnum;

final class ClassKind extends AbstractEnum
{
    public const FINAL = 'final';
    /** Non-final: an `allOf` base that other DTOs extend. */
    public const OPEN = 'open';
    public const ABSTRACT = 'abstract';

    protected static function values(): array
    {
        return [self::FINAL, self::OPEN, self::ABSTRACT];
    }
}
```

`src/Domain/Model/DiscriminatorModel.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Model;

use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;

final class DiscriminatorModel
{
    private string $propertyName;

    /**
     * Keys are discriminator values; PHP turns numeric ones into ints, hence `int|string`.
     *
     * @var non-empty-array<int|string, ClassName>
     */
    private array $mapping;

    /**
     * @param string $propertyName wire name of the discriminating property
     * @param array<int|string, ClassName> $mapping discriminator value → concrete class
     */
    public function __construct(string $propertyName, array $mapping)
    {
        if ($propertyName === '') {
            throw new InvalidModel('Discriminator property name must not be empty.');
        }

        if ($mapping === []) {
            throw new InvalidModel(sprintf('Discriminator "%s" has no mapping.', $propertyName));
        }

        $this->propertyName = $propertyName;
        $this->mapping = $mapping;
    }

    public function propertyName(): string
    {
        return $this->propertyName;
    }

    /**
     * @return list<string>
     */
    public function values(): array
    {
        return array_map('strval', array_keys($this->mapping));
    }

    public function classFor(string $value): ?ClassName
    {
        return $this->mapping[$value] ?? null;
    }
}
```

`src/Domain/Model/ClassModel.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Model;

use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;
use MSSTC4PHP\DtoGenerator\Domain\Target\Mutability;

final class ClassModel
{
    private ClassName $name;

    private ClassKind $kind;

    private ?ClassName $parent;

    /** @var list<PropertyModel> */
    private array $properties;

    private Mutability $mutability;

    private DocModel $doc;

    private SchemaLocation $source;

    /** @var list<AttributeModel> */
    private array $attributes;

    private ?DiscriminatorModel $discriminator;

    /**
     * @param list<PropertyModel> $properties own properties only, in schema order
     * @param list<AttributeModel> $attributes
     */
    public function __construct(
        ClassName $name,
        ClassKind $kind,
        ?ClassName $parent,
        array $properties,
        Mutability $mutability,
        DocModel $doc,
        SchemaLocation $source,
        array $attributes = [],
        ?DiscriminatorModel $discriminator = null
    ) {
        if ($parent !== null && $parent->equals($name)) {
            throw new InvalidModel(sprintf('Class %s cannot extend itself.', $name->fqcn()));
        }

        if ($discriminator !== null && !$kind->equals(ClassKind::from(ClassKind::ABSTRACT))) {
            throw new InvalidModel(sprintf('Only an abstract class can carry a discriminator; %s is %s.', $name->fqcn(), $kind->value()));
        }

        $names = [];
        $wireNames = [];
        foreach ($properties as $property) {
            if (isset($names[$property->name()])) {
                throw new InvalidModel(sprintf('Class %s declares property "%s" twice.', $name->fqcn(), $property->name()));
            }

            if (isset($wireNames[$property->wireName()])) {
                throw new InvalidModel(sprintf('Class %s maps wire name "%s" twice.', $name->fqcn(), $property->wireName()));
            }

            $names[$property->name()] = true;
            $wireNames[$property->wireName()] = true;
        }

        $this->name = $name;
        $this->kind = $kind;
        $this->parent = $parent;
        $this->properties = $properties;
        $this->mutability = $mutability;
        $this->doc = $doc;
        $this->source = $source;
        $this->attributes = $attributes;
        $this->discriminator = $discriminator;
    }

    public function name(): ClassName
    {
        return $this->name;
    }

    public function kind(): ClassKind
    {
        return $this->kind;
    }

    public function parent(): ?ClassName
    {
        return $this->parent;
    }

    /**
     * @return list<PropertyModel>
     */
    public function properties(): array
    {
        return $this->properties;
    }

    public function property(string $name): ?PropertyModel
    {
        foreach ($this->properties as $property) {
            if ($property->name() === $name) {
                return $property;
            }
        }

        return null;
    }

    public function mutability(): Mutability
    {
        return $this->mutability;
    }

    public function doc(): DocModel
    {
        return $this->doc;
    }

    public function source(): SchemaLocation
    {
        return $this->source;
    }

    /**
     * @return list<AttributeModel>
     */
    public function attributes(): array
    {
        return $this->attributes;
    }

    public function discriminator(): ?DiscriminatorModel
    {
        return $this->discriminator;
    }

    public function withAttributes(AttributeModel ...$attributes): self
    {
        return new self(
            $this->name,
            $this->kind,
            $this->parent,
            $this->properties,
            $this->mutability,
            $this->doc,
            $this->source,
            array_merge($this->attributes, $attributes),
            $this->discriminator,
        );
    }

    public function withProperties(PropertyModel ...$properties): self
    {
        return new self(
            $this->name,
            $this->kind,
            $this->parent,
            $properties,
            $this->mutability,
            $this->doc,
            $this->source,
            $this->attributes,
            $this->discriminator,
        );
    }
}
```

- [ ] **Step 4: Реализовать enum'ы**

`src/Domain/Model/EnumBacking.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Model;

use MSSTC4PHP\DtoGenerator\Domain\Shared\AbstractEnum;

final class EnumBacking extends AbstractEnum
{
    public const STRING = 'string';
    public const INT = 'int';

    protected static function values(): array
    {
        return [self::STRING, self::INT];
    }
}
```

`src/Domain/Model/EnumCase.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Model;

use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;

final class EnumCase
{
    private string $name;

    /** @var int|string */
    private $value;

    private DocModel $doc;

    /**
     * @param int|string $value
     */
    public function __construct(string $name, $value, ?DocModel $doc = null)
    {
        // A class constant (and an enum case) must not be called "class".
        if (!Identifier::isValid($name) || strtolower($name) === 'class') {
            throw new InvalidModel(sprintf('"%s" is not a usable enum case name.', $name));
        }

        if (!is_int($value) && !is_string($value)) {
            throw new InvalidModel(sprintf('Enum case "%s" must have an int or string value.', $name));
        }

        $this->name = $name;
        $this->value = $value;
        $this->doc = $doc ?? DocModel::none();
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * @return int|string
     */
    public function value()
    {
        return $this->value;
    }

    public function doc(): DocModel
    {
        return $this->doc;
    }
}
```

`src/Domain/Model/EnumModel.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Model;

use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;

final class EnumModel
{
    private ClassName $name;

    private EnumBacking $backing;

    /** @var non-empty-list<EnumCase> */
    private array $cases;

    private DocModel $doc;

    private SchemaLocation $source;

    /**
     * @param list<EnumCase> $cases
     */
    public function __construct(ClassName $name, EnumBacking $backing, array $cases, DocModel $doc, SchemaLocation $source)
    {
        if ($cases === []) {
            throw new InvalidModel(sprintf('Enum %s has no cases.', $name->fqcn()));
        }

        $isInt = $backing->equals(EnumBacking::from(EnumBacking::INT));
        $names = [];
        $values = [];
        foreach ($cases as $case) {
            if (is_int($case->value()) !== $isInt) {
                throw new InvalidModel(sprintf(
                    'Enum %s case "%s" has value %s, which does not match the %s backing.',
                    $name->fqcn(),
                    $case->name(),
                    var_export($case->value(), true),
                    $backing->value(),
                ));
            }

            if (isset($names[$case->name()])) {
                throw new InvalidModel(sprintf('Enum %s repeats case "%s".', $name->fqcn(), $case->name()));
            }

            if (isset($values[$case->value()])) {
                throw new InvalidModel(sprintf('Enum %s repeats value "%s".', $name->fqcn(), $case->value()));
            }

            $names[$case->name()] = true;
            $values[$case->value()] = true;
        }

        $this->name = $name;
        $this->backing = $backing;
        $this->cases = $cases;
        $this->doc = $doc;
        $this->source = $source;
    }

    public function name(): ClassName
    {
        return $this->name;
    }

    public function backing(): EnumBacking
    {
        return $this->backing;
    }

    /**
     * @return non-empty-list<EnumCase>
     */
    public function cases(): array
    {
        return $this->cases;
    }

    public function doc(): DocModel
    {
        return $this->doc;
    }

    public function source(): SchemaLocation
    {
        return $this->source;
    }
}
```

> `$values[$case->value()]` безопасен: при проверке backing'а все значения одного типа, а у string-enum ключи `"1"` и `"01"` остаются разными строками.

- [ ] **Step 5: Прогнать тесты**

Run: `vendor/bin/phpunit --filter 'PropertyModelTest|ClassModelTest|EnumModelTest' && make test-74`
Expected: PASS.

- [ ] **Step 6: Статическая проверка**

Run: `make fix && make check`
Expected: без ошибок.

- [ ] **Step 7: Commit**

```bash
git add src/Domain/Model tests/Unit/Domain/Model
git commit -m "feat(model): property, class and enum models

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 9: Закрытие этапа — порог мутаций, README, база знаний

**Files:**
- Modify: `infection.json5` (поля `minMsi`, `minCoveredMsi`)
- Create: `README.md`
- Create: `.claude/docs/README.md`, `.claude/docs/architecture.md`, `.claude/docs/domain-model.md`, `.claude/docs/conventions.md`, `.claude/docs/known-issues.md`

**Interfaces:**
- Consumes: всё из Tasks 1–8.
- Produces: зафиксированный порог MSI; документацию.

- [ ] **Step 1: Полный прогон**

Run: `make check && make test && make test-74`
Expected: всё зелёное, на обеих версиях PHP одинаковое число тестов.

- [ ] **Step 2: Мутационное тестирование**

Run: `make infection`
Expected: отчёт с `Mutation Score Indicator (MSI)` и `Covered Code MSI`. Если выжившие мутанты указывают на непроверенное поведение (а не на эквивалентные мутации, как недостижимый `LogicException` в `AbstractEnum::tryFrom`), добавьте тесты в соответствующие `*Test.php` и прогоните заново.

- [ ] **Step 3: Зафиксировать порог**

В `infection.json5` замените комментарий и значения: каждое значение = измеренное − 2, с округлением вниз до целого. Пример для MSI 91.4 % / Covered 94.8 %:

```json5
    // Floor two points below the level measured at the end of stage 1.
    "minMsi": 89,
    "minCoveredMsi": 92,
```

Run: `make infection`
Expected: завершается успешно (порог не нарушен).

- [ ] **Step 4: README пакета**

`README.md`:

````markdown
# msstc4php/dto-generator

Генератор PHP DTO из схем OpenAPI 3.1 (`components/schemas`, YAML/JSON) с учётом целевой версии PHP (7.4–8.5).

> **Статус:** в разработке. Готов этап 1 — доменная модель (схема, IR, профиль целевой версии).
> Генерация файлов, CLI, Composer-плагин и Docker-образ появятся в следующих этапах.

Дизайн: [`docs/specs/2026-10-01-dto-generator-design.md`](docs/specs/2026-10-01-dto-generator-design.md).

## Требования

- PHP ≥ 7.4 для запуска генератора.
- Для разработки: PHP 8.x локально и Docker (тесты и lint на 7.4).

## Разработка

```bash
make install   # зависимости пакета и инструментов (tools/)
make check     # PHPStan, CS-Fixer, Rector, deptrac, lint на PHP 7.4
make test      # PHPUnit на локальном PHP
make test-74   # PHPUnit в контейнере php:7.4-cli
make infection # мутационное тестирование
make fix       # автоисправление стиля и Rector
```

## Лицензия

MIT
````

- [ ] **Step 5: База знаний `.claude/docs/`**

`.claude/docs/README.md`:

```markdown
# dto-generator — база знаний

Внутренние заметки для работы над пакетом. Спецификация: `docs/specs/2026-10-01-dto-generator-design.md`; планы: `docs/plans/`.

| Файл | О чём |
|---|---|
| [architecture.md](architecture.md) | Слои, правило зависимостей, где что лежит |
| [domain-model.md](domain-model.md) | Schema, IR, TargetProfile — инварианты |
| [conventions.md](conventions.md) | Правила кода, обязательные из-за рантайма PHP 7.4 |
| [known-issues.md](known-issues.md) | Подводные камни и ограничения |
```

`.claude/docs/architecture.md`:

```markdown
# Архитектура

Hex-подход, адаптированный под библиотеку; правило зависимостей проверяет deptrac (`deptrac.yaml`).

| Слой | Каталог | Может зависеть от |
|---|---|---|
| Domain | `src/Domain/{Schema,Model,Target,Shared,Exception}` | — |
| DomainService | `src/Domain/Builder` | Domain |
| Contract (SPI) | `src/Contract` | Domain |
| Application | `src/Application` | Domain, DomainService, Contract |
| Infrastructure | `src/Infrastructure` | Domain, Application, Contract, Symfony, PhpParser, Composer |
| Presentation | `src/Presentation` | Application, Domain, Contract, Symfony, Composer |
| Extension | `src/Extension` | Contract, Domain |

Состояние на 2026-10-01 (UTC): реализован только Domain (этап 1). Остальные каталоги появятся в этапах 2–6.

- `Domain/Schema` — разобранная JSON Schema; `$ref` хранится как строка, разрешение — вне домена.
- `Domain/Model` — IR, из которого Emitter строит код. Enricher'ы (SPI) только **добавляют** `AttributeModel`.
- `Domain/Target` — `TargetProfile`: версия PHP + режимы; все решения «можно ли на этой версии» идут через `Capability`.
- Инструменты в `tools/` (отдельный `composer.json`), чтобы `require-dev` пакета ставился на PHP 7.4.
- Открытый вопрос к этапу 3: Presentation (CLI) будет точкой сборки и должен инстанцировать адаптеры Infrastructure — правило deptrac для этого придётся расширить или вынести composition root.
```

`.claude/docs/domain-model.md`:

```markdown
# Доменная модель

## Schema (`Domain/Schema`)
- `Schema` — неизменяемый узел; собирается через `SchemaBuilder`. Ключевые слова со своими accessor'ами (`Schema::STRUCTURAL_KEYWORDS`) и `x-*` нельзя класть в `keywords` — один источник истины.
- `SchemaLocation` — файл + JSON pointer (RFC 6901, `~`→`~0`, `/`→`~1`); используется в каждой диагностике.
- `Extensions` — только ключи `x-<name>`; порядок — как в документе.
- `default` обёрнут в `DefaultValue`: «default = null» ≠ «default нет».

## IR (`Domain/Model`)
- `TypeModel`: `ScalarType` (с PHPDoc-уточнением), `ClassType`, `ListType`, `MapType` (ключи всегда string), `UnionType` (≥2 разных, плоский, без nullable/mixed), `NullableType` (не вкладывается, не над mixed), `MixedType`. Равенство типов — по `describe()`.
- `ClassName` отклоняет зарезервированные слова в любом сегменте (консервативно, под PHP 7.4) — переименование (суффикс `_`) делает Builder, не модель.
- `PropertyModel.wireName` — исходное имя из схемы (для `SerializedName` в мосте).
- `ClassModel`: уникальны и PHP-имена, и wire-имена; discriminator только у `ClassKind::ABSTRACT`; `with*()` пересоздают объект и заново проверяют инварианты.
- `EnumModel`: значения строго типа backing'а (`'1'` в int-enum — ошибка), без дублей имён и значений; case `class` запрещён.
- `AttributeModel` + `ArgumentValue` (literal / list / map / constant / class-reference / new-instance) — общий вход для рендереров атрибутов и аннотаций. Порядок аргументов: позиционные, затем именованные без повторов. `INF`/`NAN` запрещены.

## Target (`Domain/Target`)
- `Capability` — матрица «возможность → минимальная версия PHP» (spec §6.1). Новая версия PHP = строка в `PhpVersion::SUPPORTED` + строки в матрице.
- `TargetProfile` валидирует сразу: `metadata=attributes` требует 8.0; `public-properties` для immutable требует readonly (8.1). `accessorsFor()` разрешает `auto` по мутабельности конкретного класса (её можно переопределить `x-dto-mutable`).
```

`.claude/docs/conventions.md`:

```markdown
# Конвенции проекта

Отличия от глобальных правил, продиктованные рантаймом PHP 7.4:

- Нет нативных enum → наследники `Domain/Shared/AbstractEnum` (`X::from(X::CONST)`, сравнение `===` или `equals()`); экземпляры интернированы.
- Нет `readonly` → `private` типизированные свойства + геттеры, `final` классы, `with*()` через `clone`/`new self`.
- Нет union-типов и `mixed` в сигнатурах → тип в PHPDoc, нативный тип опущен. JSON-значения — алиас `JsonValue` из `Domain/Shared/Json` (`@phpstan-import-type JsonValue from Json`).
- В многострочных списках **параметров** висячая запятая запрещена (PHP 8.0); в вызовах и массивах — обязательна (CS-Fixer настроен так).
- Функции 8.0+ (`str_contains`, `str_starts_with`…) не использовать: `strncmp`/`strpos`.
- PHPUnit 9.6: `@dataProvider` в аннотациях, провайдеры `public static`.
- Проверка «код парсится на 7.4» — `make lint-74`, входит в `make check`; тесты на 7.4 — `make test-74`.
- Нарушение инварианта модели → `Domain\Exception\InvalidModel`; вызов accessor'а не того вида → `\LogicException`.
```

`.claude/docs/known-issues.md`:

```markdown
# Известные особенности

- **Числовые ключи PHP-массивов.** Имена свойств (`"200"`) и значения discriminator (`"1"`) PHP хранит как `int`-ключи. Внутри — `array<int|string, …>`, наружу — только через `propertyNames()` / `values()`, которые приводят к строке. Поиск по строке работает (PHP приводит ключ сам).
- **`AbstractEnum` и сериализация.** Экземпляры интернированы; `unserialize` создал бы второй экземпляр и сломал `===`. Модель не сериализуется — не добавлять.
- **`ClassName` консервативен.** Отклоняет зарезервированные слова и в сегментах namespace, хотя PHP 8.0+ их там допускает: сгенерированный код должен парситься и на 7.4.
- **Различие «нет ключа / null»** в v1 не моделируется (spec §5.2).
- **PHPStan и JSON.** Алиасы типов PHPStan не бывают рекурсивными, поэтому `JsonValue` = `JsonScalar|array<array-key, mixed>`.
```

- [ ] **Step 6: Финальная проверка и коммит**

Run: `make check && make test && make test-74`
Expected: всё зелёное.

```bash
git add infection.json5 README.md .claude/docs
git commit -m "docs: stage 1 README, knowledge base and mutation floor

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

- [ ] **Step 7: Закрытие итерации по глобальным правилам**

Запустить `/acc:code-review high` по ветке. Это новый модуль, поэтому уровень high. Исправить все находки, включая Suggestions. Повторить `make check && make test && make test-74`. Если исправления были нетривиальными, прогнать ревью ещё раз. Дополнить `.claude/docs/known-issues.md` всем неочевидным, что всплыло при ревью.

---

## Следующие планы

Каждый план пишется после завершения предыдущего этапа, по spec §13:

2. Загрузчик YAML/JSON, `$ref` (внутренние и внешние), модель конфига. Builder: скаляры, объекты, массивы, nullable, `format`, `description`.
3. Emitter (php-parser) для всех версий PHP, Writer + манифест, CLI, golden-матрица, проверка вывода в Docker-матрице, CI.
4. Композиция: enum, `allOf`, `oneOf`/`anyOf` + discriminator, `additionalProperties`, вынос инлайн-схем.
5. SPI, встроенное `CustomAttributes` + алиасы, рендерер аннотаций.
6. Composer-плагин, автодетект окружения, обнаружение расширений, Docker-образ.
