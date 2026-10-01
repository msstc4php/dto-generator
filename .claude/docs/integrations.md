# Интеграции

| Что | Порт (Application) | Адаптер (Infrastructure) | Заметки |
|---|---|---|---|
| Файлы спецификаций и конфига | `Port\DocumentLoader` (+ `Port\Document`, `Port\DocumentLoadFailed::path()`) | `Document\FileDocumentLoader` | `.json` — `json_decode` с `JSON_THROW_ON_ERROR`; `.yaml/.yml` — `symfony/yaml` (на 7.4 ставится 5.4). Идентичность документа — лексически нормализованный **запрошенный** путь; кеш разобранного — по `realpath`. |
| `require.php` проекта | `Port\ProjectPhpConstraint` → `Port\PhpRequirement` (файл, ограничение, причина отказа) | `Environment\ComposerJsonPhpConstraint` | Ближайший `composer.json` вверх от каталога конфига; поиск останавливается на нём, даже если `php` там нет. Ограничение разбирает `Config\PhpConstraint`: нижняя граница только у `>=`, `>`, `^`, `~`, голой версии и начала диапазона `a - b`. |

Тестовые двойники: `tests/Support/InMemoryDocumentLoader`, `tests/Support/FixedPhpConstraint`.

UTF-8 BOM срезается; NUL-байт в пути — `DocumentLoadFailed::notFound` (а не `ValueError` из `realpath`).
