# Конвенции проекта

Отличия от глобальных правил, продиктованные рантаймом PHP 7.4:

- Нет нативных enum → наследники `Domain/Shared/AbstractEnum` (`X::from(X::CONST)`, сравнение `===` или `equals()`); экземпляры интернированы.
- Нет `readonly` → `private` типизированные свойства + геттеры, `final` классы, `with*()` через `clone`/`new self`.
- Нет union-типов и `mixed` в сигнатурах → тип в PHPDoc, нативный тип опущен. JSON-значения — алиас `JsonValue` из `Domain/Shared/Json` (`@phpstan-import-type JsonValue from Json`).
- В многострочных списках **параметров** висячая запятая запрещена (PHP 8.0); в вызовах и массивах — обязательна (CS-Fixer настроен так).
- Функции 8.0+ (`str_contains`, `str_starts_with`…) не использовать: `strncmp`/`strpos`. `phpVersion: 70400` их **не** ловит (они есть в анализирующем рантайме 8.4) — запрещены через `disallowedFunctionCalls`/`disallowedClasses` в `phpstan.dist.neon`; пополнять список при необходимости.
- `Schema::keywords()` может содержать int-ключи (числовые имена); для строк — `keywordNames()`.
- PHPUnit 9.6: `@dataProvider` в аннотациях, провайдеры `public static`.
- Проверка «код парсится на 7.4» — `make lint-74`, входит в `make check`; тесты на 7.4 — `make test-74`.
- Нарушение инварианта модели → `Domain\Exception\InvalidModel`; вызов accessor'а не того вида → `\LogicException`. Все доменные исключения реализуют маркер `Domain\Exception\DomainError` — внешние слои ловят его одним `catch`.
- `with*()` заменяет значение; добавление — `withAdded*()`. Копии всегда через конструктор (инварианты перепроверяются), не через `clone`.
- `SchemaBuilder`: variadic-сеттеры заменяют, keyed (`property`, `keyword`) — добавляют.
- Сравнение идентификаторов — `Identifier::asciiLower()`, не `strtolower()` (на 7.4 зависит от локали). Регулярки на целую строку — с `\z`, не `$` (`$` пропускает завершающий `\n`).
- Тест исключения всегда проверяет и класс, и устойчивую подстроку сообщения.
- Полный гейт перед коммитом: `make fix && make verify` (= `check` + `test` + `test-74`); `make infection` держит MSI ≥ 99 % — эквивалентные мутанты перечислены в `infection.json5` с причиной.
