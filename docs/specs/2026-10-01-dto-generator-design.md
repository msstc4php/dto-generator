# DTO Generator — дизайн ядра

- **Дата:** 2026-10-01 (UTC)
- **Статус:** проект, ожидает вычитки
- **Формат:** design doc (спека), предшествует плану реализации
- **Пакет:** `msstc4php/dto-generator`
- **Связанный пакет:** `msstc4php/dto-generator-bridge-symfony` — отдельный
  репозиторий и отдельная спека (см. §12)

---

## 1. Контекст и цель

Библиотека генерирует PHP-классы DTO из схем моделей OpenAPI 3.1
(`components/schemas`, JSON Schema 2020-12), заданных в YAML или JSON.

Ключевые свойства:

- генерирует **immutable** и **mutable** DTO;
- знает **целевую версию PHP** (7.4 … 8.5) и использует её возможности;
- переносит `description` в PHPDoc, `format` — в PHP-тип;
- превращает `x-…` расширения в **произвольные атрибуты произвольных классов**;
- через публичный SPI позволяет расширениям (в первую очередь мосту Symfony)
  добавлять атрибуты Symfony Validator и Symfony Serializer.

Сгенерированный код — обычные `.php`-файлы, которые коммитятся в проект-
потребитель и **не имеют рантайм-зависимости** от генератора.

### Критерии успеха

1. Один и тот же вход (схемы + конфиг) даёт побайтно одинаковый вывод.
2. Сгенерированный код синтаксически корректен на целевой версии PHP,
   проходит PHPStan max и выполняется на этой версии (проверяется в CI).
3. Генератор сам работает на PHP 7.4+.
4. Все ошибки входа сообщаются разом, с указанием места в схеме.

### Вне рамок ядра

- Генерация клиентов, контроллеров, путей (`paths`) — только модели.
- Атрибуты Symfony Validator/Serializer — в мосте (§12).
- Symfony bundle, `bin/console`, `cache:warmup` — в мосте.
- Загрузка `$ref` по HTTP — только локальные файлы.
- Различие «ключ отсутствует / ключ равен `null`» (§5.2).

## 2. Ограничения

| Ограничение | Следствие |
|---|---|
| Рантайм генератора — PHP ≥ 7.4 | Исходники ядра без enum, readonly, атрибутов, `match`, union-типов, promoted-свойств. Строгость — через PHPDoc + PHPStan max с `phpVersion: 70400`. |
| Целевой код — PHP 7.4 … 8.5 | Вся версия-зависимость сосредоточена в `TargetProfile` (§6). |
| Метаданные на 7.4 — только docblock-аннотации | Одна модель атрибутов, два рендерера: атрибуты и аннотации. |
| Рантайм-зависимости | `nikic/php-parser ^5`, `symfony/yaml ^5.4\|^6.4\|^7`, `symfony/console ^5.4\|^6.4\|^7`, `composer-plugin-api ^2`. Все совместимы с 7.4. |

Выбор `nikic/php-parser`: библиотека сама запускается на 7.4, но строит и
печатает AST с синтаксисом любой версии (readonly class, enum, `clone` with).
Это снимает конфликт «рантайм 7.4 → цель 8.5».

## 3. Архитектура

Hex-подход, адаптированный под библиотеку. Правило зависимостей проверяет
deptrac.

