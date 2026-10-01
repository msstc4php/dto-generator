# Доменная модель

## Schema (`Domain/Schema`)
- `Schema` — неизменяемый узел; собирается через `SchemaBuilder`. Ключевые слова со своими accessor'ами (`Schema::STRUCTURAL_KEYWORDS`) и `x-*` нельзя класть в `keywords` — один источник истины.
- `SchemaLocation` — файл + JSON pointer (RFC 6901, `~`→`~0`, `/`→`~1`); используется в каждой диагностике.
- `Extensions` — только ключи `x-<name>`; порядок — как в документе.
- `default` обёрнут в `DefaultValue`: «default = null» ≠ «default нет».

## IR (`Domain/Model`)
- `TypeModel`: `ScalarType` (с PHPDoc-уточнением), `ClassType`, `ListType`, `MapType` (ключи всегда string), `UnionType` (≥2 разных, плоский, без nullable/mixed), `NullableType` (не вкладывается, не над mixed), `MixedType`. Равенство типов — по `describe()`.
- `ClassName` отклоняет зарезервированные слова (включая soft keywords `enum`, `readonly`) только в **коротком имени**; сегменты namespace — лишь синтаксис идентификатора, т.к. с PHP 8.0 `App\Dto\Public` допустим. Отказ для target < 8.0 — обязанность валидации конфига (этап 2). Переименование (суффикс `_`) делает Builder, не модель.
- `ClassName::equals()` регистронезависим (как PHP); `reservedNamespaceSegments()` — для проверки target < 8.0. Список зарезервированных включает `die`, `__halt_compiler`, магические константы.
- `PropertyModel`: запрещено только точное `this` (`$This` — законная переменная). `wireName` — исходное имя из схемы (для `SerializedName` в мосте).
- `ClassModel`: уникальны PHP-имена (регистронезависимо — иначе столкнутся `getFoo()`) и wire-имена; discriminator только у `ClassKind::ABSTRACT`; `with*()` пересоздают объект и заново проверяют инварианты.
- `EnumModel`: значения строго типа backing'а (`'1'` в int-enum — ошибка), без дублей имён и значений; case `class` запрещён.
- `AttributeModel` + `ArgumentValue` (literal / list / map / constant / class-reference / new-instance) — общий вход для рендереров атрибутов и аннотаций. Порядок аргументов: позиционные, затем именованные без повторов. `INF`/`NAN` запрещены.

## Target (`Domain/Target`)
- `Capability` — матрица «возможность → минимальная версия PHP» (spec §6.1). Новая версия PHP = строка в `PhpVersion::SUPPORTED` + строки в матрице.
- `TargetProfile` валидирует сразу: `metadata=attributes` требует 8.0; `public-properties` для immutable требует readonly (8.1). `configuredAccessors()` может вернуть `AUTO` — генерация обязана использовать `accessorsFor()`, который разрешает `auto` по мутабельности конкретного класса (её можно переопределить `x-dto-mutable`).
