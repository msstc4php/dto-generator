# Известные особенности

- **Числовые ключи PHP-массивов.** Имена свойств (`"200"`) и значения discriminator (`"1"`) PHP хранит как `int`-ключи. Внутри — `array<int|string, …>`, наружу — только через `propertyNames()` / `values()`, которые приводят к строке. Поиск по строке работает (PHP приводит ключ сам).
- **`AbstractEnum` и сериализация.** Экземпляры интернированы; `unserialize` создал бы второй экземпляр и сломал `===`, поэтому `__sleep`/`__wakeup` бросают `LogicException`.
- **Зарезервированные сегменты namespace** (`App\Dto\Public`) модель пропускает — на PHP 7.4 это parse error. FQCN приходят не только из `sources[].namespace`, но и из `x-php-type`, `formats.*.type`, `x-php-attributes`, `attributeAliases.*.class` и от SPI-enricher'ов. **Требование к этапу 3:** emitter для target < 8.0 проверяет каждый выводимый `ClassName` через `reservedNamespaceSegments()` и выдаёт диагностику; этап 2 — то же для конфига.
- **Сырые JSON-типы проверяет загрузчик/Builder, не модель.** `Schema::$additionalProperties` (`bool|Schema|null`) и `EnumCase::$value` (`int|string`) типизированы только PHPDoc; рантайм-guard'ы убраны — при PHPStan max их нельзя протестировать. Этапы 2/4 обязаны выдавать диагностику с `SchemaLocation` до создания модели. Для enum'ов отклонять явно и `bool` (`is_int(true)` — false, `true` проскочил бы в string-enum), и `float`.
- **Требования к следующим этапам из финального ревью этапа 1:**
  - `UnionType` дедуплицирует по PHPDoc (`int` и `positive-int`, `list<int>` и `array<string,int>` — разные), а нативно это `int|int` / `array|array` — compile error. Emitter (этап 3) обязан дедуплицировать нативные типы.
  - `AttributeModel` пока без цели (класс / свойство / параметр конструктора, spec §8) — добавить в этапе 5 со значением по умолчанию.
  - Catch-all `$additionalProperties` (spec §5.1) не имеет wire-имени, а `PropertyModel` требует непустое уникальное — до этапа 4 нужен флаг или отдельная модель, иначе коллизия с настоящим свойством `additionalProperties` и неверный `SerializedName`.
  - `INF`/`NAN` из YAML (`.inf`, `.nan`) доходят до `DefaultValue` и `Schema::enum`, а `ArgumentValue` их отклоняет — Builder/Emitter должны выбрать одну политику (отказ или `\INF`).
  - `ClassType` не знает, ссылается ли на enum (важно для 7.4/8.0: `string` + PHPDoc `Name::*`) — emitter решает через реестр моделей.
- **Различие «нет ключа / null»** в v1 не моделируется (spec §5.2).
- **Rector и `@return static`.** `RemoveDuplicatedReturnSelfDocblockRector` удаляет `@return static` у фабрик `AbstractEnum` (типизация наследников ломается) — правило в `withSkip`. `make fix` запускает Rector **до** CS-Fixer: Rector оставляет FQCN, CS-Fixer их сокращает.
- **Docker-цели в песочнице Claude Code.** `make check` (`lint-74`), `make test-74` обращаются к `docker.sock` — внутри песочницы падают с `permission denied`; запускать вне песочницы.
- **PHPStan и JSON.** Алиасы типов PHPStan не бывают рекурсивными, поэтому `JsonValue` = `JsonScalar|array<array-key, mixed>`.
