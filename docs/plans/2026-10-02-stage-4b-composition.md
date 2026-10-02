# DTO Generator — этап 4b: композиция (`allOf`, `oneOf`/`anyOf`, discriminator). План реализации

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Закрыть таблицу композиции spec §5.3. `allOf` даёт наследование или слияние, `oneOf`/`anyOf` с discriminator — abstract-базу с наследниками, `oneOf`/`anyOf` без discriminator — union-тип.

**Spec:** §5.3, §6.2 («Классы `final`, кроме баз `allOf`/discriminator»), §7 (`x-php-all-of`), §10 (конфликт полей в merge).

## Global Constraints
Те же, что в этапах 3–4a. Ветка — `feat/stage-4b-composition`.

## Решения
- **`allOf`, стратегия `extends`.** Это `dto.allOfStrategy` из конфига или `x-php-all-of` на схеме. Ровно один член `allOf` — `$ref` на класс, остальные инлайн-объекты без `$ref`. Тогда `Child extends Base`, а собственные свойства — это `properties` самой схемы плюс свойства инлайн-членов. Base получает `ClassKind::OPEN`, если он не abstract.
- **`allOf`, стратегия `merge`.** Включается при нескольких `$ref` или явной стратегии `merge`. Свойства всех членов (рекурсивно через их `allOf`) и собственные `properties` сливаются в один final-класс. Одно wire-имя с разными типами — ошибка (§10). `required` — объединение.
- **`allOf` на не-объекте** (например, `allOf: [{$ref: Money}]` у алиаса) — это алиас на член, если член один. Иначе ошибка.
- **`oneOf`/`anyOf` + discriminator** на именованной схеме дают `ClassKind::ABSTRACT` с `DiscriminatorModel`:
  - общие свойства — пересечение свойств вариантов по wire-имени с одинаковым типом;
  - свойство общее-обязательное, только если оно обязательно во всех вариантах;
  - каждый вариант (`$ref` на объектную схему) становится классом-наследником со своими свойствами минус общие;
  - mapping: явный `discriminator.mapping`, иначе имя схемы варианта;
  - вариант с собственным родителем или в двух discriminated-объединениях — ошибка (одиночное наследование).
- **`oneOf`/`anyOf` без discriminator** — `UnionType` членов. На 8.0+ нативный, на 7.4 только PHPDoc. Одинаковые члены схлопываются. `mixed` поглощает union. nullable-член делает union nullable.
- **Emitter.**
  - **База (OPEN/ABSTRACT).** Свойства `protected` (кроме формы public-properties), getters есть, withers нет, конструктор `protected`, если класс abstract.
  - **Наследник (`final`).** Параметры родителя, затем свои, required-first по всей цепочке. Родительские параметры не promoted, они уходят в `parent::__construct(...)`. Getters и setters только для своих свойств. Withers для всех свойств цепочки через `new self(...)`.
  - **Readonly class (8.2+).** Наследник и база должны совпадать по mutability. Разная mutability в иерархии — ошибка в Build.
  - **Порт.** `CodeEmitter::emit(ClassModel, TargetProfile, list<PropertyModel> $inherited = [])`. `Generate` собирает свойства предков.
- `RequiredCycles` учитывает свойства предков.

## Review Focus
1. Наследник с обязательными собственными и необязательными родительскими свойствами: порядок аргументов на 8.x не должен давать deprecation.
2. Merge с конфликтом типов одного поля: ошибка, а не молчаливая перезапись.
3. Discriminator mapping на схему, которая не является вариантом: ошибка.
4. Union из `$ref`-классов и `null` на 7.4 и 8.0.
5. Base из другого источника (namespace) — `extends \Other\Base`.

## Tasks
1. Domain: `TypeMapper` для `oneOf`/`anyOf` без discriminator (union) и `allOf`-алиаса; `SchemaShape` различает варианты.
2. Build: `allOf` extends/merge, discriminator → abstract + наследники (`Registry` получает parent/kind/discriminator), проверки иерархии.
3. Emitter: базы и наследники, `inherited`-параметр, `protected`, `parent::__construct`, withers через `new self`.
4. Generate: цепочка предков; golden-матрица (Base/Child в фикстуре) + smoke; golden-проект (Pet/Cat/Dog с discriminator, Address allOf, union); test-targets.
5. Документация, infection, ревью, слияние.
