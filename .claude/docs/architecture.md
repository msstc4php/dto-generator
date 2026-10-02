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

Этап 3a: порт `Application/Port/CodeEmitter` (`emit(ClassModel, TargetProfile): string` — весь файл). Адаптер `Infrastructure/Emitter/PhpParserEmitter` на `nikic/php-parser` 5: `TypeRenderer` (нативный тип + PHPDoc-тип; чужие классы — `\FQCN`, свои — короткое имя), `DocBlock`, `GeneratedCodePrinter` (пустые строки между членами, `declare(strict_types=1);`). Вся версия-зависимость формы класса — в `TargetProfile::classFormFor(Mutability): ClassForm` (promoted, видимость, readonly-свойства/класс, getters/setters, `WitherStyle`: clone-assign 7.4–8.0, new-self 8.1–8.4, clone-with 8.5). Emitter отвергает `parent`, discriminator и атрибуты (`LogicException`) до этапов 4–5.

Этап 3b: use-case `Application/Service/Generate` (`Action`/`Input`/`Output`, `Mode` write|check|dry-run, `Status` ok|out-of-date|generation-failed|config-failed) склеивает `Config/Load` → `Schemas/Load` → `Model/Build` → `CodeEmitter` → `FileWriter` (порт: `plan(outputDirs, files): WritePlan`, `apply(WritePlan)`). Адаптер `Infrastructure/Writer/FilesystemWriter`: манифест `.dto-generator.manifest.json` в каждом outputDir, атомарная запись (temp рядом + rename), удаление устаревших, отказ трогать файлы без `@generated`. Presentation: `Cli/GenerateCommand` (symfony/console, коды 0/1/2/3), `Cli/DiagnosticFormatter` (пути относительно cwd). Composition root и PHP API — `src/DtoGenerator.php` (слой deptrac `EntryPoint`), `bin/dto-generator` только ищет autoload.
