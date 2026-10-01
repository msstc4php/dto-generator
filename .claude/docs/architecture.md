# Архитектура

Hex-подход, адаптированный под библиотеку; правило зависимостей проверяет deptrac (`deptrac.yaml`).

| Слой | Каталог | Может зависеть от |
|---|---|---|
| Domain | `src/Domain/{Schema,Model,Target,Shared,Exception}` | — |
| DomainService | `src/Domain/Builder` | Domain |
| Contract (SPI) | `src/Contract` | Domain |
| Application | `src/Application` | Domain, DomainService, Contract |
| Infrastructure | `src/Infrastructure` | Domain, Application, Contract, Symfony, PhpParser, Composer |
| Presentation | `src/Presentation` | Application, Domain, Contract, Symfony, Composer |
| Extension | `src/Extension` | Contract, Domain |

Состояние на 2026-10-01 (UTC): реализован только Domain (этап 1). Остальные каталоги появятся в этапах 2–6.

- `Domain/Schema` — разобранная JSON Schema; `$ref` хранится как строка, разрешение — вне домена.
- `Domain/Model` — IR, из которого Emitter строит код. Enricher'ы (SPI) только **добавляют** `AttributeModel`.
- `Domain/Target` — `TargetProfile`: версия PHP + режимы; все решения «можно ли на этой версии» идут через `Capability`.
- deptrac: слой `TypeAlias` — PHPStan-алиасы `JsonValue`/`JsonScalar`, которые deptrac считает классами; анализ с `--fail-on-uncovered`.
- Инструменты в `tools/` (отдельный `composer.json`), чтобы `require-dev` пакета ставился на PHP 7.4.
- Открытый вопрос к этапу 3: Presentation (CLI) будет точкой сборки и должен инстанцировать адаптеры Infrastructure — правило deptrac для этого придётся расширить или вынести composition root.
