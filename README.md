# msstc4php/dto-generator

Генератор PHP DTO из схем OpenAPI 3.1 (`components/schemas`, YAML/JSON) с учётом целевой версии PHP (7.4–8.5).

> **Статус:** в разработке. Готовы этапы 1–3: загрузка конфига и спецификаций с графом `$ref`, построение классов
> (spec §5.1–§5.5), генерация кода под PHP 7.4–8.5 (spec §6.2), запись с манифестом и CLI.
> Enum и композиция (этап 4), атрибуты и SPI (этап 5), Composer-плагин и Docker-образ (этап 6) — впереди.

Дизайн: [`docs/specs/2026-10-01-dto-generator-design.md`](docs/specs/2026-10-01-dto-generator-design.md).

## Использование

`dto-generator.yaml` рядом с `composer.json` (пути — относительно файла конфига):

```yaml
version: 1
target:
  php: auto            # нижняя граница require.php из composer.json; иначе 7.4
dto:
  mutability: immutable
sources:
  - spec: openapi/public.yaml
    namespace: App\Dto
    outputDir: src/Dto
```

```bash
vendor/bin/dto-generator generate                # записать классы
vendor/bin/dto-generator generate --check        # ничего не писать; код 1, если вывод устарел (для CI)
vendor/bin/dto-generator generate --dry-run      # показать, что изменится
vendor/bin/dto-generator generate --format=json  # машиночитаемый отчёт
```

Коды выхода: `0` — успех, `1` — `--check` нашёл расхождения, `2` — ошибки в схемах или при записи,
`3` — ошибка конфигурации. Все ошибки выводятся разом, с местом в схеме. При любой ошибке ничего не пишется.

В каждом `outputDir` лежит `.dto-generator.manifest.json`: генератор удаляет файлы, которые сгенерировал раньше,
и никогда не перезаписывает файл без заголовка `@generated`.

Из PHP:

```php
use MSSTC4PHP\DtoGenerator\Application\Service\Generate\Input;
use MSSTC4PHP\DtoGenerator\Application\Service\Generate\Mode;
use MSSTC4PHP\DtoGenerator\DtoGenerator;

$output = DtoGenerator::generator()(new Input('/path/to/dto-generator.yaml', Mode::from(Mode::WRITE)));
$output->status()->value();   // ok | out-of-date | generation-failed | config-failed
$output->diagnostics()->all();
```

## Требования

- PHP ≥ 7.4 для запуска генератора.
- Для разработки: PHP 8.x локально и Docker (тесты и lint на 7.4).

## Разработка

```bash
make install   # зависимости пакета и инструментов (tools/)
make check     # PHPStan, CS-Fixer, Rector, deptrac, lint на PHP 7.4
make test      # PHPUnit на локальном PHP
make test-74   # PHPUnit в контейнере php:7.4-cli
make test-targets # сгенерированный код: php -l, smoke и PHPStan max на php:7.4…8.5-cli (Docker)
make infection # мутационное тестирование
make fix       # автоисправление стиля и Rector
```

## Лицензия

MIT
