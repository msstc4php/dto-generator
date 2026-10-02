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

Состояние на 2026-10-01 (UTC): этапы 1 и 2a. Реализованы Domain, `Domain/Builder/SchemaParser`, Application (`Config/*`, use-case'ы `Service/Config/Load` и `Service/Schemas/Load`, порты `DocumentLoader`, `ProjectPhpConstraint`) и Infrastructure (`Document/FileDocumentLoader`, `Environment/ComposerJsonPhpConstraint`).

Поток этапа 2a: `Config/Load` (файл → `GeneratorConfig` → `TargetResolver` → `TargetProfile`) → `Schemas/Load` (спецификации источников → выбранные `components/schemas` → обход `$ref` до замыкания → `SchemaGraph`). Ошибки ввода не бросаются, а копятся в `Diagnostics`.

- `Domain/Schema` — разобранная JSON Schema; `$ref` хранится как строка, разрешение — вне домена.
- `Domain/Model` — IR, из которого Emitter строит код. Enricher'ы (SPI) только **добавляют** `AttributeModel`.
- `Domain/Target` — `TargetProfile`: версия PHP + режимы; все решения «можно ли на этой версии» идут через `Capability`.
- deptrac: слой `TypeAlias` — PHPStan-алиасы `JsonValue`/`JsonScalar`, которые deptrac считает классами; анализ с `--fail-on-uncovered`.
- Инструменты в `tools/` (отдельный `composer.json`), чтобы `require-dev` пакета ставился на PHP 7.4.
- Открытый вопрос к этапу 3: Presentation (CLI) будет точкой сборки и должен инстанцировать адаптеры Infrastructure — правило deptrac для этого придётся расширить или вынести composition root.

- `Domain/Diagnostic` — отклонение от spec §3: `Diagnostics` живёт в Domain (его используют `SchemaParser` и Application), Contract (этап 5) будет ссылаться на него. Каждая `Diagnostic` обязана иметь `SchemaLocation`; одинаковые диагностики схлопываются.
- `Domain/Builder/SchemaParser` — «decoded JSON → `Schema`»; «`Schema` → IR» (spec §3 `Builder`) появится в этапе 2b рядом.
- `SchemaGraph` хранит рёбра `ReferenceUse::key()` → цель, записанные при загрузке; `resolve(ReferenceUse)` — чистый поиск без повторного разбора `$ref`.

Этап 2b: `Service/Model/Build` — граф схем → IR. `Domain/Builder`: `NameResolver` (имена), `SchemaShape` (форма класса / неподдержанный keyword), `TypeMapper` (§5.1), `ClassBuilder` (§5.2, §5.4, §5.5, `x-` свойств и items), `ExtensionVocabulary` (словарь §7), `DefaultFit` (default ↔ тип), `RequiredCycles` (неконструируемые циклы). Порядок: регистрация всех классов графа (имена, коллизии) → построение свойств с уже известными именами целей `$ref`.

Этап 3a: порт `Application/Port/CodeEmitter` (`emit(ClassModel, TargetProfile): string` — весь файл). Адаптер `Infrastructure/Emitter/PhpParserEmitter` на `nikic/php-parser` 5: `TypeRenderer` (нативный тип + PHPDoc-тип; чужие классы — `\FQCN`, свои — короткое имя), `DocBlock`, `GeneratedCodePrinter` (пустые строки между членами, `declare(strict_types=1);`). Вся версия-зависимость формы класса — в `TargetProfile::classFormFor(Mutability): ClassForm` (promoted, видимость, readonly-свойства/класс, getters/setters, `WitherStyle`: clone-assign 7.4–8.0, new-self 8.1–8.4, clone-with 8.5). Атрибуты emitter отвергает (`LogicException`) до этапа 5.

Этап 3b: use-case `Application/Service/Generate` (`Action`/`Input`/`Output`, `Mode` write|check|dry-run, `Status` ok|out-of-date|generation-failed|config-failed) склеивает `Config/Load` → `Schemas/Load` → `Model/Build` → `CodeEmitter` → `FileWriter` (порт: `plan(outputDirs, files): WritePlan`, `apply(WritePlan)`). Адаптер `Infrastructure/Writer/FilesystemWriter`: манифест `.dto-generator.manifest.json` в каждом outputDir, атомарная запись (temp рядом + rename), удаление устаревших, отказ трогать файлы без `@generated`. Presentation: `Cli/GenerateCommand` (symfony/console, коды 0/1/2/3), `Cli/DiagnosticFormatter` (пути относительно cwd). Composition root и PHP API — `src/DtoGenerator.php` (слой deptrac `EntryPoint`), `bin/dto-generator` только ищет autoload.

Этап 4a: enum и инлайн-схемы. `Domain/Builder/EnumBuilder` (схема → `EnumModel`), `Model/EnumType` (класс + backing + значение→case), `Builder/Declarations` (location → класс | enum, skipped, abandoned) кормит `TypeMapper`. Build (`Application/Service/Model/Build`): `Registry` (claim имён, очередь классов, готовые enum-ы), обход свойств классов — инлайн-объект/enum → `<Parent><Property>[Item…]`; `Output::enums()` (`BuiltEnum`). `CodeEmitter::emitEnum()`: 8.1+ `enum Name: string|int`, ниже — `final class` с константами и приватным конструктором; `EnumType` ниже 8.1 — backing + PHPDoc `Name::*`; default enum → `Name::CASE`.

Этап 4b: композиция. `Domain/Builder/SchemaShape` различает класс (`properties`; `allOf` объектов; именованный `oneOf`/`anyOf` + discriminator) и алиасы. `AllOfResolver` → `Composition` (parent, parts, ownParts, required): `extends` единственного члена-`$ref` на сгенерированный класс или `merge` всех частей рекурсивно. `VariantResolver` → `Variants` (классы-варианты + `DiscriminatorModel`). `ClassBuilder::build(..., Composition)` собирает свойства по частям. `ClassLookup` находит класс за `$ref`, в том числе через обёртки `allOf: [$ref]`. В Build после построения — `Domain/Builder/Hierarchy::link(list<ClassModel>, Variants…)`: родитель вариантам, разрыв циклов, общие свойства в базу, `ClassKind` (OPEN/ABSTRACT/FINAL), проверки mutability и переобъявлений. `Output::inheritedProperties(ClassModel)` — свойства предков (root first) для emitter и `RequiredCycles`. Порт `CodeEmitter::emit(ClassModel, TargetProfile, list<PropertyModel> $inherited = [])`; `Infrastructure/Emitter/ClassShape` — база/наследник: protected-свойства у баз, abstract — protected-конструктор, наследник передаёт унаследованные параметры в `parent::__construct`. Withers: на clone-целях (7.4/8.0 clone-assign, 8.5 clone-with) база клонирует себя и возвращает `static` (на 7.4 — `self` + `@return static`), наследник их наследует; на 8.1–8.4 (`new self`) у баз withers нет, final-наследник объявляет их для всей цепочки. Setters баз возвращают `static`.

Этап 6a:

- `Infrastructure/Environment/ComposerProject::nearest()` — каталог ближайшего вверх `composer.json`; общий для `AutoloadClassVerifierLocator` и `ComposerLockPackages`.
- Порт `Application/Port/ProjectPackages` (+ `ProjectPackagesUnusable`) → `ComposerLockPackages`: `packages` и `packages-dev` из `composer.lock` проекта, версии как записаны. `Generate\Action::packages()` передаёт их в `EnrichInput`; непригодный lock — warning на `<lock>#`, пакетов нет.
- Порт `Application/Port/ExtensionDiscovery` (DTO `DiscoveredExtension`, `DiscoveredExtensions` с проблемами) → `InstalledJsonExtensionDiscovery`: `installed.json` установки, из которой запущен генератор (каталог `Composer\InstalledVersions`), форматы Composer 1 и 2.
- `Extension\Load\Action(loader, builtIn, discovery)`: встроенные → явные → обнаруженные (по пакету, внутри — по порядку объявления; usort с индексом ради стабильности на 7.4). Обнаруженный класс, уже загруженный (без учёта регистра), пропускается. Проблемы обнаружения — warning, ошибки загрузки — error на `#/discoverExtensions`. При `discoverExtensions: false` обнаружение не вызывается.

Этап 5b:

- Emitter: `AttributeNames` (имена классов атрибутов, import-алиасы, коллизии, `use`) общий для `AttributeRenderer` (`#[...]`) и `AnnotationRenderer` (строки docblock). `AnnotationNode` — дерево значения аннотации; группа, не влезающая в 100 байт, переносит элементы на отдельные строки на любой глубине.
- Порты `Application/Port/ClassVerifier` (`hasClass`, `hasConstant`) и `ClassVerifierLocator` (`locate(dir)`, `isDisabledByEnvironment()`).
- Адаптеры `Infrastructure/Environment/AutoloadClassVerifier{,Locator}` ищут `vendor/autoload.php` вверх от каталога и читают `DTO_GENERATOR_VERIFY_CLASSES`.
- `Generate\Action::verifier()` разрешает `verifyClasses`:
  - `false` → без проверки;
  - `auto` → без проверки, если окружение запрещает, иначе найденный автозагрузчик;
  - `true` без автозагрузчика → CONFIG_FAILED на `#/verifyClasses`.
- Найденный проверяльщик передаётся в `Enrich\Input` вместе с enum'ами Build. `Enrich\AttributeVerification` (одна на запуск):
  - состояние одного запуска (Input, SchemaIndex, Diagnostics, verification) — `Enrich\EnrichmentRun`;
  - классы и enum'ы запуска существуют, константы сгенерированного enum — только его case'ы;
  - сначала атрибуты класса, потом свойств; каждое отсутствующее имя — одна ошибка в первом месте, какой бы атрибут его ни использовал; ключ без учёта регистра имени класса (`app\x`, `app\x::C`, `\C`);
  - `ClassVerificationFailed` порта — одна ошибка, проверка прекращается.
- `AutoloadClassVerifierLocator` ищет ближайший вверх `composer.json`; vendor-dir — `COMPOSER_VENDOR_DIR`, затем `config.vendor-dir`, иначе `vendor`; абсолютный путь — как `Platform::isAbsolutePath` Composer: `/…`, `C:…` (и `C:x`), `\\server`; одиночный `\` — относительный. Переменная `COMPOSER` и `~` не поддерживаются. Окружение читается через `Closure(string): ?string`.
- `ArgumentValue::children()` — непосредственные вложенные значения (list, значения map, аргументы `new`); обходчики (`AttributeRules`, `AttributeVerification`) строятся на нём.
- `AutoloadClassVerifier` подключает `autoload.php` лениво. E_USER_ERROR (platform check Composer) и любые Throwable — при загрузке и в каждом вопросе, включая `defined()` константы класса — превращаются в `ClassVerificationFailed`; deprecations кода потребителя глушатся. Composer-загрузчик потребителя, если он новый, перерегистрируется без prepend.
- `hasClass` (класс/enum — для атрибута и `new`) ≠ `hasType` (ещё interface/trait — для `::class` и класса константы).

Этап 5a: SPI и атрибуты. `src/Contract/` (слой Contract, PHP 7.4): `Extension`, `ExtensionRegistry`, `PropertyEnricher`, `ClassEnricher`, контексты `PropertyContext`/`ClassContext` (узел IR, владелец, исходная `Schema`, `TargetProfile`, `InstalledPackages`, `Diagnostics`), `FormatMapping` (тип IR формата), `InstalledPackages`. `Application/Extension/Registry` реализует реестр и сам вызывает enricher'ы (их исключения → ошибка «Extension "x" failed on …» в диагностику контекста). Use-case'ы: `Service/Extension/Load` (встроенные расширения от точки сборки, затем `extensions` конфига через порт `ExtensionLoader`; секция `extensionConfig.<name()>`), `Service/Model/Enrich` (после Build: атрибуты класса и собственных свойств, `Domain/Target/AttributeRules` — `new` в аргументах ниже 8.1, конфликт import-алиасов (используется и встроенным расширением ради точных мест)), `Domain/Schema/SchemaIndex` — схема узла по location. Поток `Generate`: конфиг → расширения → схемы → Build (форматы конфига поверх форматов расширений, ключи алиасов в словаре `x-`) → Enrich → emit. Встроенное расширение — `src/Extension/CustomAttributes/` (`AttributeParser` — грамматика §7.1, `AliasExpander` — §7.2); собирается в `DtoGenerator::generator()` (Application не видит слой Extension). Emitter: `AttributeRenderer` (каждый атрибут в своём `#[...]`, у класса и promoted-параметров, `use Ns as Alias;` для `ImportAlias`, классы вне namespace — `\FQCN`), `ReadableLiteral` (строки с управляющими символами в двойных кавычках); длинные аргументы атрибутов переносятся как вызовы (`pAttribute`).
