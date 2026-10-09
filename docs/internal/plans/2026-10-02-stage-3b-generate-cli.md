# DTO Generator — этап 3b: запись, Generate, CLI, golden end-to-end, CI. План реализации

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Довести генератор до рабочего инструмента. Команда `vendor/bin/dto-generator generate` должна читать конфиг, строить IR, эмитить классы и атомарно записывать их с манифестом. Режимы `--check` и `--dry-run`, коды выхода по spec §9.2.

**Architecture:**
- Application:
  - порт `FileWriter` (`plan()` / `apply()`);
  - DTO `GeneratedFile`, `WritePlan`, `FileChange`;
  - use-case `Service/Generate` (`Action`/`Input`/`Output`) последовательно вызывает существующие `Config/Load`, `Schemas/Load`, `Model/Build`, затем `CodeEmitter` и `FileWriter`.
- Infrastructure: `Writer/FilesystemWriter`. Манифест `.dto-generator.manifest.json` лежит в каждом `outputDir`. Запись атомарная (временный файл + `rename`). Чужой файл без `@generated` не трогается.
- Presentation: `Cli/GenerateCommand` (symfony/console) и `Cli/DiagnosticFormatter`. Формат `text` или `json`, пути показываются относительно текущего каталога.
- Новый слой deptrac `EntryPoint` — файл `src/DtoGenerator.php`. Это единственный composition root: собирает зависимости для PHP API, CLI и тестов. Он же и есть «PHP API» из вопросов брейншторма.
- `bin/dto-generator` только находит autoload и вызывает `DtoGenerator::console()->run()`.

**Tech Stack:** PHP ≥ 7.4, `symfony/console ^5.4 || ^6.4 || ^7.0`, `nikic/php-parser ^5.0`, PHPUnit 9.6, PHPStan 2 max, deptrac, Infection, Docker, GitHub Actions.

**Spec:** `docs/internal/specs/2026-10-01-dto-generator-design.md`:
- §3 — поток данных, «ничего не пишется при ошибке»;
- §9.1 — запись;
- §9.2 — CLI;
- §10 — ошибки;
- §11.1 — golden end-to-end, проверка вывода, детерминизм, функциональные тесты;
- §11.3 — CI;
- §13, этап 3 (вторая часть).

## Global Constraints

- Все ограничения этапа 3a действуют и здесь: исходники на PHP 7.4, PHPStan max без ignore, комментарии только «почему» и на английском, даты в UTC, коммиты с `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`, гейт `make fix && make verify`, Docker-цели запускаются вне песочницы, MSI ≥ 99 %.
- Ошибки ввода и записи передаются как `Diagnostic`. Исключения допустимы только для программных ошибок и для отказа файловой системы во время `apply()` (`WriteFailed`).
- Если в диагностике есть хотя бы одна ошибка, ничего не записывается (spec §3, §10).
- Коды выхода:
  - `0` — успех, либо `--check` без расхождений;
  - `1` — `--check` нашёл расхождения;
  - `2` — ошибки генерации или записи;
  - `3` — ошибка конфигурации: конфиг не загружен или в диагностике загрузки конфига есть ошибки.
- Манифест — `{"generator":"msstc4php/dto-generator","files":{"<relative path>":"<sha256>"}}`. Ключи отсортированы, JSON с `JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES` и `\n` в конце.
- Неизменённый файл не перезаписывается, mtime сохраняется.
- Путь файла: `<outputDir>/<FQCN без namespace источника, \ → />.php`.
- Работа идёт в ветке `feat/stage-3b-generate-cli`.

## Review Focus

