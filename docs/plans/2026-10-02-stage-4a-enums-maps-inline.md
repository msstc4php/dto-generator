# DTO Generator — этап 4a: enum, `additionalProperties`, вынос инлайн-схем. План реализации

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Генерировать enum-ы (spec §5.3), map-типы и дополнительное свойство из `additionalProperties` (§5.1), выносить инлайн-объекты и инлайн-enum в именованные классы `<Parent><Property>` (§5.3).

**Architecture:**
- **Domain.**
  - `EnumType`: ссылка на enum из IR, хранит класс, backing и карту «значение → имя case».
  - `NameResolver::enumCaseName()`: UPPER_SNAKE; ведущая цифра получает префикс `_`, `class` превращается в `CLASS_`.
  - `EnumBuilder`: схема → `EnumModel` + `EnumType`, с диагностикой.
  - `Declarations`: реестр «location → класс или enum», плюс skipped-схемы. Его потребляет `TypeMapper`.
  - `SchemaShape`: `enum` и `additionalProperties` больше не считаются неподдержанными; добавлен `isEnum()`.
- **Build.**
  - Регистрация именованных схем: класс, enum или алиас.
  - Обход свойств каждого класса, рекурсивно с учётом `items`. Инлайн-объект или инлайн-enum регистрируется как `<Parent><Property>`, для элемента массива добавляется `Item`. `x-php-class-name` на инлайн-схеме переопределяет имя.
  - Enum-ы строятся сразу при регистрации, затем строятся классы. `Output::enums()`.
- **ClassBuilder.** Схема с `properties` и `additionalProperties: <schema>` получает необязательное свойство `$additionalProperties` (`array<array-key, T>`, default `[]`).
- **Emitter.**
  - `CodeEmitter::emitEnum()`. На 8.1+ — `enum Name: string|int` с case-ами. На 7.4/8.0 — `final class` с публичными константами и приватным конструктором.
  - `EnumType`: на 8.1+ нативный тип — класс enum. Ниже 8.1 нативный тип — backing, а в PHPDoc `\Ns\Name::*`.
  - Default enum-свойства печатается как `Name::CASE` на всех целях, включая элементы списков.
- **Generate.** Эмитит и классы, и enum-ы.

**Spec:** `docs/specs/2026-10-01-dto-generator-design.md` §5.1, §5.3 (строки enum и инлайн), §5.5 (`x-enum-descriptions`), §7.

## Global Constraints
Все ограничения этапов 3a и 3b остаются в силе:
- исходники совместимы с PHP 7.4;
- PHPStan max;
- MSI ≥ 99;
- `make fix && make verify`, а также `make test-targets` вне песочницы;
- комментарии на английском, только «почему»;
- коммиты с `Co-Authored-By`.

Ветка — `feat/stage-4a-enums-maps-inline`.

## Решения
- **Имена case:** UPPER_SNAKE из значения (`in-progress` → `IN_PROGRESS`, `1` → `VALUE_1`, `-1` → `VALUE_MINUS_1`). Если два значения дают одно имя, это ошибка.
- **Значения:** только string или int одного типа, `null` разрешён (тогда тип nullable). Пустой список — ошибка, смешанные типы — ошибка (§10). Тип выводится из значений, если `type` не задан. Если `type` противоречит значениям, это ошибка.
- **`x-enum-descriptions`:** map «значение → описание». Каждый ключ обязан быть значением enum, иначе ошибка. Описание попадает в PHPDoc case-а или константы.
- **`$additionalProperties`:** wire name `additionalProperties`. Если реальное свойство с таким PHP-именем уже есть, это ошибка с подсказкой `x-php-name`.

## Review Focus
1. Enum со значением `class`: на 7.4 константа `CLASS` недопустима, ожидается `CLASS_`.
2. Два инлайн-объекта дают одно имя `<Parent><Property>`, или инлайн-имя совпадает с именованной схемой: ожидается ошибка с подсказкой `x-php-class-name`.
3. Default `'EUR'` у enum-свойства на 7.4 печатается как `Currency::EUR` (константа), а не как строка.
4. `enum: [a, null]` даёт nullable-тип свойства.
5. Вложенный инлайн-объект внутри инлайн-объекта (`UserAddressGeo`) и массив инлайн-объектов (`UserTagsItem`).

## Tasks
1. Domain: `EnumType`, `NameResolver::enumCaseName`, `SchemaShape::isEnum`, `DefaultFit` для `EnumType`.
2. `EnumBuilder` с диагностикой; `x-enum-descriptions` возвращается в словарь.
3. `Declarations` + `TypeMapper` (enum по `$ref` и по location, map из `additionalProperties`); `ClassBuilder` (`$additionalProperties`, приём `Schema`).
4. Build: регистрация, вынос инлайн-схем, enum-ы; `Output::enums()`.
5. Emitter: `emitEnum`, `EnumType` в `TypeRenderer`, enum default; golden-матрица (enum `Currency` в фикстуре) и smoke.
6. Generate эмитит enum-ы; golden-проект получает enum, инлайн-объект и `additionalProperties`; документация, infection, ревью, слияние.
