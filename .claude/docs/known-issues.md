# Известные особенности

- **Числовые ключи PHP-массивов.** Имена свойств (`"200"`) и значения discriminator (`"1"`) PHP хранит как `int`-ключи. Внутри — `array<int|string, …>`, наружу — только через `propertyNames()` / `values()`, которые приводят к строке. Поиск по строке работает (PHP приводит ключ сам).
- **`AbstractEnum` и сериализация.** Экземпляры интернированы; `unserialize` создал бы второй экземпляр и сломал `===`. Модель не сериализуется — не добавлять.
- **Зарезервированные сегменты namespace** (`App\Dto\Public`) модель пропускает — на PHP 7.4 это parse error. Этап 2: конфиг обязан отклонять их при `target.php < 8.0`.
- **Сырые JSON-типы проверяет загрузчик/Builder, не модель.** `Schema::$additionalProperties` (`bool|Schema|null`) и `EnumCase::$value` (`int|string`) типизированы только PHPDoc; рантайм-guard'ы убраны — при PHPStan max их нельзя протестировать. Этапы 2/4 обязаны выдавать диагностику с `SchemaLocation` до создания модели.
- **Различие «нет ключа / null»** в v1 не моделируется (spec §5.2).
- **Rector и `@return static`.** `RemoveDuplicatedReturnSelfDocblockRector` удаляет `@return static` у фабрик `AbstractEnum` (типизация наследников ломается) — правило в `withSkip`. `make fix` запускает Rector **до** CS-Fixer: Rector оставляет FQCN, CS-Fixer их сокращает.
- **Docker-цели в песочнице Claude Code.** `make check` (`lint-74`), `make test-74` обращаются к `docker.sock` — внутри песочницы падают с `permission denied`; запускать вне песочницы.
- **PHPStan и JSON.** Алиасы типов PHPStan не бывают рекурсивными, поэтому `JsonValue` = `JsonScalar|array<array-key, mixed>`.