| Слой | Каталог | Содержимое | Зависит от |
|---|---|---|---|
| Domain | `src/Domain/Schema/` | Неизменяемая модель разобранной JSON Schema: `Schema`, `SchemaType`, `Discriminator`, `Extensions` (`x-*`), `SchemaLocation` (файл + JSON pointer) | — |
| Domain | `src/Domain/Model/` | IR: `ClassModel`, `EnumModel`, `PropertyModel`, `TypeModel`, `AttributeModel`, `ArgumentValue`, `DocModel` | — |
| Domain | `src/Domain/Target/` | `TargetProfile`, `PhpVersion`, `Capability`, `MetadataMode`, `Mutability`, `AccessorStyle` | — |
| DomainService | `src/Domain/Builder/` | Schema → IR: `TypeMapper` (+ format-таблица), `CompositionResolver`, `NameResolver`, `InlineSchemaHoister` | Domain |
| Contract | `src/Contract/` | Публичный SPI: `Extension`, `ExtensionRegistry`, `PropertyEnricher`, `ClassEnricher`, `PropertyContext`, `ClassContext`, `FormatMapping`, `InstalledPackages`, `Diagnostics` | Domain |
| Application | `src/Application/Service/Generate/` | `Action` / `Input` / `Output` — единственный use-case | Domain, DomainService, Contract |
| Application | `src/Application/Port/` | `DocumentLoader`, `RefResolver`, `CodeEmitter`, `FileWriter`, `EnvironmentDetector` | Domain |
| Application | `src/Application/Config/` | Модель конфига и его валидация | Domain |
| Infrastructure | `src/Infrastructure/` | `YamlJsonDocumentLoader`, `FilesystemRefResolver`, `PhpParserEmitter` (+ `AttributeRenderer`, `AnnotationRenderer`), `FilesystemWriter`, `ComposerEnvironmentDetector` | Domain, Application, Contract |
| Presentation | `src/Presentation/Cli/`, `src/Presentation/Composer/` | `GenerateCommand`, `bin/dto-generator`, `ComposerPlugin` | Application |
| Extension | `src/Extension/CustomAttributes/` | Встроенное расширение `x-php-attributes` + алиасы, реализует SPI | Contract, Domain |

### Поток данных

```
Config ─► DocumentLoader + RefResolver ─► Schema (по каждому source)
       ─► Builder ─► IR (ClassModel/EnumModel)
       ─► Enrichers (встроенные + расширения) ─► AttributeModel на узлах IR
       ─► TargetProfile: проверка capabilities (strict → ошибка, иначе деградация + warning)
       ─► CodeEmitter (IR → AST → PrettyPrinter) ─► набор файлов в памяти
       ─► FileWriter (манифест, --check / --dry-run, атомарная запись)
```

Генерация идёт в два прохода: сначала строится и обогащается весь IR, затем
он эмитится. Ошибки копятся в `Diagnostics`. Если есть хотя бы одна ошибка,
ничего не записывается.

## 4. Конфигурация

Файл `dto-generator.yaml` (поддерживается и `.json`). Пути считаются
относительно файла конфига. Неизвестный ключ — ошибка конфига.

```yaml
version: 1
target:
  php: auto            # auto | '7.4' … '8.5'
  metadata: auto       # auto | attributes | annotations | none
  strict: true
dto:
  mutability: immutable        # immutable | mutable
  accessors: auto              # auto | getters | public-properties
  dateTimeClass: DateTimeImmutable   # DateTimeImmutable | DateTime
  allOfStrategy: extends       # extends | merge
formats:
  uuid: { type: Symfony\Component\Uid\Uuid }
attributeAliases:
  x-audit: { class: App\Attr\Audited, args: { level: '{value}' } }
verifyClasses: auto            # auto | true | false
discoverExtensions: true       # автообнаружение через composer extra (§8)
extensions:
  - MSSTC4PHP\DtoGeneratorBridgeSymfony\SymfonyExtension
extensionConfig:
  symfony: { version: auto }
sources:
  - spec: openapi/public.yaml
    namespace: App\Dto\Public
    outputDir: src/Dto/Public
    include: ['*']
    exclude: []
```

### Правила разрешения `auto`

| Ключ | `auto` означает |
|---|---|
| `target.php` | Нижняя граница из `require.php` в `composer.json` рядом с конфигом (или выше по дереву); если файла нет — `7.4` |
| `target.metadata` | `< 8.0` → `annotations`, иначе `attributes` |
| `dto.accessors` | По таблице §6.2 |
| `verifyClasses` | `true`, если найден `vendor/autoload.php` потребителя и не задано `DTO_GENERATOR_VERIFY_CLASSES=0`, иначе `false` |

