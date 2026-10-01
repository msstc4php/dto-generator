# Конвенции проекта

Отличия от глобальных правил, продиктованные рантаймом PHP 7.4:

- Нет нативных enum → наследники `Domain/Shared/AbstractEnum` (`X::from(X::CONST)`, сравнение `===` или `equals()`); экземпляры интернированы.
- Нет `readonly` → `private` типизированные свойства + геттеры, `final` классы, `with*()` через `clone`/`new self`.
- Нет union-типов и `mixed` в сигнатурах → тип в PHPDoc, нативный тип опущен. JSON-значения — алиас `JsonValue` из `Domain/Shared/Json` (`@phpstan-import-type JsonValue from Json`).
- В многострочных списках **параметров** висячая запятая запрещена (PHP 8.0); в вызовах и массивах — обязательна (CS-Fixer настроен так).
- Функции 8.0+ (`str_contains`, `str_starts_with`…) не использовать: `strncmp`/`strpos`.
- PHPUnit 9.6: `@dataProvider` в аннотациях, провайдеры `public static`.
- Проверка «код парсится на 7.4» — `make lint-74`, входит в `make check`; тесты на 7.4 — `make test-74`.
- Нарушение инварианта модели → `Domain\Exception\InvalidModel`; вызов accessor'а не того вида → `\LogicException`.