1. **Существующий в `outputDir` файл без `@generated` и вне манифеста.** Ожидается ошибка и отказ от любой записи. Тест — Task 2.
2. **Файл из манифеста, которого больше нет в наборе** (схему удалили). Ожидается удаление. Если файл в манифесте, но заголовок в нём стёрт руками, файл сохраняется и выдаётся ошибка. Тест — Task 2.
3. **Два источника с одним `outputDir`.** Ожидается один общий манифест, а не перетирание манифестов друг другом. Тест — Task 2.
4. **Повторный запуск без изменений.** Ожидается ни одной записи и неизменные mtime, а `--check` возвращает 0. Тест — Task 2 и Task 4.
5. **Ошибка в схеме при уже существующем выводе.** Ожидается код 2 и нетронутые файлы. Тест — Task 4.

---

### Task 1: DTO записи и порт `FileWriter`

**Files:** `src/Application/Port/FileWriter.php`, `src/Application/Port/WriteFailed.php`, `src/Application/Service/Generate/{GeneratedFile,FileChange,WritePlan}.php`. Тесты для DTO: `tests/Unit/Application/Service/Generate/WritePlanTest.php`.

**Interfaces:**
- `GeneratedFile::__construct(string $outputDir, string $relativePath, string $contents)`. Конструктор отклоняет пустые, абсолютные пути и пути с `..`.
- `FileChange`: `kind` = `create|update|delete|unchanged`, `path` (абсолютный), `?string $contents`.
- `WritePlan::__construct(list<FileChange> $changes, list<string> $conflicts, array<string, string> $manifests)`.
  - `$manifests`: абсолютный путь манифеста → содержимое; `''` означает удалить манифест.
  - Методы: `changes()`, `conflicts()`, `manifests()`, `hasChanges(): bool` (есть create/update/delete или манифест меняется), `isEmpty()`.
- `interface FileWriter { plan(list<GeneratedFile>): WritePlan; apply(WritePlan): void; }`. `apply` бросает `WriteFailed`.

### Task 2: `FilesystemWriter`

**Files:** `src/Infrastructure/Writer/FilesystemWriter.php`, `tests/Integration/Infrastructure/Writer/FilesystemWriterTest.php`. Тесты пишут во временный каталог и убирают его в `tearDown`.

Поведение:
1. Файлы группируются по `outputDir`.
2. Для каждого каталога читается манифест. Битый манифест считается пустым.
3. Желаемый файл:
   - на диске то же содержимое → `unchanged`;
   - файла нет → `create`;
   - файл есть и он в манифесте или в нём есть `@generated` → `update`;
   - иначе → конфликт `<path>: … is not generated by dto-generator; remove it or move outputDir.`
4. Запись манифеста, которой нет среди желаемых файлов:
   - файл на диске с `@generated` → `delete`;
   - файла нет → только убрать из манифеста;
   - файл без `@generated` → конфликт (файл сохраняется).
5. Новый манифест сравнивается со старым. Если отличается, он попадает в `manifests`.
6. `apply`:
   - создаёт каталоги;
   - пишет каждый файл через `tempnam` в том же каталоге, `chmod 0644`, затем `rename`;
   - удаляет файлы, помеченные на удаление;
   - пишет манифесты так же атомарно.

Тесты:
- создание и манифест;
- повторный запуск даёт пустой план и прежние mtime;
- обновление;
- удаление устаревшего файла;
- конфликт с чужим файлом;
- конфликт со стёртым заголовком;
- общий `outputDir` у двух источников;
- битый манифест;
- `apply` по пустому плану ничего не трогает;
- `WriteFailed`, если каталог недоступен для записи. Если тест запущен от root, он пропускается.

### Task 3: Use-case `Generate`

**Files:** `src/Application/Service/Generate/{Action,Input,Output,Mode,Status}.php`, `tests/Unit/Application/Service/Generate/GenerateTest.php`. В тесте используются реальные сервисы, `InMemoryDocumentLoader` и writer-шпион из `tests/Support/RecordingWriter.php`.