### Несколько источников

- `include`/`exclude` — glob-фильтры по именам `components/schemas`.
- `$ref` в файл, принадлежащий другому `source`, → класс из namespace того
  источника.
- `$ref` в файл вне всех `sources` → схема выносится в namespace текущего
  источника.
- Одна и та же схема, достижимая из двух источников без принадлежности ни к
  одному, → ошибка (неоднозначный namespace).

## 5. Маппинг схемы в IR

### 5.1 Типы

| Схема | PHP-тип | PHPDoc |
|---|---|---|
| `string` | `string` | `non-empty-string` при `minLength ≥ 1` |
| `integer` | `int` | `int<a, b>` / `positive-int` / `non-negative-int` по `minimum`/`maximum`/`exclusive*` |
| `number` | `float` | — |
| `boolean` | `bool` | — |
| `format: email, uri, uuid, hostname, ipv4, ipv6, time, byte, binary` | `string` | — |
| `format: date-time`, `date` | `\DateTimeImmutable` или `\DateTime` (`dateTimeClass`, переопределение — `x-php-type`) | — |
| `format: int32`, `int64` | `int` | — |
| `format: float`, `double` | `float` | — |
| формат из `formats` конфига | указанный класс | — |
| `type: [T, "null"]` | `?T` | — |
| `array` + `items` | `array` | `list<T>` |
| объект только с `additionalProperties: <schema>` | `array` | `array<array-key, T>` ¹ |
| `properties` + `additionalProperties` | доп. свойство `$additionalProperties` | `array<array-key, T>` ¹ |
| `$ref` | класс / enum цели | — |
| `x-php-type` | указанный FQCN | — |

¹ Ключ — `array-key`, а не `string`: PHP хранит ключи JSON-объекта в канонической десятичной записи целого (`"200"`, `"-5"`) как `int`; `"01"` или `"1.0"` остаются строками. Решение владельца от 2026-10-01 (UTC).

Неизвестный `format` даёт базовый тип и warning. Расширения регистрируют
дополнительные форматы через SPI.

### 5.2 Обязательность и значения по умолчанию

- В `required` и не nullable → обязательный аргумент конструктора без
  значения по умолчанию.
- Иначе — `?T`, значение по умолчанию берётся из `default` схемы, при его
  отсутствии `null`.
- Порядок аргументов конструктора: обязательные в порядке `properties`, затем
  необязательные в порядке `properties`.
- **Ограничение v1:** «ключ отсутствует» и «ключ = `null`» не различаются.
  Записывается в known limitations.

### 5.3 Композиция

| Конструкция | Результат |
|---|---|
| `enum` однотипный string/int | 8.1+: `enum Name: string\|int` с case'ами. 7.4/8.0: `final class Name` с константами; свойство типа `string`/`int`, PHPDoc `Name::*` |
| `enum` со смешанными типами | Ошибка генерации |
| `allOf` с одним `$ref` + свои `properties`, стратегия `extends` | `class Child extends Base`; `Base` генерируется не-`final`; конструктор наследника принимает параметры родителя и свои, вызывает `parent::__construct`; на 8.2+ наследник — `readonly class` |
| `allOf` с несколькими `$ref` или стратегия `merge` (`allOfStrategy` / `x-php-all-of`) | Поля сливаются в один класс; конфликт типов одного поля — ошибка |
| `oneOf`/`anyOf` + `discriminator` | `abstract class Name` (общий набор полей — пересечение); варианты наследуются от него. Маппинг discriminator сохраняется в IR (`ClassModel::$discriminator`) — для моста |
| `oneOf`/`anyOf` без `discriminator` | 8.0+: union-тип `A\|B`. 7.4: свойство без нативного типа, PHPDoc `A\|B` |
| Инлайн-объект / инлайн-enum | Выносится в именованный класс `<Parent><Property>` (PascalCase) |
| Коллизия имён классов | Ошибка с подсказкой `x-php-class-name` |

