# dto-generator — база знаний

Внутренние заметки для работы над пакетом. Спецификация: `docs/internal/specs/2026-10-01-dto-generator-design.md`; планы: `docs/internal/plans/`.

Пользовательская документация (английский, входит в пакет): `README.md`, `CHANGELOG.md`, `SECURITY.md`,
`docs/configuration.md`, `docs/x-extensions.md`, `docs/openapi-support.md`, `docs/extensions-spi.md`. `docs/internal/`
(спеки и планы на русском) и dev-файлы исключены из архива пакета через `export-ignore` в `.gitattributes`. Изменение
поведения для пользователя → запись в `CHANGELOG.md` (Unreleased) и правка соответствующей страницы `docs/`.
Примеры кода в документации получены реальным прогоном генератора — при изменении emitter их надо перегенерировать.

| Файл | О чём |
|---|---|
| [architecture.md](architecture.md) | Слои, правило зависимостей, где что лежит |
| [domain-model.md](domain-model.md) | Schema, IR, TargetProfile — инварианты |
| [conventions.md](conventions.md) | Правила кода, обязательные из-за рантайма PHP 7.4 |
| [known-issues.md](known-issues.md) | Подводные камни и ограничения |
| [integrations.md](integrations.md) | Порты, адаптеры, внешние библиотеки |
