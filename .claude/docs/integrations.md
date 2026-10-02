# Интеграции

| Что | Порт (Application) | Адаптер (Infrastructure) | Заметки |
|---|---|---|---|
| Файлы спецификаций и конфига | `Port\DocumentLoader` (+ `Port\Document`, `Port\DocumentLoadFailed::path()`) | `Document\FileDocumentLoader` | `.json` — `json_decode` с `JSON_THROW_ON_ERROR`; `.yaml/.yml` — `symfony/yaml` (на 7.4 ставится 5.4). Идентичность документа — лексически нормализованный **запрошенный** путь; кеш разобранного — по `realpath`. |
| `require.php` проекта | `Port\ProjectPhpConstraint` → `Port\PhpRequirement` (файл, ограничение, причина отказа) | `Environment\ComposerJsonPhpConstraint` | Ближайший `composer.json` вверх от каталога конфига; поиск останавливается на нём, даже если `php` там нет. Ограничение разбирает `Config\PhpConstraint`: нижняя граница только у `>=`, `>`, `^`, `~`, голой версии и начала диапазона `a - b`; оператор, отделённый пробелом (`<= 8.0`), приклеивается к версии. Ограничение без нижней границы (`<8.0`) и нечитаемый `composer.json` дают предупреждение и PHP 7.4. |

| Расширения (SPI) | `Port\ExtensionLoader::load(ClassName): Extension`, бросает `Port\ExtensionFailed` | `Extension\ClassExtensionLoader` | Класс через автозагрузчик процесса; не существует / не реализует `Contract\Extension` / не инстанцируется (интерфейс, abstract) / требует аргументов конструктора → `ExtensionFailed`, use-case пишет «Extension X cannot be loaded: …» на `#/extensions/<i>`. |

Тестовые двойники: `tests/Support/InMemoryDocumentLoader`, `tests/Support/FixedPhpConstraint`.

UTF-8 BOM срезается; NUL-байт в пути — `DocumentLoadFailed::notFound` (а не `ValueError` из `realpath`).

## SPI для авторов расширений (этап 5a)
- Расширение создаётся без аргументов; `name()` — ключ секции `extensionConfig` (секция не-объект → ошибка, расширение получает `[]`).
- Порядок: встроенное `custom-attributes`, затем `extensions` конфига по порядку; в этом порядке выводятся и атрибуты. Обнаружение через composer — этап 6.
- `addFormat`: формат двух расширений → ошибка; формат из `formats` конфига перекрывает формат расширения.
- `claimExtensionKeys(glob…)`: только `x-…`, не покрывая ключи ядра и префиксы `x-php-`/`x-dto-`; алиас из `attributeAliases`, совпадающий с заявленным ключом, → ошибка.
- `InstalledPackages` до этапа 6 пустой.
- Enricher видит только собственные свойства класса; унаследованные обогащаются в базовом классе.