### 5.4 Именование

- Имя класса — имя схемы в PascalCase, переопределение — `x-php-class-name`.
- Имя свойства — camelCase от исходного имени, переопределение — `x-php-name`.
- Исходное имя сохраняется в `PropertyModel::$wireName`, из него мост строит
  `SerializedName`.
- Зарезервированные слова и недопустимые идентификаторы: имена классов
  получают суффикс `_`, свойства — нормализуются до допустимого
  идентификатора. Если после нормализации получились совпадения — ошибка.

### 5.5 Документация

- `description` схемы → PHPDoc класса или enum.
- `description` свойства → PHPDoc свойства; для promoted-параметров (8.0+) —
  PHPDoc параметра конструктора.
- `description` значения enum (через `x-enum-descriptions`) → PHPDoc case'а
  или константы.
- Последовательность `*/` в тексте экранируется.
- `deprecated: true` → `@deprecated`.
- Заголовок каждого файла:
  `@generated by msstc4php/dto-generator — DO NOT EDIT`.

## 6. TargetProfile и форма кода

### 6.1 Capabilities

| Capability | С версии |
|---|---|
| Typed properties | 7.4 |
| Constructor promotion, union types, атрибуты, `mixed` | 8.0 |
| Readonly properties, enums, `new` в инициализаторах | 8.1 |
| Readonly classes, `null`/`false` standalone | 8.2 |
| Typed class constants | 8.3 |
| Asymmetric visibility, property hooks | 8.4 |
| `clone` with properties | 8.5 |

Если конструкция требует capability, которой нет у target: при
`strict: true` — ошибка, при `false` — деградация (например, union → PHPDoc)
и warning. Новая версия PHP добавляется строкой в матрице и (при
необходимости) веткой в emitter.

### 6.2 Форма класса

| | 7.4 | 8.0 | 8.1 | 8.2–8.4 | 8.5 |
|---|---|---|---|---|---|
| immutable | `final class`, private typed props, `getX()` | + promoted ctor | public `readonly` promoted props | `final readonly class` | как 8.2 |
| `with*()` в immutable | `$c = clone $this; $c->x = $x;` | то же | `new self(...)` | `new self(...)` | `clone($this, ['x' => $x])` |
| mutable, `accessors: getters` (по умолчанию) | private props, `getX()`, `setX(): self` | + promoted ctor | то же | то же | то же |
| mutable, `accessors: public-properties` | public typed props | то же | то же | то же | то же |

- `accessors: auto`: для immutable — getters на 7.4/8.0, public readonly на
  8.1+; для mutable — getters.
- `accessors: public-properties` для immutable на 7.4/8.0 — ошибка конфига
  (нет readonly).
- `x-dto-mutable` на схеме переопределяет `dto.mutability`.
- Классы `final`, кроме баз `allOf`/discriminator.
- Возможности 8.3/8.4 (typed constants, asymmetric visibility, hooks) для DTO
  не используются: валидация — забота Symfony Validator.

## 7. `x-` словарь ядра

| Ключ | Где | Значение |
|---|---|---|
| `x-php-class-name` | схема | Имя класса/enum |
| `x-php-name` | свойство | Имя PHP-свойства |
| `x-php-type` | схема / свойство | FQCN PHP-типа |
| `x-dto-mutable` | схема | `true` / `false` |
| `x-php-all-of` | схема | `extends` / `merge` |
| `x-php-skip` | схема / свойство | Исключить из генерации |
| `x-php-attributes` | схема / свойство | Произвольные атрибуты (§7.1) |
| `x-enum-descriptions` | схема с `enum` | Map «значение → описание» |
| ключи из `attributeAliases` | схема / свойство | Атрибут по шаблону (§7.2) |

- Неизвестный ключ с префиксом `x-php-` или `x-dto-` → ошибка (ловит опечатки).
- Прочие `x-*` игнорируются, если ни одно расширение не заявило их через
  `claimExtensionKeys`.

