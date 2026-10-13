# Интеграции

| Что | Порт (Application) | Адаптер (Infrastructure) | Заметки |
|---|---|---|---|
| Файлы спецификаций и конфига | `Port\DocumentLoader` (+ `Port\Document`, `Port\DocumentLoadFailed::path()`) | `Document\FileDocumentLoader` | `.json` — `json_decode` с `JSON_THROW_ON_ERROR`; `.yaml/.yml` — `symfony/yaml` (на 7.4 ставится 5.4). Идентичность документа — лексически нормализованный **запрошенный** путь; кеш разобранного — по `realpath`. |
| `require.php` проекта | `Port\ProjectPhpConstraint` → `Port\PhpRequirement` (файл, ограничение, причина отказа) | `Environment\ComposerJsonPhpConstraint` | Ближайший `composer.json` вверх от каталога конфига; поиск останавливается на нём, даже если `php` там нет. Ограничение разбирает `Config\PhpConstraint`: нижняя граница только у `>=`, `>`, `^`, `~`, голой версии и начала диапазона `a - b`; оператор, отделённый пробелом (`<= 8.0`), приклеивается к версии. Ограничение без нижней границы (`<8.0`) и нечитаемый `composer.json` дают предупреждение и PHP 7.4. |
| Расширения (SPI) | `Port\ExtensionLoader::load(ClassName): Extension`, бросает `Port\ExtensionFailed` | `Extension\ClassExtensionLoader` | Класс через автозагрузчик процесса; не существует / не реализует `Contract\Extension` / не инстанцируется (интерфейс, abstract) / требует аргументов конструктора → `ExtensionFailed`, use-case пишет «Extension X cannot be loaded: …» на `#/extensions/<i>`. |

Тестовые двойники: `tests/Support/InMemoryDocumentLoader`, `tests/Support/FixedPhpConstraint`.

UTF-8 BOM срезается; NUL-байт в пути — `DocumentLoadFailed::notFound` (а не `ValueError` из `realpath`).

## Composer и Docker (этап 6b)

- Пакет — `composer-plugin`: потребителю нужно `allow-plugins` (Composer 2.2+ спросит). Отказ отключает только плагин.
- Docker-образ обнаруживает расширения из своего vendor, читает `composer.json`/`composer.lock` смонтированного проекта и не выполняет его код при `verifyClasses: auto`. Образ `ghcr.io/msstc4php/dto-generator` публикует `.github/workflows/docker-image.yml` по тегу `vX.Y.Z` (или вручную: `gh workflow run docker-image.yml -f tag=vX.Y.Z`): smoke на amd64, затем amd64+arm64 (arm64 через QEMU). Плавающие `X.Y`/`X`/`latest` двигаются, только если релиз — новейший в своей линии; шаг падает, если `ARG VERSION` в Dockerfile тега не совпадает с тегом (поэтому релизный коммит обязан поднять `ARG VERSION`).

## Пакеты и расширения потребителя (этап 6a)

- `composer.lock` проекта читается как данные: версии пакетов для `InstalledPackages` расширений.
- Расширения из `extra.dto-generator.extensions` (список FQCN) установленных пакетов подключаются автоматически. Источник — `vendor/composer/installed.json` той установки, где лежит генератор: при обычной установке это vendor потребителя, в Docker — образа. Глобально установленный генератор не увидит расширений проекта.

## Автозагрузчик потребителя (этап 5b)

`verifyClasses` подключает `autoload.php` ближайшего вверх Composer-проекта (его `vendor-dir`) через `require`, при первом вопросе и только если есть атрибут для проверки. Это **выполнение чужого кода**. Загрузчик потребителя ставится после загрузчика генератора, чтобы его версии пакетов (php-parser, symfony) не подменили наши; `files` и platform check потребителя всё равно выполняются. Безопасно, когда генератор и потребитель делят один vendor; при разных vendor возможны конфликты функций/констант из `files`. Переменная окружения `DTO_GENERATOR_VERIFY_CLASSES=0` выключает режим `auto`; явное `true` она не отменяет.

## SPI: разрешение `$ref` (для моста Symfony)

`PropertyContext::references()` / `ClassContext::references()` → `Contract\SchemaReferences::resolve(Schema): Schema` идёт по цепочке `$ref` через `SchemaGraph::resolve(ReferenceUse)`; неразрешимая ссылка — сама схема со ссылкой; цикл — схема, на которой он замыкается. Ключевые слова рядом с `$ref` не объединяются: они остаются на исходной схеме, расширение читает её первой. `chain(Schema): non-empty-list<Schema>` — та же цепочка целиком (при цикле — до замыкания, без повтора); `resolve()` = конец `chain()`, кроме цикла, где это первая повторённая схема (она уже есть в `chain()`). Контекст, созданный без графа (тесты расширений), получает `SchemaReferences::none()`: схема как есть. `Enrich` создаёт один `SchemaReferences` на запуск (`EnrichmentRun::references()`).

## SPI: выбирающий дискриминатор и catch-all (v1.1.0)

- `ClassContext::selectingDiscriminator()` → `?DiscriminatorModel` ближайшего предка, в чьём `mapping()` есть этот класс: в глубокой иерархии (`Pet → Mammal → Cat`, Swagger-2 или явный mapping на внука) дискриминатор лежит только на базе, у прямого родителя его нет. `Enrich\Action::selectingDiscriminator()` идёт по `parent()` через индекс FQCN → ClassModel всех классов прогона (порядок обогащения не важен); родитель вне прогона обрывает поиск → null. В `PropertyContext` метода нет: свойство-дискриминатор объявлено на базе, варианты его наследуют, а ядро обогащает свойство там, где оно объявлено, — у варианта нет свойства для ограничения (поэтому проверка значения у вариантов отложена в E: ядро будет само выставлять значение в конструкторе варианта). Вариант без собственных свойств (`allOf: [$ref Base]`) — алиас базы, класса и строки в mapping нет.
- `PropertyModel::isAdditionalProperties()` — true только у свойства `$additionalProperties`, собирающего необъявленные ключи (`properties` + схема `additionalProperties`); объявленное в `properties` свойство с тем же именем — false. Мосту нужно, чтобы не путать их по JSON Pointer. Флаг — необязательный последний параметр конструктора, `withAddedAttributes()` его сохраняет.

## SPI для авторов расширений (этап 5a)
- Расширение создаётся без аргументов; `name()` — ключ секции `extensionConfig` (секция не-объект → ошибка, расширение получает `[]`).
- Порядок: встроенное `custom-attributes`, затем `extensions` конфига по порядку; в этом порядке выводятся и атрибуты. Обнаружение через composer — этап 6.
- `addFormat`: формат двух расширений → ошибка; формат из `formats` конфига перекрывает формат расширения.
- `claimExtensionKeys(glob…)`: только `x-…`, не покрывая ключи ядра и префиксы `x-php-`/`x-dto-`; алиас из `attributeAliases`, совпадающий с заявленным ключом, → ошибка.
- `InstalledPackages` до этапа 6 пустой.
- Enricher видит только собственные свойства класса; унаследованные обогащаются в базовом классе.

- **`ClassContext::ancestors()` (1.5, 2026-10-13 UTC).** Предки класса, ближайший первым — модели прогона *до* enrichment (у каждой только свои свойства и `source()` её схемы). Заполняет `Enrich\Action::ancestors()` по `parent()` через модели всего прогона (по FQCN; при `readWriteModels: split` у read/write-видов свой ряд предков с тем же `source()`); список обрывается на родителе вне прогона и на цикле (сам класс в предки не попадает). Мост по нему находит унаследованные свойства и места схемы предка.
