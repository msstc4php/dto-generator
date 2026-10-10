# Конвенции проекта

Отличия от глобальных правил, продиктованные рантаймом PHP 7.4:

- Нет нативных enum → наследники `Domain/Shared/AbstractEnum` (`X::from(X::CONST)`, сравнение `===` или `equals()`); экземпляры интернированы.
- Нет `readonly` → `private` типизированные свойства + геттеры, `final` классы, `with*()` через `clone`/`new self`.
- Нет union-типов и `mixed` в сигнатурах → тип в PHPDoc, нативный тип опущен. JSON-значения — алиас `JsonValue` из `Domain/Shared/Json` (`@phpstan-import-type JsonValue from Json`).
- В многострочных списках **параметров** висячая запятая запрещена (PHP 8.0); в вызовах и массивах — обязательна (CS-Fixer настроен так).
- Функции 8.0+ (`str_contains`, `str_starts_with`…) не использовать: `strncmp`/`strpos`. `phpVersion: 70400` их **не** ловит (они есть в анализирующем рантайме 8.4) — запрещены через `disallowedFunctionCalls`/`disallowedClasses` в `phpstan.dist.neon`; пополнять список при необходимости.
- `Schema::keywords()` может содержать int-ключи (числовые имена); для строк — `keywordNames()`.
- PHPUnit 9.6: `@dataProvider` в аннотациях, провайдеры `public static`.
- Проверка «код парсится на 7.4» — `make lint-74`, входит в `make check`; тесты на 7.4 — `make test-74`.
- Неизвестное значение enum-like (`X::from()`) тоже `InvalidModel`, так что ошибки конфига ловятся как `DomainError`.
- Нарушение инварианта модели → `Domain\Exception\InvalidModel`; вызов accessor'а не того вида → `\LogicException`. Все доменные исключения реализуют маркер `Domain\Exception\DomainError` — внешние слои ловят его одним `catch`.
- `with*()` заменяет значение; добавление — `withAdded*()`. Копии всегда через конструктор (инварианты перепроверяются), не через `clone`.
- `SchemaBuilder`: variadic-сеттеры заменяют, keyed (`property`, `keyword`) — добавляют.
- Сравнение идентификаторов — `Identifier::asciiLower()`, не `strtolower()` (на 7.4 зависит от локали). Регулярки на целую строку — с `\z`, не `$` (`$` пропускает завершающий `\n`).
- Тест исключения всегда проверяет и класс, и устойчивую подстроку сообщения.
- Полный гейт перед коммитом: `make fix && make verify` (= `check` + `test` + `test-74`); `make infection` держит MSI ≥ 99 % — эквивалентные мутанты перечислены в `infection.json5` с причиной — только точечно по методу (`ignore`), не глобальным regex.
- Use-case'ы Application — вертикальные слайсы `Service/<Area>/<UseCase>/{Action,Input,Output}.php`; вспомогательные классы слайса лежат рядом (`Schemas/Load/GraphBuilder`).
- Ошибки пользовательского ввода (конфиг, спецификации) — только `Diagnostics` с `SchemaLocation`, без исключений; исключения — для нарушений инвариантов кода. Порт загрузки бросает `DocumentLoadFailed`, use-case превращает его в диагностику.
- Временные файлы тестов — только через `sys_get_temp_dir()`: bootstrap (`tests/bootstrap.php`) направляет его в собственный каталог процесса `var/tmp/run-<pid>-<hex>`, который удаляется по завершении. Не вызывать `sys_get_temp_dir()` и не писать в `/tmp` до bootstrap (значение кэшируется на процесс).
- Интеграционные тесты (`tests/Integration`, suite `integration`) работают с реальной ФС и фикстурами `tests/Fixtures`; модульные используют двойники из `tests/Support`.
- YAML-float (`php: 8.2`, `openapi: 3.1`) превращается в текст только через `Json::floatToString()` (`var_export` при временно выставленном `serialize_precision=-1`) — голый `var_export()` зависит от php.ini, `sprintf('%.1f')` округляет и зависит от локали.
- `Json::isList()` несёт `@phpstan-assert-if-true list<mixed>`: после проверки массив — список для PHPStan; литеральные массивы в тестах поэтому подаются через data provider.
- CS-Fixer: `no_superfluous_phpdoc_tags.allow_mixed = true` — на PHP 7.4 `@param mixed` единственный способ типизировать границу декодера.

- Регистр первой буквы — `Identifier::asciiUpperFirst/asciiLowerFirst` (не `ucfirst/lcfirst`: на 7.4 зависят от локали).
- Тесты Builder'а строят граф через `tests/Support/GraphFixture` (настоящий `Schemas/Load` на `InMemoryDocumentLoader`), IR — через `tests/Support/ModelFixture`.
- В тестах не называть хелперы `at()`: в PHPUnit 9 это статический метод `TestCase::at()`, переопределение — фатальная ошибка при загрузке.

## Golden-файлы emitter (этап 3a)
- `tests/Fixtures/Emitter/<php>-<mutability>[-getters|-public]/{Sample,Tag,Copy}.php.golden` (все три обязательны — `smoke.php` их подключает) — побайтный вывод; расширение `.golden`, чтобы lint-74, PHPStan, CS-Fixer и Rector не трогали синтаксис 8.x.
- Обновление: `UPDATE_SNAPSHOTS=1 vendor/bin/phpunit tests/Integration/Emitter`, затем прочитать diff. Руками golden не правят — дефект чинится в emitter.
- `make test-targets` (вне песочницы, Docker): `php -l` + `tests/Targets/smoke.php` на версии профиля (deprecation/notice = провал), затем PHPStan max по профилю с его `phpVersion` (`fileExtensions: [golden]`). Новый профиль = новая строка в `GoldenEmitterTest::profiles()` (тест сверяет список с каталогами).
- `EmitterFixture::model()` принимает mutability явно — в тестах класс должен совпадать с профилем.

## Сквозной golden-проект (этап 3b)
- `tests/Fixtures/Projects/golden/` — `api/openapi.yaml`, `api/shared/common.yaml`, конфиги `php<ver>.yaml`; эталоны `expected/<ver>/*.php.golden`. `GoldenProjectTest` гоняет `DtoGenerator::generator()` в dry-run, `UPDATE_SNAPSHOTS=1` перезаписывает; `make test-targets` линтует и прогоняет PHPStan max по `expected/<ver>`.
- Функциональные тесты CLI — suite `functional` (`tests/Functional`), они же входят в infection (`testFrameworkOptions`).