### 7.1 Грамматика `x-php-attributes`

```yaml
x-php-attributes:
  - class: App\Attr\Sensitive
  - class: App\Attr\Mask
    args:                    # map → именованные, list → позиционные
      keep: 4
      mode: { const: App\Mask::TAIL }
      target: { class: App\Model\User }          # → User::class
      inner: { new: { class: App\Attr\Rule, args: [1] } }
```

| Значение | Рендер |
|---|---|
| скаляр / `null` | литерал |
| list | `[...]` |
| map (вне `args`) | `['k' => ...]` |
| `{const: 'A::B'}` | `A::B` (константа или enum case) |
| `{class: 'Foo'}` | `Foo::class` |
| `{new: {class, args}}` | `new Foo(...)` (атрибуты 8.1+) / вложенная аннотация |

- Map с единственным ключом `const`/`class`/`new` всегда трактуется как
  маркер. Буквальная map с таким ключом — через `{literal: {...}}`.
- Именованные аргументы требуют 8.0+ (атрибуты). В режиме аннотаций они
  рендерятся как `key=value`, позиционные — как значение `value`.
- `{new: …}` на 8.0 в режиме атрибутов → capability-ошибка/деградация (§6.1).
- При `verifyClasses: true` существование классов атрибутов и констант
  проверяется через autoloader потребителя.

### 7.2 Алиасы

```yaml
attributeAliases:
  x-audit:
    class: App\Attr\Audited
    args: { level: '{value}', by: '{value.user}' }
```

- Ключ алиаса должен начинаться с `x-` и не пересекаться со словарём ядра
  или ключами, заявленными расширениями.
- `{value}` — всё значение расширения с сохранением типа (если плейсхолдер
  занимает всю строку); `{value.<key>}` — поле map-значения.
- Отсутствующий `<key>` → ошибка.

## 8. SPI для расширений

Интерфейсы — на PHP 7.4, типы уточняются PHPDoc.

```php
interface Extension
{
    /** @return non-empty-string ключ секции в extensionConfig */
    public function name(): string;

    /** @param array<string, mixed> $config секция extensionConfig.<name> как есть */
    public function register(ExtensionRegistry $registry, array $config): void;
}

interface ExtensionRegistry
{
    public function addPropertyEnricher(PropertyEnricher $enricher): void;
    public function addClassEnricher(ClassEnricher $enricher): void;
    public function addFormat(string $format, FormatMapping $mapping): void;
    public function claimExtensionKeys(string ...$globs): void;
}

interface PropertyEnricher
{
    /** @return list<AttributeModel> */
    public function enrichProperty(PropertyContext $context): array;
}

interface ClassEnricher
{
    /** @return list<AttributeModel> */
    public function enrichClass(ClassContext $context): array;
}
```

- **Контексты только для чтения:**
  - узел IR (`PropertyModel` / `ClassModel`, включая `wireName` и `discriminator`);
  - исходный узел `Schema` со всеми keywords и `x-*`;
  - `TargetProfile`;
  - `InstalledPackages` (версии из `composer.lock` потребителя);
  - `Diagnostics` (warning/error с `SchemaLocation`).
- Enricher не мутирует IR, а только возвращает атрибуты.
- `AttributeModel`:
  - FQCN класса и аргументы (`ArgumentValue`);
  - предпочтительный импорт (например, `Symfony\Component\Validator\Constraints`
    `as Assert`);
  - цель: класс, свойство или параметр конструктора.
- `FormatMapping`: PHP-тип и PHPDoc-тип. Constraints в нём нет: их добавляет
  enricher расширения.
- Конфликт: два расширения регистрируют один и тот же формат → ошибка.

### Подключение расширений

1. Явно — список FQCN в `extensions` конфига.
2. Автообнаружение — `extra.dto-generator.extensions` в `composer.json`
   установленных пакетов (читается из `vendor/composer/installed.json` той
   установки, из которой запущен генератор: vendor проекта при обычной
   установке, vendor образа в Docker; глобально установленный генератор
   расширений проекта не видит). Отключается через `discoverExtensions: false`.

