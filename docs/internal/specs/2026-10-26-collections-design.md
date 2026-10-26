# Подпроект C — объект-коллекция вместо массива

**Дата:** 2026-10-26 (UTC). **Статус:** владелец поручил 2026-10-26 (UTC) («давай сразу сделаем 2 + 3») и
разрешил решать без согласования; решения помечены «[решение Claude]».
**Основа:** `~/PhpstormProjects/msstc4php/dto-collection/docs/internal/specs/2026-10-25-dto-collection-design.md`,
проект 3: подставлять вместо `array` + `@var list<T>` любой класс коллекции, не только наш; ядро не зависит от
dto-collection и dto-collection-generator.

## 1. Цель

Свойство-список сгенерированного DTO получает тип класса коллекции (`TagList`, `ArrayCollection<int, Tag>`) вместо
`array`. Вместе с dto-collection-generator это даёт типизированные коллекции под конкретные DTO без рукописного кода:
ядро помечает сгенерированный класс элемента `#[TypedCollection]`, второй генератор строит коллекцию.

Критерий успеха: golden-тесты на каждом профиле, `make test-targets` на 7.4…8.5, MSI 100 %, BC-check чист, по
умолчанию вывод не меняется ни на байт.

## 2. Состав и порядок

| Этап | Репозиторий | Содержание | Релиз |
|---|---|---|---|
| C1 | ядро | конфиг `dto.collections`, `x-php-collection`, `CollectionType`, рендер, атрибут `#[TypedCollection]` | 1.17.0 (minor) |
| C2 | runtime-мост | `CollectionNormalizer` для Symfony Serializer | 1.4.0 (minor) |
| C3 | Symfony-мост | `#[Context]` для коллекций, ограничения валидатора | 1.9.0, требует ядро `^1.17` |

C3 берёт ядро из GitHub (`vcs`), поэтому сливается только после публикации ядра 1.17.0; публикует владелец.

## 3. C1 — ядро

### 3.1 Конфиг

```yaml
dto:
  collections:
    class: App\Dto\Collection\{item}List   # включает возможность; FQCN, {item} необязателен
    typeArguments: [item]                  # PHPDoc-аргументы класса: [], [item], [int, item]
    typedCollection: none                  # none | immutable | mutable
```

- `class` — шаблон FQCN. `{item}` — единственный плейсхолдер; после подстановки имя должно быть корректным FQCN с
  неймспейсом, иначе ошибка конфига.
- `typeArguments` по умолчанию: `[]`, если в `class` есть `{item}` (типизированная коллекция сама знает тип), и
  `[item]` без него (обобщённая, `ListOf<Tag>`). `[int, item]` — для Doctrine `ArrayCollection<int, Tag>`. Других
  значений нет [решение Claude: YAGNI, произвольный шаблон PHPDoc не нужен ни одной известной библиотеке].
- `typedCollection` пишет `#[\MSSTC4PHP\DtoCollection\Attribute\TypedCollection(<класс>, Variant::<…>)]` на
  сгенерированные классы и enum-ы элементов. Требует `{item}` в `class`, цель ≥ 8.1 и запись атрибутами
  (`metadata: auto|attributes`), иначе ошибка конфига. ODM не предлагается: сгенерированные DTO не документы ODM
  [решение Claude].
- Без `dto.collections` вывод прежний.

### 3.2 `{item}`

| Тип элемента | `{item}` |
|---|---|
| класс (сгенерированный, `x-php-type`, `formats`) | короткое имя класса (`Tag`, `TagRead` в split-модели) |
| enum, цель ≥ 8.1 | короткое имя enum |
| enum, цель < 8.1 | как его скаляр |
| `int`, `float`, `string`, `bool` (с уточнениями) | `Integer`, `Float`, `String`, `Boolean` |
| `mixed`, union, nullable элемент, вложенный список, map | нет: свойство остаётся массивом с предупреждением |

Без `{item}` в `class` подходит любой тип элемента.

### 3.3 Какие свойства

Только свойство, чей тип — список или nullable-список. Списки внутри union, map, вложенные списки и
`additionalProperties` остаются массивами [решение Claude: класс коллекции в union даёт неоднозначную
десериализацию, а вложенность умножает классы].

`x-php-collection` на схеме массива или свойстве (через `$ref` наследуется):

- FQCN (можно с `{item}`) — этот класс для этого свойства, даже без `dto.collections`;
- `false` — оставить массив.

Неизвестный тип значения — ошибка схемы. `{item}` для неподходящего элемента — предупреждение, остаётся массив.

### 3.4 Модель

