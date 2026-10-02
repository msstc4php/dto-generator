# DTO Generator — этап 6b: Composer-плагин и Docker-образ. План реализации

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** `composer dump-autoload` (и `install`/`update`) сам перегенерирует DTO, если в `composer.json` задан `extra.dto-generator.config`. Генератор можно запустить из Docker-образа, не устанавливая PHP-зависимости в проект.

**Spec:** §9.3 (плагин: `post-autoload-dump`, `extra.dto-generator.config`, `failOnError`, `--no-plugins`/`--no-scripts`), §9.4 (Docker: multi-stage, `php:8.4-cli-alpine`, non-root, `WORKDIR /app`, `ENTRYPOINT ["dto-generator", "generate"]`, `DTO_GENERATOR_VERIFY_CLASSES=0`), §11.3 (CI).

## Global Constraints
Те же, что в этапах 3–6a. Ветка — `feat/stage-6b-composer-docker`.

## Решения
- **Пакет — Composer-плагин.**
  - `type: composer-plugin`, `extra.class: MSSTC4PHP\DtoGenerator\ComposerPlugin`, `require: composer-plugin-api ^2.0`. Отдельного пакета нет, как и сказано в §9.3.
  - Цена: Composer 2.2+ спросит `allow-plugins` при установке. Отказ отключает только плагин; CLI и PHP API работают.
  - `require-dev: composer/composer ^2.2` — типы плагина для тестов и PHPStan.
- **Плагин** (`src/ComposerPlugin.php`, слой EntryPoint: его создаёт сам Composer, и он собирает генератор через `DtoGenerator`): `PluginInterface` + `EventSubscriberInterface`, подписка на `ScriptEvents::POST_AUTOLOAD_DUMP`.
  - Нет `extra.dto-generator` или `config` — ничего не делает.
  - `config` — непустая строка, путь относительно каталога `composer.json` проекта (рабочий каталог Composer). `failOnError` — bool, по умолчанию `false`. Неверный тип — сообщение и (при `failOnError: true`) провал команды.
  - Запуск `<bin-dir>/dto-generator generate --config=… --no-ansi` отдельным процессом (`ProcessExecutor`): внутри Composer классы, `installed.json` и библиотеки — его собственные, а не проекта. Вывод CLI печатается через IO Composer с префиксом `dto-generator: `; stderr — стилем `warning` (или `error` при `failOnError` и ненулевом коде), итог — как у CLI (`Written: N, deleted: M, unchanged: K.`).
  - `bin/dto-generator` сначала берёт `$GLOBALS['_composer_autoload_path']` от bin-прокси Composer, так что и пакет, подключённый симлинком, работает с автозагрузчиком проекта.
  - Конфиг ищется относительно каталога `composer.json` проекта (учитывая переменную `COMPOSER`).
  - Статус `config-failed`/`generation-failed`: при `failOnError: false` — всё как warning, команда успешна; при `true` — исключение, Composer завершает команду с ошибкой.
  - Код Console в пути плагина не используется: Composer несёт свою копию symfony/console.
  - `--no-plugins` — плагин не загружается. `--no-scripts` Composer плагинам не скрывает, поэтому плагин читает защищённое `EventDispatcher::$runScripts`; если прочитать не удалось — запускается.
  - `extra.dto-generator` без `config` — предупреждение (ошибка при `failOnError: true`).
- **Docker** (`docker/Dockerfile`):
  - stage 1 `composer:2`: копия пакета в `/opt/dto-generator`, `composer install --no-dev --classmap-authoritative --no-plugins --no-scripts`;
  - stage 2 `php:8.4-cli-alpine`: копия из stage 1, `ln -s /opt/dto-generator/bin/dto-generator /usr/local/bin/dto-generator`, `ENV DTO_GENERATOR_VERIFY_CLASSES=0`, `USER 1000:1000` по умолчанию (переопределяется `-u`), `WORKDIR /app`, `ENTRYPOINT ["dto-generator", "generate"]`.
  - Имя образа — `ghcr.io/msstc4php/dto-generator`; публикация в реестр — вне этапа (внешнее действие).
  - `.dockerignore` — tests, tools, vendor, var, docs.
  - `make docker-build` и `make docker-smoke`: копия golden-проекта во временный каталог, `docker run -u … -v …:/app <image> --config=php8.2.yaml`, сравнение вывода с `expected/8.2`.
- **CI:** job `docker` — сборка образа и `make docker-smoke`.

## Review Focus
1. `extra.dto-generator` без `config`, `config` не строкой, `failOnError` не bool.
2. Путь конфига относительный и абсолютный; рабочий каталог Composer (`--working-dir`).
3. Ошибка конфига генератора при `failOnError: false` и `true`.
4. Образ: запуск под произвольным `-u uid:gid`, запись в смонтированный `/app`, `target.php: auto` по `composer.json` проекта.
5. Плагин не требует `symfony/console` нужной версии (в Composer своя).

## Tasks
1. Плагин: `composer.json` (type, extra.class, composer-plugin-api, composer/composer dev), `Presentation/Composer/Plugin` с тестами на `BufferIO`.
2. Docker: `docker/Dockerfile`, `.dockerignore`, `make docker-build`/`docker-smoke`, job в CI.
3. Документация (README: плагин, Docker), infection, ревью, слияние.
