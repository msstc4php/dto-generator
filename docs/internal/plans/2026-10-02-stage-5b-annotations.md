# DTO Generator — этап 5b: рендер аннотаций и `verifyClasses`. План реализации

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** При `metadata: annotations` (по умолчанию на PHP 7.4) атрибуты IR выводятся как docblock-аннотации в стиле Doctrine. При `verifyClasses` существование классов атрибутов и констант проверяется через автозагрузчик потребителя.

**Spec:** §2 («метаданные на 7.4 — только docblock-аннотации»), §4 (правило `auto` для `verifyClasses`), §7.1 (рендер аннотаций, `{new}` → вложенная аннотация), §9.4 (`DTO_GENERATOR_VERIFY_CLASSES=0`).

## Global Constraints
Те же, что в этапах 3–5a. Ветка — `feat/stage-5b-annotations`.

## Решения
- **Формат аннотации (Doctrine Annotations).**
  - Аннотация пишется как `@Name(args)`. Имя — короткое для своего namespace, `\FQCN` для чужих, `Alias\Rest` при `ImportAlias` (тогда в файл добавляется `use Ns as Alias;`).
  - Именованный аргумент — `key=value`. Единственный позиционный — голое значение (Doctrine отдаёт его в `value`). Несколько позиционных — `value={a, b}`. Позиционные вместе с именованными — `value=…` плюс именованные.
  - Значения:
    - строки в двойных кавычках, `"` удваивается (`""`); перевод строки становится двумя символами `\n`, а `*/` — `*\/`, иначе docblock сломается (Enrich предупреждает о такой замене);
    - `true` / `false` / `null`, числа (float с `.0`);
    - list — `{a, b}`, map — `{"k"=v}`;
    - константа — `\FQCN::CONST` или глобальная `CONST`;
    - `\FQCN::class` (Doctrine не разрешает короткое имя, если класс не загружается при разборе);
    - `{new}` — вложенная аннотация `@Name(...)`.
  - Аннотации класса стоят в его docblock после описания и тегов. Аннотации свойства — в docblock свойства: объявленного на 7.4, promoted-параметра на 8.0+.
  - Аннотация длиннее 100 байт переносит аргументы по одному на строку с отступом в 4 пробела; вложенные `{...}` и `@Name(...)` переносятся так же, если не помещаются.
  - Несколько позиционных аргументов дают warning; позиционный вместе с именованным `value` — атрибут отбрасывается (strict — error).
- **`verifyClasses`.**
  - Значение `auto` означает `true`, если найден `vendor/autoload.php` потребителя (вверх от каталога конфига) и переменная `DTO_GENERATOR_VERIFY_CLASSES` не равна `0`; иначе `false`. Явное `true` без найденного автозагрузчика — ошибка конфига.
  - Порт `Application/Port/ClassVerifier` (`hasClass(ClassName)`, `hasConstant(?ClassName, string)`). Адаптер `Infrastructure/Environment/AutoloadClassVerifier` один раз подключает `vendor/autoload.php` и проверяет через `class_exists`/`interface_exists`/`enum_exists` и `defined`.
  - Классы и enum'ы этого же запуска считаются существующими. Автозагрузчик потребителя подключается лениво, при первом вопросе; его Composer-загрузчик переносится в конец очереди. Сбой загрузки — одна ошибка, дальше проверка не идёт. Поиск автозагрузчика останавливается на ближайшем `composer.json` и учитывает `config.vendor-dir`.
  - Проверку выполняет `Enrich` для каждого выводимого атрибута:
    - класс атрибута, классы в `{class}` и `{new}`, класс и константа в `{const}`;
    - отсутствие — ошибка с location схемы.
  - Без `verifyClasses` ничего не проверяется.
- Предупреждение 5a «annotations from a later version» удаляется.

## Review Focus
1. Строка с `"`, `\` и переводом строки в аргументе аннотации.
2. `{new}` с аргументами внутри list внутри аннотации.
3. Import-алиас при аннотациях: `use` в файле есть, коллизия с коротким именем обрабатывается так же, как у атрибутов.
4. `verifyClasses: auto` без `vendor/autoload.php` и с `DTO_GENERATOR_VERIFY_CLASSES=0`.
5. Константа enum-case в `{const}` (`App\Status::ACTIVE` при `enum Status`) на 8.1+ проходит проверку.

## Tasks
1. `AnnotationRenderer` (с общим `AttributeNames`) и встраивание в emitter (docblock'и, `use`, коллизии); golden-матрица 7.4 с аннотациями; smoke-проверка текста аннотаций на 7.4.
2. `verifyClasses`: правило `auto`, порт и адаптер, проверка в `Enrich`, подключение в `Generate`/`DtoGenerator`.
3. Golden-проект 7.4, документация, infection, ревью, слияние.