`Domain\Model\CollectionType implements TypeModel` (`@api`): `className(): ClassName`, `list(): ListType`,
`typeArguments(): list<'int'|'item'>`; `describe()` = `<fqcn><<описание элемента>>`, чтобы `TypeMeet`, `Views` и
дедупликация union различали коллекции разных элементов. `ListType`/`MapType`/`PropertyModel` не меняются (BC).

Подстановка — в `ClassBuilder::property()` после `TypeMapper::map()`: каждое представление (read/write) строится
заново, поэтому имя элемента уже нужное. Места с `instanceof ListType` (`TypeMeet`, `Superseded`, `ViewDependence`,
`DefaultFit`, `UnionType::foldClassNames`) получают ветку `CollectionType` там, где важен элемент
(`ViewDependence` — обязательно: коллекция split-класса зависит от модели).

### 3.5 Значения по умолчанию

Класс коллекции не может быть константным выражением (конструктор неизвестен), поэтому `default` у такого свойства
даёт `null`: `[]` — молча (документировано), непустой — с предупреждением «cannot be a PHP constant expression»
[решение Claude: `new X()` годится только для X с публичным конструктором без аргументов, а у коллекций dto-collection
конструктор закрыт].

### 3.6 Рендер

- Нативный тип — имя класса коллекции (`?TagList`), на всех целях.
- PHPDoc: с `typeArguments` — `TagList<int, Tag>`; уточнения элемента (`non-empty-string`) идут в `@phpstan-var`,
  как сейчас у списков. Без аргументов тега нет, если он совпал бы с нативным типом.
- Геттеры, сеттеры, `with*()` — тем же типом. Wither-ы копируют ссылку на объект: для неизменяемых DTO нужна
  неизменяемая коллекция; это в документации.

### 3.7 Атрибут `#[TypedCollection]`

После сборки всех классов для каждого `CollectionType` с `{item}`, чей элемент — класс или enum этого запуска,
классу элемента добавляется атрибут (по одному на класс коллекции; повторяемый). Аргументы: имя класса коллекции
строкой и `Variant::Immutable|Mutable` константой. `verifyClasses` проверит, что dto-collection установлен.
Скалярные коллекции (`StringList`) перечисляются в `dto-collection-generator.yaml` вручную.

### 3.8 Документация

`docs/configuration.md` (`dto.collections`), `docs/x-extensions.md` (`x-php-collection`), раздел «Коллекции» с
примером связки dto-generator → dto-collection-generator, `CHANGELOG.md`, `.claude/docs`.

## 4. C2 — runtime-мост

`Msstc4Symfony\DtoGeneratorBridge\Runtime\CollectionNormalizer` (нормализатор и денормализатор), регистрируется как
`AdditionalPropertiesNormalizer`.

- Ключ контекста `dto_generator_collection` = `{class: FQCN, item: тип для Serializer ('Tag', 'int', 'Tag[]'…)}`;
  его пишет на свойство Symfony-мост через `#[Context]`.
- Денормализация: только если `$type === context.class` и данные — список; элементы через
  `$serializer->denormalize($data, item . '[]')` с контекстом без ключа; затем `C::fromArray($items)`, если есть
  публичный статический `fromArray`, иначе `new C($items)`.
- Нормализация: только объект `context.class`; `toArray()`, иначе `iterator_to_array($object, false)`; элементы —
  через serializer.
- Ошибки (не список, нет способа создать) — `NotNormalizableValueException`, как у Serializer.

## 5. C3 — Symfony-мост

- `SerializerEnricher`: на свойство с `CollectionType` — `#[Context(normalizationContext: …, denormalizationContext:
  …)]` с ключом §4; item-тип — тот, что мост сейчас выводит для элементов списка.
- `KindConstraints::kindOf()`: `CollectionType` — `'collection'` (`Count`, `Unique`), `ConstraintBuilder::each()` —
  `All` по элементам, `Valid` для классов. `Count` требует `Countable`, `All`/`Valid` — `Traversable`; мост пишет их
  без проверки класса и документирует требование [решение Claude: класс может ещё не существовать во время генерации].
- Документация моста и CHANGELOG.

## 6. Не цели

- Map (`additionalProperties`) как объект-коллекция, коллекции внутри union и вложенные.
- Генерация самих классов коллекций ядром (это dto-collection-generator).
- Проверка, что класс коллекции существует и подходит.

## 7. Риски

- Пользователь включает `class` без `{item}` с классом, чей конструктор не берёт массив: ядро это не видит, C2 падает
  при десериализации понятной ошибкой.
- `describe()` нового типа участвует в сравнении моделей read/write: ошибка в нём склеит или разведёт классы.
  Покрыть тестами split-моделей.
