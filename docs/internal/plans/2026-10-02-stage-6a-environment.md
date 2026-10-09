# DTO Generator — этап 6a: окружение потребителя и обнаружение расширений. План реализации

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Расширения получают реальные версии пакетов потребителя (`InstalledPackages` из `composer.lock`). Расширения, объявленные в `extra.dto-generator.extensions` установленных пакетов, подключаются автоматически.

**Spec:** §8 (контексты: `InstalledPackages` из `composer.lock`; «Подключение расширений»: явные, затем обнаруженные по имени пакета; `discoverExtensions: false`), §4 (`discoverExtensions`), §9.4 (Docker: код потребителя не выполняется).

## Global Constraints
Те же, что в этапах 3–5b: исходники на PHP 7.4, PHPStan max, deptrac, MSI 100 %, golden-матрица. Ветка — `feat/stage-6a-environment`.

## Решения
- **Проект потребителя.** Ближайший `composer.json` вверх от каталога конфига — общий для всех адаптеров окружения (`Infrastructure/Environment/ComposerProject::nearest()`). `AutoloadClassVerifierLocator` переходит на него.
- **`InstalledPackages`.**
  - Порт `Application/Port/ProjectPackages::read(string $directory): InstalledPackages`; при нечитаемом или битом lock бросает `ProjectPackagesUnusable` (файл и причина).
  - Адаптер `Infrastructure/Environment/ComposerLockPackages` читает `composer.lock` рядом с найденным `composer.json`: `packages` и `packages-dev`, `name` → `version` как записано (`v6.4.1` остаётся `v6.4.1`).
  - Нет проекта или нет lock — пустой набор без диагностики. Битый lock — warning на `<lock>` и пустой набор: генерация не зависит от расширений, которым нужны версии.
  - Переменная `COMPOSER` (другое имя файла) не поддерживается, как и у `verifyClasses`.
- **Обнаружение расширений.**
  - Порт `Application/Port/ExtensionDiscovery::discover(): DiscoveredExtensions` (список `DiscoveredExtension{package, class}` и список проблем).
  - Адаптер `Infrastructure/Environment/InstalledJsonExtensionDiscovery` читает `vendor/composer/installed.json` той установки, из которой запущен генератор (каталог `Composer\InstalledVersions`). Так в Docker обнаруживаются расширения образа, а при обычной установке — проекта потребителя, и код потребителя не выполняется.
  - Поддерживаются форматы Composer 2 (`{"packages": [...]}`) и Composer 1 (список).
  - `extra.dto-generator.extensions` — список FQCN. Другой тип или не-FQCN-элемент — проблема: warning `Package "p" declares …` на `#/discoverExtensions`, пакет пропускается целиком или элемент — поэлементно.
  - Нечитаемый или битый `installed.json` — одна проблема; обнаружение пустое.
- **Порядок и дубли.** `Load\Action`: встроенные, затем явные из `extensions` в порядке конфига, затем обнаруженные по имени пакета (внутри пакета — в порядке объявления). Обнаруженный класс, уже указанный явно (без учёта регистра), пропускается молча. Ошибка загрузки обнаруженного — error `Extension X, discovered in package p, cannot be loaded: …` на `#/discoverExtensions`.
- **Подключение.** `Generate\Action` читает пакеты для `config->baseDir()` и передаёт их в `EnrichInput` (сейчас там пустой `new InstalledPackages()`). `DtoGenerator::generator()` собирает адаптеры.

## Review Focus
1. Lock с `packages-dev: null`, без `packages`, с пакетом без `version`.
2. `installed.json` Composer 1 (список) и Composer 2; пакет без `extra`; `extensions` строкой вместо списка.
3. Класс, указанный и явно, и через обнаружение (в другом регистре) — загружается один раз.
4. `discoverExtensions: false` — `installed.json` не читается вовсе.
5. Два обнаруженных расширения с одним `name()` — второе отклоняется так же, как явное.

## Tasks
1. `ComposerProject`, порт `ProjectPackages` и `ComposerLockPackages`; `InstalledPackages` в `Generate`; `AutoloadClassVerifierLocator` на `ComposerProject`.
2. Порт `ExtensionDiscovery`, `InstalledJsonExtensionDiscovery`, порядок и дубли в `Load\Action`, подключение в `DtoGenerator`.
3. Документация, infection, ревью, слияние.
