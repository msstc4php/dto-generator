# DTO Generator — этап 5a: SPI, `x-php-attributes`, алиасы, рендер атрибутов. План реализации

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Расширения подключаются через SPI (spec §8) и добавляют атрибуты к IR. Встроенное `CustomAttributes` переводит `x-php-attributes` (§7.1) и ключи `attributeAliases` (§7.2) в `AttributeModel`, а emitter выводит их как атрибуты PHP 8. Рендер аннотаций и `verifyClasses` — этап 5b.

**Spec:** §3 (слои Contract и Extension, поток «Enrichers»), §7.1, §7.2, §8, §10 (класс ошибок SPI).

## Global Constraints
Те же, что в этапах 3–4. Ветка — `feat/stage-5a-spi-attributes`. Интерфейсы SPI — на PHP 7.4, типы уточняются в PHPDoc.

## Решения
- **Contract (`src/Contract/`).** Сюда входят:
  - `Extension`, `ExtensionRegistry`, `PropertyEnricher`, `ClassEnricher`;
  - контексты `PropertyContext` и `ClassContext` (final, только чтение): узел IR, класс-владелец, исходная `Schema`, `TargetProfile`, `InstalledPackages`, `Diagnostics`;
  - `FormatMapping` — тип IR для формата;
  - `InstalledPackages` — value object «пакет → версия».

  `Diagnostics` берётся доменный.
- **Реестр** — `Application/Service/Extension/Registry` (реализует `ExtensionRegistry`):
  - Enricher'ы применяются в порядке регистрации.
  - Формат, который регистрируют два расширения, — ошибка с именами обоих. Формат из конфига перекрывает формат расширения (решение: явная настройка пользователя).
  - `claimExtensionKeys` принимает glob'ы по ключам `x-*`.
- **Загрузка расширений.** Порт `Application/Port/ExtensionLoader::load(ClassName): Extension`. Адаптер `Infrastructure/Extension/ClassExtensionLoader` проверяет три вещи: класс существует, реализует `Extension` и создаётся без аргументов; иначе бросает `ExtensionFailed`, а use-case превращает это в ошибку с именем расширения.
  - Порядок: встроенное `CustomAttributes`, затем `extensions` из конфига по порядку. Обнаружение через composer — этап 6.
  - Конфиг расширения — `extensionConfig.<name()>`, по умолчанию `[]`.
  - Исключение из `register()` или enricher'а превращается в ошибку с именем расширения.
- **Обогащение** — use-case `Application/Service/Model/Enrich` (Action/Input/Output), работает после Build и до emit:
  - У каждого класса сначала собираются атрибуты класса, затем атрибуты собственных свойств.
  - Исходные схемы находит `Domain/Schema/SchemaIndex` — индекс по location всех подсхем графа.
- **Проверка атрибутов против цели** (там же, в Enrich, ведь у emitter нет канала диагностики):
  - `metadata: none` — атрибуты не выводятся, без диагностики.
  - `metadata: attributes` и `{new: …}` в аргументе на PHP < 8.1: при `strict` это ошибка, иначе атрибут отбрасывается с warning.
  - Два `ImportAlias` с одним алиасом и разными namespace в одном классе — ошибка.
- **Словарь `x-`.**
  - `x-php-attributes` разрешён у класса и свойства (как и раньше).
  - Ключ из `attributeAliases` разрешён у класса и свойства.
  - Ключи, заявленные расширениями через `claimExtensionKeys`, тоже допустимы. `x-php-*`/`x-dto-*` заявить нельзя, это ошибка.
  - Алиас пересекается с ключом ядра или заявленным ключом — ошибка.
- **`CustomAttributes` (`src/Extension/CustomAttributes/`).** Отвечает за грамматику §7.1:
  - элементы — `{class, args?}`;
  - `args`: map даёт именованные аргументы, list — позиционные;
  - значения: скаляр, list, map, `{const}`, `{class}`, `{new: {class, args}}`, `{literal: map}`.

  Ошибки грамматики выдаются с location до самого элемента; ошибочный атрибут пропускается. Алиасы §7.2:
  - `{value}` (вся строка — значение с сохранением типа), `{value.key}`;
  - плейсхолдер внутри строки подставляет строковое значение;
  - отсутствующий ключ — ошибка.

  Алиасы приходят в расширение через его конфиг: Generate передаёт `attributeAliases` встроенному расширению.
- **Emitter.**
  - `attrGroups` у класса, у promoted-параметра и у объявленного свойства.
  - Аргументы:
    - литерал выводится через `BuilderFactory::val` (строки с управляющими символами — в двойных кавычках);
    - list и map — `Array_`;
    - константа — `ClassConstFetch`/`ConstFetch`;
    - `Foo::class`;
    - `new Foo(...)`;
    - именованные аргументы — `Arg` с `name`.
  - Классы вне namespace файла пишутся `\FQCN`, свои — коротким именем. С `ImportAlias` в файл добавляется `use Ns as Alias;`, а имя пишется `Alias\Rest`.
  - Атрибуты свойств наследника — только на его собственных параметрах.

## Review Focus
1. `{new: …}` на 8.0 при strict и не-strict.
2. Плейсхолдер `{value}` с не-строковым значением и `{value.missing}`.
3. Map с единственным ключом `class` как буквальное значение через `{literal}`.
4. Атрибуты на promoted-параметре наследника и на объявленном свойстве в форме 8.0 public-properties.
5. Исключение внутри стороннего enricher'а: ошибка с именем расширения, генерация не падает.

## Tasks
1. `SchemaIndex`; Contract-интерфейсы и value objects; `Registry` (форматы, конфликты, claim, порядок).
2. Порт `ExtensionLoader` и адаптер; use-case `Enrich`; подключение в `Generate` и `DtoGenerator`; проверки цели; словарь `x-` с алиасами и заявленными ключами; форматы расширений в `TypeMapper` (`array<format, TypeModel>`).
3. `CustomAttributes`: грамматика, алиасы, диагностика.
4. Emitter: атрибуты, `use … as`, golden-матрица (атрибуты в `Sample`, профили 8.x), smoke-проверка через Reflection `getAttributes()` на 8.x.
5. Golden-проект (`x-php-attributes`, алиас), документация, infection, ревью, слияние.
