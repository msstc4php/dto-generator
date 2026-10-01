# msstc4php/dto-generator

Генератор PHP DTO из схем OpenAPI 3.1 (`components/schemas`, YAML/JSON) с учётом целевой версии PHP (7.4–8.5).

> **Статус:** в разработке. Готов этап 1 — доменная модель (схема, IR, профиль целевой версии).
> Генерация файлов, CLI, Composer-плагин и Docker-образ появятся в следующих этапах.

Дизайн: [`docs/specs/2026-10-01-dto-generator-design.md`](docs/specs/2026-10-01-dto-generator-design.md).

## Требования

- PHP ≥ 7.4 для запуска генератора.
- Для разработки: PHP 8.x локально и Docker (тесты и lint на 7.4).

## Разработка

```bash
make install   # зависимости пакета и инструментов (tools/)
make check     # PHPStan, CS-Fixer, Rector, deptrac, lint на PHP 7.4
make test      # PHPUnit на локальном PHP
make test-74   # PHPUnit в контейнере php:7.4-cli
make infection # мутационное тестирование
make fix       # автоисправление стиля и Rector
```

## Лицензия

MIT
