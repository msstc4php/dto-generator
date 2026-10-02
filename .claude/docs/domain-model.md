# Доменная модель

## Schema (`Domain/Schema`)
- `Schema` — неизменяемый узел; собирается через `SchemaBuilder`. Ключевые слова со своими accessor'ами (`Schema::STRUCTURAL_KEYWORDS`) и `x-*` нельзя класть в `keywords` — один источник истины.
- `SchemaLocation` — файл + JSON pointer (RFC 6901, `~`→`~0`, `/`→`~1`); используется в каждой диагностике.
- `Extensions` — только ключи `x-<name>`; порядок — как в документе.
- `default` обёрнут в `DefaultValue`: «default = null» ≠ «default нет».

## IR (`Domain/Model`)
- `TypeModel`: `ScalarType` (с PHPDoc-уточнением), `ClassType`, `ListType`, `MapType` (`array<array-key, T>` — числовые ключи JSON становятся int), `UnionType` (≥2 разных, плоский, без nullable/mixed), `NullableType` (не вкладывается, не над mixed), `MixedType`. Равенство типов — по `describe()`.
- `ClassName` отклоняет зарезервированные слова (включая soft keywords `enum`, `readonly`) только в **коротком имени**; сегменты namespace — лишь синтаксис идентификатора, т.к. с PHP 8.0 `App\Dto\Public` допустим. Отказ для target < 8.0 — обязанность валидации конфига (этап 2). Переименование (суффикс `_`) делает Builder, не модель.
- `Identifier`: проверка с `\z`; два списка — `PHP74_KEYWORDS` (токены лексера 7.4: ломают и имя класса, и сегмент namespace до 8.0) и `RESERVED_CLASS_NAMES` (`enum`, `match`, `readonly`, имена типов — только для имени класса); `resource`/`numeric` разрешены (soft-reserved, компилируются везде); `normalizeQualifiedName()` — общая проверка FQCN/namespace (ровно один ведущий `\`); `asciiLower()` — локаленезависимый lower.
- `ClassName::equals()` регистронезависим (как PHP); `reservedNamespaceSegments()` — для проверки target < 8.0. Список зарезервированных включает `die`, `__halt_compiler`, магические константы.
- `PropertyModel`: запрещено только точное `this` (`$This` — законная переменная). `wireName` — исходное имя из схемы (для `SerializedName` в мосте).
- `ClassModel`: уникальны PHP-имена (регистронезависимо — иначе столкнутся `getFoo()`) и wire-имена; discriminator только у `ClassKind::ABSTRACT`; `with*()` пересоздают объект и заново проверяют инварианты.
- `EnumModel`: значения строго типа backing'а (`'1'` в int-enum — ошибка), без дублей имён и значений; case `class` запрещён.
- `AttributeModel` + `ArgumentValue` (literal / list / map / constant / class-reference / new-instance) — общий вход для рендереров атрибутов и аннотаций. Порядок аргументов: позиционные, затем именованные без повторов. `INF`/`NAN` запрещены.

## Target (`Domain/Target`)
- `Capability` — матрица «возможность → минимальная версия PHP» (spec §6.1). Новая версия PHP = строка в `PhpVersion::SUPPORTED` + строки в матрице.
- `TargetProfile` валидирует сразу: `metadata=attributes` требует 8.0; `public-properties` для immutable требует readonly (8.1). `configuredAccessors()` может вернуть `AUTO` — генерация обязана использовать `accessorsFor()`, который разрешает `auto` по мутабельности конкретного класса (её можно переопределить `x-dto-mutable`).

## Диагностика и граф (этап 2a)
- `Diagnostics` — изменяемый сборщик (collecting parameter); `Diagnostic` = severity + сообщение + `SchemaLocation|null`. Ошибки конфига адресуются тем же `SchemaLocation` (файл конфига + JSON pointer).
- `SchemaParser` всегда возвращает `Schema`; неверный keyword → диагностика и пропуск.
- `SchemaGraph::resolve(ReferenceUse)` — по рёбрам, записанным при загрузке (не бросает); `Reference::target()` — `$ref` → `SchemaLocation` (лексически, без ФС); `null` для `scheme://`; якоря (`#Name`) не поддерживаются; фрагмент и путь проходят `rawurldecode`.
- `Discriminator` хранит mapping нормализованным: голое имя → `#/components/schemas/<имя>`.
- `SchemaGraph` — ключ `file#pointer`; `ResolvedSchema.source` — индекс источника-владельца; файл вне всех источников наследует владельца от ссылающихся, двое и более → ошибка неоднозначного namespace. `selected=false` — схема нужна только как цель ссылки (генерировать её всё равно придётся, иначе ссылка повиснет — решение этапа 2b).
- `Capability::RESERVED_NAMESPACE_SEGMENTS` (8.0): ниже — `TargetResolver` отклоняет токены PHP 7.4 в namespace источников и типах `formats`.

## Builder (этап 2b)
- Класс = схема без `$ref`/`x-php-type`/неподдержанных keyword'ов, с `properties` и типом `object` или без типа (`SchemaShape::isClass`). Прочие именованные схемы — алиасы: `$ref` на них встраивает их тип (с защитой от циклов).
- Схемы только-по-`$ref` (selected=false) тоже становятся классами — в namespace источника-владельца (решение владельца 2026-10-01 UTC).
- Свойство: обязательное и не-nullable → без default; иначе `?T` и default из схемы или `null`. Default у класса (дата, `x-php-type`) или map, в том числе как элемента непустого списка, → warning и `null` (`{}` и `[]` из JSON неразличимы: пустой default у map/класса — тоже warning; у списка — сохраняется; не-список у списка — ошибка несовпадения); `INF`/`NAN` → ошибка; default, не подходящий под тип с учётом уточнений (`non-empty-string`, `positive-int`, `int<a, b>`, элементы `list`), → ошибка и `null` (`DefaultFit`).
- Имена: класс — PascalCase, ведущая цифра `_`, зарезервированное слово `_` в конце; свойство — camelCase, первое слово целиком в капсе приводится к нижнему регистру целиком, ведущий акроним тоже, с цифрами (`HTTPStatus` → `httpStatus`, `HTTP2Status` → `http2Status`, `IDs` → `ids`), `this` → `this_`. Слова делятся по не-`\p{L}\p{M}\p{N}` (combining marks NFD-имён сохраняются) (NBSP, `€` — разделители); невалидный UTF-8 — только ASCII-буквы и цифры. Коллизии — без учёта ASCII-регистра.
- Неподдержанное до этапа 4 (`enum`, `allOf/oneOf/anyOf`, `discriminator`, `additionalProperties`-схема, инлайн-объект): в свойстве — ошибка и `mixed`; у выбранного компонента — warning и класс не создаётся.
- `x-php-skip` действует и на алиасы (ссылка → warning и `mixed`); required + skip → warning.
- Словарь `x-php-*`/`x-dto-*` (`ExtensionVocabulary`) проверяется у каждой схемы графа: класс/enum/композиция — набор класса, алиас — `x-php-type`, `x-php-skip`; свойства — в `ClassBuilder`; любая цепочка `items` — только `x-php-type`. Контексты закрыты: `checkClass`/`checkAlias`/`checkProperty`. `x-enum-descriptions` в ядре нет.
- Цикл обязательных class-typed свойств (A.b: B, B.a: A) → warning на каждом свойстве цикла (`RequiredCycles`, после построения всех классов).
- `exclusiveMinimum: PHP_INT_MAX` / `exclusiveMaximum: PHP_INT_MIN` → warning, граница игнорируется.
- `Schema::requireProperty()` — для имён из `propertyNames()`; иное имя — `InvalidModel` (ошибка кода, не ввода).

## Этап 4a
- Enum: значения string или int одного типа; `null` среди значений или `type: [T, "null"]` → nullable-свойство. Имена case — UPPER_SNAKE из значения (`in-progress`→`IN_PROGRESS`, `inProgress`→`IN_PROGRESS`, `1`→`VALUE_1`, `-1`→`VALUE_MINUS_1`, `class`→`CLASS_`, ведущая цифра → `_`). Совпадение имён — ошибка; дубликат значения — warning. `x-enum-descriptions` — map значение→описание (ключи проверяются).
- `additionalProperties: <schema>`: объект без `properties` → `array<array-key, T>` (алиас), с `properties` → доп. свойство `$additionalProperties` (wire name `additionalProperties`, default `[]`); конфликт имени — ошибка.
- Инлайн-объекты/enum в свойствах классов (и в `items` — суффикс `Item`) → объявления `<Parent><Property>`; `x-php-class-name` на инлайн-схеме переопределяет; коллизия — ошибка, свойство молча `mixed` (abandoned). Инлайн-схемы вне свойств классов (items алиаса и т. п.) не генерируются: объект — ошибка, enum — warning и базовый тип.
- После ревью 4a: `$ref` на nullable-класс (`type: [object, null]`) даёт nullable-тип (как и для enum). Имя инлайн-класса строится из wire name свойства (не из `x-php-name`); нет пригодных символов — нужен `x-php-class-name`, иначе ошибка. Инлайн-схемы в `additionalProperties` тоже выносятся: значения map-свойства → `<Parent><Property>Value`, `additionalProperties` класса → `<Parent>AdditionalProperty`. Схема, уже объявленная под своим именем (цель `$ref` внутрь свойства), повторно не выносится.
- Enum: значения только из bool/float/null → не enum (warning, базовый тип); объект с `properties` + `enum` → класс. `""` → case `EMPTY`; `PHP_INT_MIN` → `VALUE_MINUS_9223372036854775808`; `type: number` допустим для целых значений; `x-enum-descriptions` для int-enum 0..n-1 может прийти списком. Все проблемы enum сообщаются разом.
- Ключи объявления (`x-php-class-name`, `x-dto-mutable`, `x-enum-descriptions`) на свойстве/items допустимы только если схема становится классом или enum, иначе warning «no effect».