**Interfaces:**
- `Mode` (AbstractEnum): `write | check | dry-run`.
- `Status` (AbstractEnum): `ok | out-of-date | generation-failed | config-failed`. Порядок проверки:
  - конфиг не загрузился → `config-failed`;
  - ошибки в диагностике или конфликты записи → `generation-failed`;
  - режим `check` и в плане есть изменения → `out-of-date`;
  - иначе `ok`.
- `Input(string $configPath, Mode $mode)`.
- `Output(Status, Diagnostics, ?WritePlan, list<GeneratedFile>)`.
- `Action::__construct(LoadConfig, LoadSchemas, BuildModel, CodeEmitter, FileWriter)`.
  - Конфликты превращаются в ошибки с `SchemaLocation(<path>)`.
  - `apply` вызывается только в режиме `write` и только без ошибок.
  - `WriteFailed` превращается в ошибку и даёт статус `generation-failed`.

### Task 4: Composition root, CLI, `bin/`

**Files:** `composer.json` (+ `symfony/console`, `bin`), `deptrac.yaml` (+ слой `EntryPoint`), `src/DtoGenerator.php`, `src/Presentation/Cli/{GenerateCommand,DiagnosticFormatter}.php`, `bin/dto-generator`, `tests/Functional/Cli/GenerateCommandTest.php` (CommandTester, временная копия фикстуры), `phpunit.xml.dist` (+ suite `functional`).

- `DtoGenerator::generator(): Generate\Action` и `DtoGenerator::console(): Symfony\Component\Console\Application` — имя `dto-generator`, версия `dev`.
- `generate [--config=] [--check] [--dry-run] [--format=text|json]`. Без `--config` ищет `dto-generator.yaml`, затем `dto-generator.json` в cwd; если не найден ни один, это код 3. `--check` и `--dry-run` вместе — код 3.
- Text-вывод:
  - диагностика, по строке на запись: `error <relative location>: <message>`;
  - затем для `write`: `Written: N, deleted: M, unchanged: K.`;
  - для `check`: список расхождений;
  - для `dry-run`: план.
- JSON-вывод: `{"status":…,"diagnostics":[{"severity","location","message"}],"changes":[{"kind","path"}]}`.
- Функциональные тесты:
  - коды 0, 1, 2, 3;
  - повторный запуск;
  - `--check` после записи;
  - `--dry-run` ничего не пишет;
  - `--format=json`;
  - ошибка схемы не трогает уже существующий вывод;
  - конфиг по умолчанию из cwd.

### Task 5: Golden end-to-end и проверка вывода

**Files:**
- проект `tests/Fixtures/Projects/golden/` с `api/openapi.yaml`, `api/shared/common.yaml` и конфигами `php7.4.yaml`, `php8.0.yaml`, `php8.1.yaml`, `php8.2.yaml`, `php8.5.yaml`;
- `expected/<ver>/**.php.golden`;
- `tests/Integration/GoldenProjectTest.php`: dry-run, сравнение с эталоном, `UPDATE_SNAPSHOTS=1`, детерминизм двух прогонов;
- `tests/Targets/run.sh`: `php -l` и PHPStan max по `expected/<ver>`.

Схема покрывает:
- обязательные и nullable поля;
- `format: email`, `date-time`, `uuid`;
- integer-диапазоны;
- массив `$ref`;
- внешний `$ref`;
- default;
- многострочный `description`;
- `deprecated`;
- `x-php-name`;
- `x-dto-mutable`;
- `x-php-type`.

### Task 6: CI, документация, закрытие

- `.github/workflows/ci.yml` (GitHub Actions):
  - job `tests`: матрица PHP 7.4–8.5 × `lowest`/`highest`, `composer update --prefer-lowest|--prefer-stable`, затем `vendor/bin/phpunit`;
  - job `static` на 8.4: `make check` без `lint-74`, его делает job `tests` на 7.4 через `php -l`;
  - job `targets`: `make test-targets`.
- README: раздел «Использование» с CLI и PHP API.
- `.claude/docs`.
- Infection.
- Финальное ревью `/acc:code-review high` и завершение ветки.
