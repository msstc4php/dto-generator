# msstc4php/dto-generator

Генератор PHP DTO из схем OpenAPI 3.1 (`components/schemas`, YAML/JSON) с учётом целевой версии PHP (7.4–8.5).

> **Статус:** в разработке. Готовы этапы 1–4: загрузка конфига и спецификаций с графом `$ref`, построение классов
> (spec §5.1–§5.5), enum, map-типы и вынос инлайн-схем, композиция `allOf` (наследование или слияние),
> `oneOf`/`anyOf` с discriminator (abstract-база) и без него (union-тип), генерация кода под PHP 7.4–8.5
> (spec §6.2), запись с манифестом и CLI, расширения (SPI), `x-php-attributes` и `attributeAliases` с выводом
> атрибутов PHP 8 (этап 5a), аннотации Doctrine для PHP 7.4 и `verifyClasses` (этап 5b), версии пакетов из
> `composer.lock` и обнаружение расширений через `extra.dto-generator.extensions` (этап 6a), Composer-плагин и
> Docker-образ (этап 6b). Ядро готово; впереди мост `dto-generator-bridge-symfony`.

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

Коды выхода: `0` — успех, `1` — `--check` нашёл расхождения, `2` — ошибки в схемах, при записи или
непредвиденный сбой, `3` — ошибка конфигурации или неверные опции. Все ошибки выводятся разом (в stderr),
с местом в схеме. При ошибке в конфиге или схемах ничего не пишется; если сбой случится посреди записи
(нет прав, диск), уже записанное не откатывается — повторный запуск доведёт вывод до нужного состояния.

В каждом `outputDir` лежит `.dto-generator.manifest.json` (его стоит коммитить): генератор удаляет файлы, которые
сгенерировал раньше, никогда не перезаписывает файл без заголовка `@generated` и отказывается работать с битым
манифестом. Хеши в манифесте справочные — владение определяет заголовок. Сгенерированные файлы всегда с LF;
`--check` считает CRLF-копию (git `core.autocrlf`) неизменной, но лучше зафиксировать
`<outputDir>/** text eol=lf` в `.gitattributes`. Параллельные запуски для одного `outputDir` ждут друг друга (через lock-файл во временном каталоге системы; если он недоступен — без ожидания).

Расширения подключаются явно (`extensions:` — список классов) и автоматически: пакеты, установленные рядом с
генератором, объявляют свои в `extra.dto-generator.extensions` своего `composer.json`. Автообнаружение видит
только vendor, в котором лежит сам генератор (у глобальной установки или установки в `tools/` — свой vendor);
отключается через `discoverExtensions: false`. Версии пакетов проекта расширения берут из его `composer.lock`.

Из PHP:

```php
use MSSTC4PHP\DtoGenerator\Application\Service\Generate\Input;
use MSSTC4PHP\DtoGenerator\Application\Service\Generate\Mode;
use MSSTC4PHP\DtoGenerator\DtoGenerator;

$output = DtoGenerator::generator()(new Input('/path/to/dto-generator.yaml', Mode::from(Mode::WRITE)));
$output->status()->value();   // ok | out-of-date | generation-failed | config-failed
$output->diagnostics()->all();
```

### Composer-плагин

Пакет — Composer-плагин: после каждого `install`, `update` и `dump-autoload` он перегенерирует DTO, если в
`composer.json` проекта задан путь к конфигу (путь — относительно `composer.json`). Ставьте пакет в `require-dev`
(`composer require --dev msstc4php/dto-generator`): иначе генерация пойдёт и при `composer install --no-dev` на
деплое. Плагин запускает `vendor/bin/dto-generator` отдельным процессом, поэтому результат тот же, что у CLI.

```json
{
    "config": {"allow-plugins": {"msstc4php/dto-generator": true}},
    "extra": {"dto-generator": {"config": "dto-generator.yaml", "failOnError": false}}
}
```

По умолчанию ошибки генерации печатаются как предупреждения и команду Composer не роняют; `failOnError: true`
делает их ошибкой команды. `--no-plugins` и `--no-scripts` плагин отключают. Если плагин не разрешён в
`allow-plugins`, работают только CLI и PHP API.

### Docker

```bash
docker build -f docker/Dockerfile -t dto-generator .
docker run --rm -u "$(id -u):$(id -g)" -v "$PWD:/app" dto-generator --config=dto-generator.yaml [--check]
```

`target.php: auto` берётся из `composer.json` смонтированного проекта. Код проекта контейнер не выполняет:
`verifyClasses: auto` в нём выключен (`DTO_GENERATOR_VERIFY_CLASSES=0`), явное `verifyClasses: true` подключит
автозагрузчик проекта. Расширения — только установленные в образ.

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
make docker-smoke # собрать образ и сгенерировать им golden-проект
make fix       # автоисправление стиля и Rector
```

## Лицензия

MIT