Порядок применения: сначала встроенное `CustomAttributes`, затем явные
расширения в порядке конфига, затем обнаруженные — по имени пакета.
Атрибуты на узле выводятся в этом порядке.

## 9. Запись, CLI, Composer, Docker

### 9.1 Запись

- Сначала в памяти собирается полный набор файлов, затем он записывается.
  Каждый файл пишется атомарно: временный файл + `rename`.
- В каждом `outputDir` хранится `.dto-generator.manifest.json`: отсортированный
  список путей и sha256 содержимого.
- Файлы из манифеста, которых нет в новом наборе, удаляются.
- Существующий файл без `@generated`-заголовка не перезаписывается — ошибка.
- Неизменённые файлы не трогаются (mtime сохраняется).

### 9.2 CLI

```
vendor/bin/dto-generator generate [--config=dto-generator.yaml] [--check] [--dry-run] [--format=text|json]
```

| Код | Значение |
|---|---|
| 0 | Успех / `--check` без расхождений |
| 1 | `--check`: есть расхождения |
| 2 | Ошибки генерации (диагностика) |
| 3 | Ошибка конфигурации |

- Конфиг по умолчанию ищется как `dto-generator.yaml`, затем
  `dto-generator.json` в текущем каталоге.
- Формат диагностики:
  `error public.yaml#/components/schemas/User/properties/email: <сообщение>`.
- `--format=json` выводит машиночитаемый отчёт для CI.

### 9.3 Composer-плагин

- Срабатывает на `post-autoload-dump`, если задан
  `extra.dto-generator.config`.
- По умолчанию ошибки выводятся как warning. `extra.dto-generator.failOnError:
  true` роняет команду composer.
- При `--no-plugins` / `--no-scripts` не запускается.

### 9.4 Docker

- `docker/Dockerfile`, multi-stage.
  - Stage 1: `composer install --no-dev` — ядро, и мост, когда он выйдет.
  - Stage 2: `php:8.4-cli-alpine`, non-root пользователь, `WORKDIR /app`,
    `ENTRYPOINT ["dto-generator", "generate"]`.
- Запуск:
  ```bash
  docker run --rm -u "$(id -u):$(id -g)" -v "$PWD:/app" <image> --config=dto-generator.yaml [--check]
  ```
- `target.php: auto` в контейнере определяется по `composer.json`
  смонтированного проекта.
- В образе задано `DTO_GENERATOR_VERIFY_CLASSES=0`: при `verifyClasses: auto`
  проверка классов выключена. Иначе контейнеру пришлось бы подключать
  autoloader потребителя, то есть выполнять его код. Явное `verifyClasses:
  true` в конфиге или переменная `=1` включают проверку.
- Реестр и имя образа определяются в плане.

## 10. Обработка ошибок

| Класс | Примеры | Поведение |
|---|---|---|
| Конфиг | неизвестный ключ, нет файла спеки, недопустимая комбинация (`public-properties` + immutable на 7.4) | Код 3, генерация не начинается |
| Схема | битый YAML/JSON, неразрешимый `$ref`, цикл `$ref` без объекта-разрыва, смешанный `enum`, коллизия имён, конфликт полей в `merge` | Сбор всех, код 2 |
| Target | capability отсутствует при `strict` | Ошибка (код 2) или warning + деградация |
| SPI | конфликт форматов, конфликт заявленных ключей, исключение в enricher | Ошибка с именем расширения |
| Запись | файл без `@generated`, нет прав | Код 2, ранее записанное не откатывается — поэтому запись идёт только после полного успеха генерации |

Рекурсивные `$ref` (дерево, граф) допустимы: в IR это ссылка на класс, а не
встраивание.

## 11. Тестирование и инструменты

### 11.1 Тесты

| Уровень | Содержание |
|---|---|
| Unit Domain / DomainService | `TargetProfile` и матрица capabilities; Schema → IR на малых фикстурах: типы, nullable, format, композиция, имена, коллизии |
| Unit Emitter | IR → код по версиям × mutability × accessors × metadata-режим |
| Golden end-to-end | `tests/Fixtures/<case>/{openapi.yaml, config.yaml, expected/<target>/…}`; профили 7.4, 8.0, 8.1, 8.2, 8.5; обновление снапшотов — `UPDATE_SNAPSHOTS=1` |
| Проверка вывода на целевой версии | Docker-матрица `php:7.4…8.5-cli`: `php -l` каждого файла, runtime-скрипт (создание DTO, `with*`/сеттеры, enum), PHPStan max по выводу со стабами атрибутов |
| Детерминизм | Два прогона подряд → идентичные файлы и манифест |
| Функциональные | CLI через `CommandTester` (флаги, коды выхода, `--format=json`); Composer-плагин во временном проекте |
| Mutation | Infection с минимальным MSI (порог — как в `vo-base`) |

### 11.2 Инструменты

- В `require-dev` пакета только то, что ставится на 7.4: `phpunit/phpunit ^9.6`.
- PHPStan 2 (max, strict-rules, disallowed-calls, `phpVersion: 70400`),
  deptrac, Rector 2 (`withPhpSets(php74: true)`), PHP-CS-Fixer, Infection —
  в `tools/` с отдельным `composer.json`.
- `Makefile`: `check`, `fix`, `test`, `test-targets` (Docker-матрица вывода),
  `docker-build`.

### 11.3 CI

- Unit и функциональные тесты: матрица PHP 7.4…8.5 × `--prefer-lowest` /
  `highest`.
- Отдельный job проверки вывода по целевым версиям.
- Сборка Docker-образа.
- Платформа CI (GitHub Actions / GitLab CI) — решается в плане.

### 11.4 Документация

- `README.md`, `docs/configuration.md`, `docs/x-extensions.md`,
  `docs/extensions-spi.md`, `CHANGELOG.md`.
- `.claude/docs/` — внутренняя база знаний.

## 12. Мост `dto-generator-bridge-symfony` (отдельная спека)

Отдельный репозиторий `msstc4php/dto-generator-bridge-symfony`. Здесь
фиксируется только граница с ядром.

- **Enrichers:**
  - Symfony Validator constraints — из стандартных keywords (`minLength`,
    `maximum`, `pattern`, `format`, `enum`, `required`, …) и `x-`
    расширений моста;
  - Symfony Serializer атрибуты — `SerializedName` из `wireName`,
    `DiscriminatorMap` из `discriminator`, `Context` для дат,
    `x-serializer-*`;
  - регистрация через SPI §8.
- **Bundle:** конфиг в `config/packages`, команды `bin/console`,
  встраивание в `cache:warmup`.
- Учёт версии Symfony (5.4 … 7.x), автодетект через `InstalledPackages` или
  ядро Symfony.
- Мост работает в рантайме генератора, поэтому его минимум — PHP 7.4.
- Docker-образ ядра включает мост после его первого релиза.

## 13. Этапы реализации ядра

1. Каркас репозитория, инструменты, deptrac; Domain: Schema, IR, `TargetProfile`.
2. `DocumentLoader`, `RefResolver` (внутренние и внешние `$ref`), конфиг;
   Builder: скаляры, объекты, массивы, nullable, `format`, `description`.
3. Emitter для всех версий PHP (immutable/mutable, accessors), Writer с
   манифестом, CLI, golden-матрица и проверка вывода.
4. Композиция: enum, `allOf`, `oneOf`/`anyOf` + discriminator,
   `additionalProperties`, вынос инлайн-схем.
5. SPI, встроенное `CustomAttributes` + алиасы, рендерер аннотаций.
6. Composer-плагин, автодетект окружения, обнаружение расширений, Docker-образ.

Каждый этап поставляется с тестами и проходит `make check`.
