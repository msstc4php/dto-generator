# E2 — форма кода: план реализации

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Конструктор конкретного варианта принимает только значения дискриминатора, которые его выбирают (одно
значение — оно же default), у дискриминатора нет `with*()`/`set*()`; `dto.withers: false` убирает withers у
immutable DTO.

**Architecture:** Допустимые значения считает новый доменный сервис `Domain\Builder\SelectingValues` в конце
`Hierarchy::link()` — там уже известны родители, `ClassKind` и `DiscriminatorModel` с неявными именами (их
добавляет `VariantResolver`). Результат ложится на модель аддитивно: новый `Domain\Model\DiscriminatorValues` и
необязательный последний параметр `ClassModel::__construct(…, array $discriminatorValues = [])` с
`discriminatorValues()`, `discriminatorValuesOf()`, `withDiscriminatorValues()`. Emitter (`ClassShape`) по ним
строит проверку, default и порядок параметров и подавляет мутаторы дискриминатора. `dto.withers` идёт
`ConfigFactory` → `DtoSettings::withers()` → `TargetProfile(…, bool $withers = true)` → `classFormFor()` даёт
`WitherStyle::NONE`.

**Tech Stack:** PHP 7.4 (исходники), PHPUnit 9.6, PHPStan max (+ strict-rules), Infection 0.32, nikic/php-parser 5,
golden-проект и emitter-фикстуры на целях 7.4…8.5.

**Spec:** `docs/internal/specs/2026-10-09-e-features-design.md` §4 (решения владельца — §11, вариант B).

## Global Constraints

- Исходники в `src/` — PHP 7.4: без promotion, `match`, union-типов, атрибутов, `str_contains()`, nullsafe.
- Комментарии в коде — английский, только неочевидное «почему».
- Публичный API не ломается (`make bc-check`): новый класс `Domain\Model\DiscriminatorValues`, новые методы у
  `final` `ClassModel`/`TargetProfile`, необязательные параметры только в конце конструкторов. `ClassForm::immutable()`
  перестаёт бросать исключение на `WitherStyle::NONE` — это ослабление, не поломка.
- Выводимый код использует только встроенный `\InvalidArgumentException`; сообщение —
  `'"%s" does not select <ShortName> by "<wire name>".'`, `%` в wire-имени удваивается.
- **Отклонение от п. «PHPDoc параметра» (§4.1, пример со `@phpstan-param 'cat'|'kitty'`)**: литеральное уточнение
  параметра-дискриминатора **не выводится**. Проверено на PHPStan 2.3.1 + strict-rules (level max, как в
  `tests/Targets/run.sh`): с `@phpstan-param 'cat'|'kitty' $kind` проверка `$kind !== 'cat' && $kind !== 'kitty'`
  даёт `notIdentical.alwaysFalse` и `booleanAnd.alwaysFalse`, вариант с `in_array()` — `function.alreadyNarrowedType`,
  а `new self($this->kind, …)` в withers 8.1–8.4 — `argument.type` (`string` вместо `'cat'|'kitty'`). Значит, с
  уточнением сгенерированный код не проходит собственный гейт `make test-targets`. Параметр сохраняет PHPDoc своего
  типа (`@phpstan-param Kind::*` на 7.4/8.0, как сейчас). Решение вынести владельцу в отчёте этапа.
- По той же причине проверка не выводится, когда тип свойства уже допускает только выбирающие значения (enum, все
  case'ы которого выбирают класс; `const`): `DiscriminatorValues::isChecked() === false`, default остаётся.
- Значение mapping, указывающее на **open**-предка, класс не выбирает: open-класс конкретен, его значение выбирает
  его самого. Учитываются цели-сам класс и abstract-предки ниже базы дискриминатора (уточнение правила §4.1).
- Два дискриминатора цепочки читают одно свойство — решает ближайший к классу.
- Мост (`../msstc4symfony/dto-generator-bridge-symfony`) не меняется в E2: `RealSymfonyTest::testReadsAndWritesTheDiscriminatedSubclass`
  передаёт значение дискриминатора в конструктор по имени и продолжит проходить. Интеграционный тест «чужое значение
  → исключение» — задача E4.
- После каждой задачи: `make fix`, `vendor/bin/phpunit`; в конце — `make verify`, `make infection` (MSI 100%),
  `make test-targets`, `make bc-check` (последние три — вне песочницы, нужен Docker/сеть).

## Review Focus

1. Порядок параметров: дискриминатор с единственным значением получает default и уходит за обязательные
   параметры, `parent::__construct(...)` сохраняет порядок родителя, а `new self(...)` в withers 8.1–8.4 идёт в новом
   порядке (Task 3, `testMovesADefaultedDiscriminatorBehindTheRequiredParameters`).
2. Значение mapping у open-предка (`Dog extends Cat`, оба в mapping `Pet`) не допускается для `Dog` (Task 2,
   `testIgnoresTheValueOfAnOpenAncestor`).
3. Wire-имя с `%` и `'` даёт корректный формат `sprintf` (`pet%%type\'s`), а не предупреждение `sprintf()` или битую
   строку (Task 3, `testEscapesTheWireNameInTheMessageFormat`).
4. Проверка, которую тип уже гарантирует (enum из одного case, `const`), не выводится — иначе PHPStan max на
   сгенерированном коде падает с `notIdentical.alwaysFalse` (Task 2, `testLeavesValuesTheTypeAlreadyGuaranteesUnchecked`;
   Task 3, `testLeavesOutACheckTheTypeAlreadyMakes`).
5. Необязательный дискриминатор с несколькими значениями, чей default (`null`) ничего не выбирает, становится
   обязательным параметром и встаёт перед необязательными; default, который выбирает класс, сохраняется (Task 3,
   `testKeepsAPropertyDefaultOnlyWhenItSelectsTheClass`).

---

### Task 1: модель `DiscriminatorValues` и API `ClassModel`

**Files:**
- Create: `src/Domain/Model/DiscriminatorValues.php`
- Modify: `src/Domain/Model/ClassModel.php`
- Test: `tests/Unit/Domain/Model/DiscriminatorValuesTest.php`, `tests/Unit/Domain/Model/ClassModelTest.php`

**Interfaces:**
- Produces:
  - `new DiscriminatorValues(string $property, list<int|string> $values, bool $checked = true)`;
    `property(): string` (PHP-имя свойства), `values(): non-empty-list<int|string>` (в типе свойства; у enum —
    backing-значения), `isChecked(): bool`;
  - `ClassModel::__construct(…, ?DiscriminatorModel $discriminator = null, array $discriminatorValues = [])`;
  - `ClassModel::discriminatorValues(): list<DiscriminatorValues>` (дискриминатор корня первым),
    `discriminatorValuesOf(string $property): ?DiscriminatorValues`,
    `withDiscriminatorValues(DiscriminatorValues ...$values): self`.

- [ ] **Step 1: Write the failing test**

`tests/Unit/Domain/Model/DiscriminatorValuesTest.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Domain\Model;

use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\DiscriminatorValues;
use PHPUnit\Framework\TestCase;

final class DiscriminatorValuesTest extends TestCase
{
    public function testExposesTheValuesThatSelectAClass(): void
    {
        $values = new DiscriminatorValues('petType', ['cat', 2]);

        self::assertSame('petType', $values->property());
        self::assertSame(['cat', 2], $values->values());
        self::assertTrue($values->isChecked());
        self::assertFalse((new DiscriminatorValues('petType', ['cat'], false))->isChecked());
    }

    /**
     * @dataProvider unusable
     *
     * @param list<int|string> $values
     */
    public function testRejectsAnUnusableShape(string $property, array $values, string $message): void
    {
        $this->expectException(InvalidModel::class);
        $this->expectExceptionMessage($message);

        new DiscriminatorValues($property, $values);
    }

    /**
     * @return array<string, array{string, list<int|string>, string}>
     */
    public static function unusable(): array
    {
        return [
            'no values' => ['kind', [], 'Discriminator property "kind" has no value that selects the class.'],
            'not a property name' => ['1kind', ['a'], '"1kind" is not a usable PHP property name.'],
        ];
    }
}
```

В `ClassModelTest` (импорт `DiscriminatorValues`):

```php
    public function testOnlyFinalClassesAreSelectedByDiscriminatorValues(): void
    {
        $this->expectException(InvalidModel::class);
        $this->expectExceptionMessage('Only a final class is selected by discriminator values; App\User is open.');

        $this->classWith([], ClassKind::OPEN)->withDiscriminatorValues(new DiscriminatorValues('kind', ['user']));
    }

    public function testRejectsTwoValueSetsForOneProperty(): void
    {
        $this->expectException(InvalidModel::class);
        $this->expectExceptionMessage('Class App\User has two sets of discriminator values for $kind.');

        $this->classWith([])->withDiscriminatorValues(new DiscriminatorValues('kind', ['a']), new DiscriminatorValues('kind', ['b']));
    }

    public function testKeepsDiscriminatorValuesThroughEveryCopy(): void
    {
        $values = new DiscriminatorValues('kind', ['cat', 1]);
        $class = $this->classWith([$this->property('id')])->withDiscriminatorValues($values);

        self::assertSame([], $this->classWith([])->discriminatorValues());
        self::assertSame([$values], $class->discriminatorValues());
        self::assertSame($values, $class->discriminatorValuesOf('kind'));
        self::assertNull($class->discriminatorValuesOf('id'));
        self::assertSame([$values], $class->withProperties($this->property('name'))->discriminatorValues());
        self::assertSame([$values], $class->withAddedAttributes(new AttributeModel(ClassName::fromFqcn('App\Marker')))->discriminatorValues());
        self::assertSame([$values], $class->withHierarchy(ClassKind::from(ClassKind::FINAL), ClassName::fromFqcn('App\Pet'), null)->discriminatorValues());
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter 'DiscriminatorValuesTest|ClassModelTest'`
Expected: FAIL — `Class "…\DiscriminatorValues" not found`.

- [ ] **Step 3: Write minimal implementation**

`src/Domain/Model/DiscriminatorValues.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Model;

use MSSTC4PHP\DtoGenerator\Domain\Exception\InvalidModel;

/**
 * The values of one discriminator that select a final class: its constructor accepts no other.
 */
final class DiscriminatorValues
{
    private string $property;

    /** @var non-empty-list<int|string> */
    private array $values;

    private bool $checked;

    /**
     * @param string $property PHP name of the discriminating property, declared by the class or an ancestor
     * @param list<int|string> $values as the property holds them: the backing values of an enum
     * @param bool $checked false when the property's type admits no other value, so a check could never fail
     */
    public function __construct(string $property, array $values, bool $checked = true)
    {
        if (!Identifier::isValid($property)) {
            throw new InvalidModel(sprintf('"%s" is not a usable PHP property name.', $property));
        }

        if ($values === []) {
            throw new InvalidModel(sprintf('Discriminator property "%s" has no value that selects the class.', $property));
        }

        $this->property = $property;
        $this->values = $values;
        $this->checked = $checked;
    }

    public function property(): string
    {
        return $this->property;
    }

    /**
     * @return non-empty-list<int|string>
     */
    public function values(): array
    {
        return $this->values;
    }

    public function isChecked(): bool
    {
        return $this->checked;
    }
}
```

`ClassModel`: поле `/** @var list<DiscriminatorValues> */ private array $discriminatorValues;`, последний параметр
конструктора `array $discriminatorValues = []` (PHPDoc `@param list<DiscriminatorValues> $discriminatorValues root
discriminator first`), проверка после проверки discriminator:

```php
        if ($discriminatorValues !== [] && !$kind->equals(ClassKind::from(ClassKind::FINAL))) {
            throw new InvalidModel(sprintf('Only a final class is selected by discriminator values; %s is %s.', $name->fqcn(), $kind->value()));
        }

        $checked = [];
        foreach ($discriminatorValues as $values) {
            if (isset($checked[$values->property()])) {
                throw new InvalidModel(sprintf('Class %s has two sets of discriminator values for $%s.', $name->fqcn(), $values->property()));
            }

            $checked[$values->property()] = true;
        }
```

Методы:

```php
    /**
     * @return list<DiscriminatorValues> root discriminator first
     */
    public function discriminatorValues(): array
    {
        return $this->discriminatorValues;
    }

    public function discriminatorValuesOf(string $property): ?DiscriminatorValues
    {
        foreach ($this->discriminatorValues as $values) {
            if ($values->property() === $property) {
                return $values;
            }
        }

        return null;
    }

    public function withDiscriminatorValues(DiscriminatorValues ...$values): self
    {
        return new self(
            $this->name,
            $this->kind,
            $this->parent,
            $this->properties,
            $this->mutability,
            $this->doc,
            $this->source,
            $this->attributes,
            $this->discriminator,
            $values,
        );
    }
```

В `withAddedAttributes()`, `withHierarchy()`, `withProperties()` добавить последним аргументом
`$this->discriminatorValues`.

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter 'DiscriminatorValuesTest|ClassModelTest'`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
make fix && vendor/bin/phpunit
git add src/Domain/Model tests/Unit/Domain/Model
git commit -m "feat(model): a final class carries the discriminator values that select it"
```

### Task 2: `SelectingValues` — допустимые значения в Build

**Files:**
- Create: `src/Domain/Builder/SelectingValues.php`
- Modify: `src/Domain/Builder/Hierarchy.php` (`link()`, новый `selectVariants()`)
- Modify: `tests/Support/ModelFixture.php` (новый `selections()`)
- Test: `tests/Unit/Application/Service/Model/SelectingValuesTest.php`

**Interfaces:**
- Consumes: `DiscriminatorValues`, `ClassModel::withDiscriminatorValues()` (Task 1), `LiteralType::admits()`/`of()` (E1).
- Produces: `SelectingValues::of(array<string, ClassModel> $models, Diagnostics $diagnostics): array<string, non-empty-list<DiscriminatorValues>>`;
  `ModelFixture::selections(Output): array<string, list<string>>` — `"petType: 'cat'|'kitty'"`, с суффиксом
  `" unchecked"`, когда `isChecked()` ложно.

- [ ] **Step 1: Write the failing test**

В `ModelFixture`:

```php
    /**
     * @return array<string, list<string>> final class → "property: value|value[ unchecked]" per discriminator, root first
     */
    public static function selections(Output $output): array
    {
        $selections = [];
        foreach ($output->classes() as $class) {
            $lines = [];
            foreach ($class->model()->discriminatorValues() as $values) {
                $literals = array_map(static fn ($value): string => var_export($value, true), $values->values());
                $lines[] = $values->property() . ': ' . implode('|', $literals) . ($values->isChecked() ? '' : ' unchecked');
            }

            if ($lines !== []) {
                $selections[$class->model()->name()->fqcn()] = $lines;
            }
        }

        return $selections;
    }
```

`tests/Unit/Application/Service/Model/SelectingValuesTest.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Application\Service\Model;

use MSSTC4PHP\DtoGenerator\Tests\Support\ModelFixture;
use PHPUnit\Framework\TestCase;

final class SelectingValuesTest extends TestCase
{
    private const AT = '/project/api/openapi.yaml#/components/schemas/';

    public function testSelectsAVariantByItsMappingAndAnotherByItsName(): void
    {
        $kind = ['type' => 'object', 'required' => ['petType'], 'properties' => ['petType' => ['type' => 'string']]];
        $output = ModelFixture::build([
            'Pet' => [
                'oneOf' => [['$ref' => '#/components/schemas/Cat'], ['$ref' => '#/components/schemas/Dog']],
                'discriminator' => ['propertyName' => 'petType', 'mapping' => ['cat' => '#/components/schemas/Cat', 'kitty' => '#/components/schemas/Cat']],
            ],
            'Cat' => $kind,
            'Dog' => $kind,
        ]);

        self::assertSame([], ModelFixture::messages($output));
        self::assertSame(['App\Dto\Cat' => ["petType: 'cat'|'kitty'"], 'App\Dto\Dog' => ["petType: 'Dog'"]], ModelFixture::selections($output));
    }

    public function testChecksEveryDiscriminatorOfANestedHierarchy(): void
    {
        $breed = ['type' => 'object', 'required' => ['kind', 'breed'], 'properties' => ['kind' => ['type' => 'string'], 'breed' => ['type' => 'string']]];
        $output = ModelFixture::build([
            'Pet' => [
                'oneOf' => [['$ref' => '#/components/schemas/Cat'], ['$ref' => '#/components/schemas/Dog']],
                'discriminator' => ['propertyName' => 'kind', 'mapping' => ['cat' => '#/components/schemas/Cat', 'kitty' => '#/components/schemas/Cat']],
            ],
            'Cat' => [
                'oneOf' => [['$ref' => '#/components/schemas/Siamese'], ['$ref' => '#/components/schemas/Persian']],
                'discriminator' => ['propertyName' => 'breed'],
            ],
            'Siamese' => $breed,
            'Persian' => $breed,
            'Dog' => ['type' => 'object', 'required' => ['kind'], 'properties' => ['kind' => ['type' => 'string']]],
        ]);

        self::assertSame([], ModelFixture::messages($output));
        self::assertSame(
            [
                'App\Dto\Siamese' => ["kind: 'cat'|'kitty'", "breed: 'Siamese'"],
                'App\Dto\Persian' => ["kind: 'cat'|'kitty'", "breed: 'Persian'"],
                'App\Dto\Dog' => ["kind: 'Dog'"],
            ],
            ModelFixture::selections($output),
        );
    }

    public function testIgnoresTheValueOfAnOpenAncestor(): void
    {
        $output = ModelFixture::build([
            'Pet' => ['oneOf' => [['$ref' => '#/components/schemas/Cat'], ['$ref' => '#/components/schemas/Dog']], 'discriminator' => ['propertyName' => 'kind']],
            'Cat' => ['type' => 'object', 'properties' => ['kind' => ['type' => 'string']]],
            'Dog' => ['allOf' => [['$ref' => '#/components/schemas/Cat'], ['properties' => ['bark' => []]]]],
        ]);

        self::assertSame([], ModelFixture::messages($output));
        self::assertSame(['App\Dto\Dog' => ["kind: 'Dog'"]], ModelFixture::selections($output));
    }

    public function testLetsTheNearestDiscriminatorOfAPropertyDecide(): void
    {
        $k = ['type' => 'object', 'required' => ['k'], 'properties' => ['k' => ['type' => 'string']]];
        $output = ModelFixture::build([
            'B0' => ['oneOf' => [['$ref' => '#/components/schemas/B1'], ['$ref' => '#/components/schemas/X']], 'discriminator' => ['propertyName' => 'k']],
            'B1' => ['oneOf' => [['$ref' => '#/components/schemas/Y'], ['$ref' => '#/components/schemas/Z']], 'discriminator' => ['propertyName' => 'k']],
            'X' => $k,
            'Y' => $k,
            'Z' => $k,
        ]);

        self::assertSame(['App\Dto\X' => ["k: 'X'"], 'App\Dto\Y' => ["k: 'Y'"], 'App\Dto\Z' => ["k: 'Z'"]], ModelFixture::selections($output));
    }

    public function testTypesTheValuesAsThePropertyHoldsThem(): void
    {
        $output = ModelFixture::build([
            'Kind' => ['type' => 'string', 'enum' => ['cat', 'dog']],
            'Pet' => [
                'oneOf' => [['$ref' => '#/components/schemas/Cat'], ['$ref' => '#/components/schemas/Dog']],
                'discriminator' => ['propertyName' => 'kind', 'mapping' => ['cat' => '#/components/schemas/Cat', 'dog' => '#/components/schemas/Dog']],
            ],
            'Cat' => ['type' => 'object', 'required' => ['kind'], 'properties' => ['kind' => ['$ref' => '#/components/schemas/Kind']]],
            'Dog' => ['type' => 'object', 'required' => ['kind'], 'properties' => ['kind' => ['$ref' => '#/components/schemas/Kind']]],
            'Coin' => ['oneOf' => [['$ref' => '#/components/schemas/One']], 'discriminator' => ['propertyName' => 'value', 'mapping' => ['1' => '#/components/schemas/One']]],
            'One' => ['type' => 'object', 'required' => ['value'], 'properties' => ['value' => ['type' => 'integer']]],
        ]);

        self::assertSame([], ModelFixture::messages($output));
        self::assertSame(
            ['App\Dto\Cat' => ["kind: 'cat'"], 'App\Dto\Dog' => ["kind: 'dog'"], 'App\Dto\One' => ['value: 1']],
            ModelFixture::selections($output),
        );
    }

    public function testLeavesValuesTheTypeAlreadyGuaranteesUnchecked(): void
    {
        $output = ModelFixture::build([
            'Only' => ['type' => 'string', 'enum' => ['cat']],
            'Pet' => ['oneOf' => [['$ref' => '#/components/schemas/Cat']], 'discriminator' => ['propertyName' => 'kind', 'mapping' => ['cat' => '#/components/schemas/Cat']]],
            'Cat' => ['type' => 'object', 'required' => ['kind'], 'properties' => ['kind' => ['$ref' => '#/components/schemas/Only']]],
            'Card' => ['oneOf' => [['$ref' => '#/components/schemas/Visa']], 'discriminator' => ['propertyName' => 'brand', 'mapping' => ['visa' => '#/components/schemas/Visa']]],
            'Visa' => ['type' => 'object', 'required' => ['brand'], 'properties' => ['brand' => ['const' => 'visa']]],
            'Maybe' => ['oneOf' => [['$ref' => '#/components/schemas/Some']], 'discriminator' => ['propertyName' => 'kind', 'mapping' => ['cat' => '#/components/schemas/Some']]],
            'Some' => ['type' => 'object', 'properties' => ['kind' => ['$ref' => '#/components/schemas/Only']]],
        ]);

        self::assertSame([], ModelFixture::messages($output));
        // A nullable property still needs the check: null selects nothing.
        self::assertSame(
            ['App\Dto\Cat' => ["kind: 'cat' unchecked"], 'App\Dto\Visa' => ["brand: 'visa' unchecked"], 'App\Dto\Some' => ["kind: 'cat'"]],
            ModelFixture::selections($output),
        );
    }

    public function testWarnsAboutValuesThePropertyCannotHold(): void
    {
        $at = self::AT;
        $output = ModelFixture::build([
            'Kind' => ['type' => 'string', 'enum' => ['cat']],
            'Pet' => [
                'oneOf' => [['$ref' => '#/components/schemas/Cat']],
                'discriminator' => ['propertyName' => 'kind', 'mapping' => ['cat' => '#/components/schemas/Cat', 'puma' => '#/components/schemas/Cat']],
            ],
            'Cat' => ['type' => 'object', 'required' => ['kind'], 'properties' => ['kind' => ['$ref' => '#/components/schemas/Kind']]],
            'Coin' => ['oneOf' => [['$ref' => '#/components/schemas/One'], ['$ref' => '#/components/schemas/Two']], 'discriminator' => ['propertyName' => 'value', 'mapping' => ['1' => '#/components/schemas/One']]],
            'One' => ['type' => 'object', 'required' => ['value'], 'properties' => ['value' => ['type' => 'integer']]],
            'Two' => ['type' => 'object', 'required' => ['value'], 'properties' => ['value' => ['type' => 'integer']]],
        ]);

        self::assertSame(
            [
                "warning {$at}Cat: Discriminator value \"puma\" is not a value of property \"kind\", so it does not select App\\Dto\\Cat.",
                "warning {$at}Two: Discriminator value \"Two\" is not a value of property \"value\", so it does not select App\\Dto\\Two.",
            ],
            ModelFixture::messages($output),
        );
        self::assertSame(['App\Dto\Cat' => ["kind: 'cat' unchecked"], 'App\Dto\One' => ['value: 1']], ModelFixture::selections($output));
    }

    public function testDoesNotCheckADiscriminatorOfAnotherType(): void
    {
        $at = self::AT;
        $output = ModelFixture::build([
            'Flag' => ['oneOf' => [['$ref' => '#/components/schemas/On']], 'discriminator' => ['propertyName' => 'state']],
            'On' => ['type' => 'object', 'required' => ['state'], 'properties' => ['state' => ['type' => 'boolean']]],
        ]);

        self::assertSame(
            ["warning {$at}On: The constructor of App\\Dto\\On does not check discriminator \"state\": only a string, integer or enum property can be checked."],
            ModelFixture::messages($output),
        );
        self::assertSame([], ModelFixture::selections($output));
    }

    public function testChecksNothingForAVariantTheMappingLeavesWithoutValue(): void
    {
        $output = ModelFixture::build([
            'Pet' => [
                'oneOf' => [['$ref' => '#/components/schemas/Cat'], ['$ref' => '#/components/schemas/Dog']],
                'discriminator' => ['propertyName' => 'kind', 'mapping' => ['Cat' => 'Dog']],
            ],
            'Cat' => ['type' => 'object', 'properties' => ['kind' => ['type' => 'string']]],
            'Dog' => ['type' => 'object', 'properties' => ['kind' => ['type' => 'string']]],
        ]);

        self::assertCount(1, ModelFixture::messages($output));
        self::assertSame(['App\Dto\Dog' => ["kind: 'Cat'"]], ModelFixture::selections($output));
    }
}
```

`Some` — необязательное (`?Only`) свойство: проверка нужна (отклоняет `null`). Если `Hierarchy` не переносит `kind`
у варианта-одиночки в базу (`count($members) < 2`), выбор всё равно найдётся — свойство ищется по всей цепочке.

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter SelectingValuesTest`
Expected: FAIL — `selections()` возвращает `[]` (никто не заполняет `discriminatorValues`).

- [ ] **Step 3: Write minimal implementation**

`src/Domain/Builder/SelectingValues.php`:

```php
<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Domain\Builder;

use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassKind;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;
use MSSTC4PHP\DtoGenerator\Domain\Model\DiscriminatorModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\DiscriminatorValues;
use MSSTC4PHP\DtoGenerator\Domain\Model\EnumBacking;
use MSSTC4PHP\DtoGenerator\Domain\Model\EnumType;
use MSSTC4PHP\DtoGenerator\Domain\Model\MixedType;
use MSSTC4PHP\DtoGenerator\Domain\Model\NullableType;
use MSSTC4PHP\DtoGenerator\Domain\Model\PropertyModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\ScalarType;
use MSSTC4PHP\DtoGenerator\Domain\Model\TypeModel;

/**
 * The discriminator values that select each final class: for every discriminator of its ancestors, the mapping keys
 * of the class itself or of an abstract ancestor below the discriminated base.
 */
final class SelectingValues
{
    private function __construct()
    {
    }

    /**
     * @param array<string, ClassModel> $models by FQCN, linked: kinds, parents and discriminators are settled
     *
     * @return array<string, non-empty-list<DiscriminatorValues>> by FQCN
     */
    public static function of(array $models, Diagnostics $diagnostics): array
    {
        $selected = [];
        foreach ($models as $fqcn => $model) {
            if (!$model->kind()->equals(ClassKind::from(ClassKind::FINAL))) {
                continue;
            }

            $values = self::forClass($model, self::ancestors($model, $models), $diagnostics);
            if ($values !== []) {
                $selected[$fqcn] = $values;
            }
        }

        return $selected;
    }

    /**
     * @param array<string, ClassModel> $models
     *
     * @return list<ClassModel> nearest first
     */
    private static function ancestors(ClassModel $model, array $models): array
    {
        $ancestors = [];
        $seen = [$model->name()->fqcn() => true];
        for ($parent = $model->parent(); $parent instanceof ClassName && isset($models[$parent->fqcn()]) && !isset($seen[$parent->fqcn()]); $parent = $models[$parent->fqcn()]->parent()) {
            $seen[$parent->fqcn()] = true;
            $ancestors[] = $models[$parent->fqcn()];
        }

        return $ancestors;
    }

    /**
     * When two discriminators read one property, the nearest decides: an outer mapping can only name the inner base,
     * whose own discriminator then reads the same value again.
     *
     * @param list<ClassModel> $ancestors nearest first
     *
     * @return list<DiscriminatorValues> root discriminator first
     */
    private static function forClass(ClassModel $model, array $ancestors, Diagnostics $diagnostics): array
    {
        $byWireName = [];
        foreach (array_merge([$model], $ancestors) as $class) {
            foreach ($class->properties() as $property) {
                $byWireName[$property->wireName()] = $property;
            }
        }

        $found = [];
        foreach ($ancestors as $index => $base) {
            $discriminator = $base->discriminator();
            // A class without the property, or one the mapping leaves out, was reported while linking.
            $property = $discriminator instanceof DiscriminatorModel ? $byWireName[$discriminator->propertyName()] ?? null : null;
            if (!$discriminator instanceof DiscriminatorModel || !$property instanceof PropertyModel || isset($found[$property->name()])) {
                continue;
            }

            $keys = self::keysSelecting($model, array_slice($ancestors, 0, $index), $discriminator);
            $values = $keys === [] ? null : self::typed($keys, $property, $model, $diagnostics);
            if ($values instanceof DiscriminatorValues) {
                $found[$property->name()] = $values;
            }
        }

        return array_reverse(array_values($found));
    }

    /**
     * @param list<ClassModel> $between the ancestors below the discriminated base
     *
     * @return list<int|string>
     */
    private static function keysSelecting(ClassModel $model, array $between, DiscriminatorModel $discriminator): array
    {
        $targets = [$model->name()->fqcn() => true];
        foreach ($between as $ancestor) {
            // An open ancestor is a class of its own: its value selects it, not its subclasses.
            if ($ancestor->kind()->isAbstract()) {
                $targets[$ancestor->name()->fqcn()] = true;
            }
        }

        $keys = [];
        foreach ($discriminator->mapping() as $key => $target) {
            if (isset($targets[$target->fqcn()])) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * The keys as the property holds them, or null when the property cannot be checked or holds none of them.
     *
     * @param non-empty-list<int|string> $keys
     */
    private static function typed(array $keys, PropertyModel $property, ClassModel $model, Diagnostics $diagnostics): ?DiscriminatorValues
    {
        $declared = $property->type();
        $type = $declared instanceof NullableType ? $declared->inner() : $declared;
        $isInt = $type instanceof EnumType ? $type->backing()->value() === EnumBacking::INT : $type instanceof ScalarType && $type->kind() === 'int';
        if (!$type instanceof EnumType && !$type instanceof MixedType && !($type instanceof ScalarType && in_array($type->kind(), ['string', 'int'], true))) {
            $diagnostics->warning(
                sprintf(
                    'The constructor of %s does not check discriminator "%s": only a string, integer or enum property can be checked.',
                    $model->name()->fqcn(),
                    $property->wireName(),
                ),
                $model->source(),
            );

            return null;
        }

        $values = [];
        foreach ($keys as $key) {
            $value = $isInt ? (is_int($key) ? $key : null) : (string) $key;
            if ($value === null || !self::holds($type, $value)) {
                $diagnostics->warning(
                    sprintf('Discriminator value "%s" is not a value of property "%s", so it does not select %s.', $key, $property->wireName(), $model->name()->fqcn()),
                    $model->source(),
                );

                continue;
            }

            $values[] = $value;
        }

        if ($values === []) {
            return null;
        }

        // A check the type already makes would be dead code, which PHPStan reports in the generated class.
        return new DiscriminatorValues($property->name(), $values, $declared instanceof NullableType || !self::covers($type, $values));
    }

    /**
     * @param int|string $value
     */
    private static function holds(TypeModel $type, $value): bool
    {
        if ($type instanceof EnumType) {
            return $type->caseFor($value) !== null;
        }

        $refinement = $type instanceof ScalarType ? $type->phpDoc() : null;

        return $refinement === null || LiteralType::admits($refinement, $value) !== false;
    }

    /**
     * Whether the values are all the type admits.
     *
     * @param non-empty-list<int|string> $values
     */
    private static function covers(TypeModel $type, array $values): bool
    {
        $held = array_map('strval', $values);
        if ($type instanceof EnumType) {
            return array_diff(array_map('strval', array_keys($type->cases())), $held) === [];
        }

        $refinement = $type instanceof ScalarType ? $type->phpDoc() : null;
        if ($refinement === null || LiteralType::admits($refinement, $values[0]) === null) {
            return false;
        }

        $literals = array_map(static fn ($value): ?string => LiteralType::of($value), $values);

        return array_diff(explode('|', $refinement), $literals) === [];
    }
}
```

`Hierarchy::link()` — после `$hierarchy->checkDiscriminators($unions);`:

```php
        $hierarchy->selectVariants();
```

```php
    /**
     * Only now are kinds and parents final, so an abstract ancestor can be told from an open one.
     */
    private function selectVariants(): void
    {
        foreach (SelectingValues::of($this->models, $this->diagnostics) as $fqcn => $values) {
            $this->models[$fqcn] = $this->models[$fqcn]->withDiscriminatorValues(...$values);
        }
    }
```

Обновить PHPDoc класса `Hierarchy`: «…and records the discriminator values that select each final class».

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit`
Expected: PASS (существующие `CompositionTest`/`EnrichTest` не меняются: для них предупреждений не добавляется —
дискриминаторы без типа (`'kind' => []`) дают `MixedType` и проверяются строками без предупреждения).

- [ ] **Step 5: Commit**

```bash
make fix && vendor/bin/phpunit
git add src/Domain/Builder tests/Support/ModelFixture.php tests/Unit/Application/Service/Model/SelectingValuesTest.php
git commit -m "feat(builder): find the discriminator values that select each final class"
```

### Task 3: emitter — проверка, default, порядок параметров, без мутаторов дискриминатора

**Files:**
- Modify: `src/Infrastructure/Emitter/ClassShape.php` (`className()`, `selection()`, `defaultOf()`, `hasMutators()`, `checks()`)
- Modify: `src/Infrastructure/Emitter/PhpParserEmitter.php` (`assertSupported()`, `constructor()`, `parameters()`,
  `check()`, `printable()`, `declare()`, `accessors()`, `witherBody()`)
- Modify: `src/Infrastructure/Emitter/TypeRenderer.php` (`enums()` → публичный `nativeEnums()`)
- Modify: `tests/Support/EmitterFixture.php` (`circle()` с значениями, новые `square()`, `wallet()`, `euroWallet()`; `shape()` с новым mapping)
- Test: `tests/Unit/Infrastructure/Emitter/PhpParserEmitterTest.php`

**Interfaces:**
- Consumes: `ClassModel::discriminatorValues()`, `discriminatorValuesOf()`, `DiscriminatorValues` (Task 1).
- Produces: `ClassShape::defaultOf(PropertyModel): ?DefaultValue`, `hasMutators(PropertyModel): bool`,
  `checks(): list<array{PropertyModel, DiscriminatorValues}>`; `TypeRenderer::nativeEnums(): bool`.

- [ ] **Step 1: Write the failing test**

`EmitterFixture` (импорт `DiscriminatorValues`):

```php
    /**
     * A discriminated base.
     */
    public static function shape(string $mutability = Mutability::IMMUTABLE): ClassModel
    {
        return self::model('App\Dto\Shape', null, [self::property('kind', ScalarType::string(), true)], $mutability)->withHierarchy(
            ClassKind::from(ClassKind::ABSTRACT),
            null,
            new DiscriminatorModel('kind', [
                'circle' => ClassName::fromFqcn('App\Dto\Circle'),
                'round' => ClassName::fromFqcn('App\Dto\Circle'),
                'square' => ClassName::fromFqcn('App\Dto\Square'),
            ]),
        );
    }

    /**
     * Selected by two values, so the discriminator stays a required parameter.
     */
    public static function circle(string $mutability = Mutability::IMMUTABLE): ClassModel
    {
        return self::model('App\Dto\Circle', null, [self::property('radius', ScalarType::float(), true)], $mutability)
            ->withHierarchy(ClassKind::from(ClassKind::FINAL), ClassName::fromFqcn('App\Dto\Shape'), null)
            ->withDiscriminatorValues(new DiscriminatorValues('kind', ['circle', 'round']))
        ;
    }

    /**
     * Selected by one value, its default, so the discriminator moves behind the required parameters.
     */
    public static function square(string $mutability = Mutability::IMMUTABLE): ClassModel
    {
        return self::model('App\Dto\Square', null, [self::property('side', ScalarType::float(), true)], $mutability)
            ->withHierarchy(ClassKind::from(ClassKind::FINAL), ClassName::fromFqcn('App\Dto\Shape'), null)
            ->withDiscriminatorValues(new DiscriminatorValues('kind', ['square']))
        ;
    }

    /**
     * A base discriminated by an enum.
     */
    public static function wallet(string $mutability = Mutability::IMMUTABLE): ClassModel
    {
        return self::model('App\Dto\Wallet', null, [self::property('currency', EnumType::of(self::currency()), true)], $mutability)->withHierarchy(
            ClassKind::from(ClassKind::ABSTRACT),
            null,
            new DiscriminatorModel('currency', ['EUR' => ClassName::fromFqcn('App\Dto\EuroWallet')]),
        );
    }

    public static function euroWallet(string $mutability = Mutability::IMMUTABLE): ClassModel
    {
        return self::model('App\Dto\EuroWallet', null, [self::property('balance', ScalarType::int(), true)], $mutability)
            ->withHierarchy(ClassKind::from(ClassKind::FINAL), ClassName::fromFqcn('App\Dto\Wallet'), null)
            ->withDiscriminatorValues(new DiscriminatorValues('currency', ['EUR']))
        ;
    }
```

В `classes()` после `self::circle($mutability)` добавить `self::square($mutability)`, `self::wallet($mutability)`,
`self::euroWallet($mutability)`.

В `PhpParserEmitterTest` (импорты `DiscriminatorModel`, `DiscriminatorValues`, `DocModel` уже есть,
`PropertyModel`):

```php
    public function testChecksTheDiscriminatorBeforeCallingTheParent(): void
    {
        $code = (new PhpParserEmitter())->emit(EmitterFixture::circle(), EmitterFixture::target('8.2', Mutability::IMMUTABLE), EmitterFixture::shape()->properties());

        self::assertStringContainsString(
            "    public function __construct(string \$kind, public float \$radius)\n    {\n"
            . "        if (\$kind !== 'circle' && \$kind !== 'round') {\n"
            . "            throw new \\InvalidArgumentException(sprintf('\"%s\" does not select Circle by \"kind\".', \$kind));\n"
            . "        }\n\n"
            . "        parent::__construct(\$kind);\n    }",
            $code,
        );
        self::assertStringContainsString('return new self($this->kind, $radius);', $code);
        self::assertStringNotContainsString('withKind', $code);
    }

    public function testMovesADefaultedDiscriminatorBehindTheRequiredParameters(): void
    {
        $code = (new PhpParserEmitter())->emit(EmitterFixture::square(), EmitterFixture::target('8.1', Mutability::IMMUTABLE), EmitterFixture::shape()->properties());

        self::assertStringContainsString("    public function __construct(public readonly float \$side, string \$kind = 'square')\n", $code);
        self::assertStringContainsString("        if (\$kind !== 'square') {\n", $code);
        self::assertStringContainsString("        parent::__construct(\$kind);\n", $code);
        self::assertStringContainsString("    public function withSide(float \$side): self\n    {\n        return new self(\$side, \$this->kind);\n    }", $code);
        self::assertStringNotContainsString('withKind', $code);
    }

    public function testComparesAnEnumDiscriminatorWithItsCases(): void
    {
        $inherited = EmitterFixture::wallet()->properties();
        $modern = (new PhpParserEmitter())->emit(EmitterFixture::euroWallet(), EmitterFixture::target('8.1', Mutability::IMMUTABLE), $inherited);
        $legacy = (new PhpParserEmitter())->emit(EmitterFixture::euroWallet(), EmitterFixture::target('7.4', Mutability::IMMUTABLE), $inherited);

        self::assertStringContainsString('public function __construct(public readonly int $balance, Currency $currency = Currency::EUR)', $modern);
        self::assertStringContainsString('if ($currency !== Currency::EUR) {', $modern);
        self::assertStringContainsString("does not select EuroWallet by \"currency\".', \$currency->value)", $modern);
        self::assertStringContainsString('public function __construct(int $balance, string $currency = Currency::EUR)', $legacy);
        self::assertStringContainsString('if ($currency !== Currency::EUR) {', $legacy);
        self::assertStringContainsString("does not select EuroWallet by \"currency\".', \$currency)", $legacy);
    }

    public function testReadsTheValueOfANullableEnumSafely(): void
    {
        $currency = EnumType::of(EmitterFixture::currency());
        $purse = EmitterFixture::model('App\Dto\Purse', null, [EmitterFixture::property('currency', new NullableType($currency), false, new DefaultValue(null))])
            ->withHierarchy(ClassKind::from(ClassKind::ABSTRACT), null, new DiscriminatorModel('currency', ['EUR' => ClassName::fromFqcn('App\Dto\Coin')]))
        ;
        $coin = EmitterFixture::model('App\Dto\Coin', null, [])
            ->withHierarchy(ClassKind::from(ClassKind::FINAL), $purse->name(), null)
            ->withDiscriminatorValues(new DiscriminatorValues('currency', ['EUR', 'in-progress']))
        ;

        $code = (new PhpParserEmitter())->emit($coin, EmitterFixture::target('8.1', Mutability::IMMUTABLE), $purse->properties());

        self::assertStringContainsString('public function __construct(?Currency $currency)', $code);
        self::assertStringContainsString('if ($currency !== Currency::EUR && $currency !== Currency::IN_PROGRESS) {', $code);
        self::assertStringContainsString('$currency?->value', $code);
    }

    public function testEscapesTheWireNameInTheMessageFormat(): void
    {
        $wire = "pet%type's";
        $petType = new PropertyModel('petType', $wire, ScalarType::string(), true, null, DocModel::none(), new SchemaLocation('/project/api/openapi.yaml', '/components/schemas/Pet'));
        $pet = EmitterFixture::model('App\Dto\Pet', null, [$petType])
            ->withHierarchy(ClassKind::from(ClassKind::ABSTRACT), null, new DiscriminatorModel($wire, ['cat' => ClassName::fromFqcn('App\Dto\Cat')]))
        ;
        $cat = EmitterFixture::model('App\Dto\Cat', null, [])
            ->withHierarchy(ClassKind::from(ClassKind::FINAL), $pet->name(), null)
            ->withDiscriminatorValues(new DiscriminatorValues('petType', ['cat']))
        ;

        $code = (new PhpParserEmitter())->emit($cat, EmitterFixture::target('8.2', Mutability::IMMUTABLE), $pet->properties());

        self::assertStringContainsString("sprintf('\"%s\" does not select Cat by \"pet%%type\\'s\".', \$petType)", $code);
    }

    public function testLeavesOutACheckTheTypeAlreadyMakes(): void
    {
        $square = EmitterFixture::model('App\Dto\Square', null, [])
            ->withHierarchy(ClassKind::from(ClassKind::FINAL), ClassName::fromFqcn('App\Dto\Shape'), null)
            ->withDiscriminatorValues(new DiscriminatorValues('kind', ['square'], false))
        ;

        $code = (new PhpParserEmitter())->emit($square, EmitterFixture::target('8.2', Mutability::IMMUTABLE), EmitterFixture::shape()->properties());

        self::assertStringContainsString("    public function __construct(string \$kind = 'square')\n    {\n        parent::__construct(\$kind);\n    }", $code);
        self::assertStringNotContainsString('InvalidArgumentException', $code);
    }

    public function testKeepsAPropertyDefaultOnlyWhenItSelectsTheClass(): void
    {
        $choice = EmitterFixture::model('App\Dto\Choice', null, [
            EmitterFixture::property('mode', new NullableType(ScalarType::string()), false, new DefaultValue('a')),
            EmitterFixture::property('size', new NullableType(ScalarType::int()), false, new DefaultValue(null)),
        ])->withHierarchy(ClassKind::from(ClassKind::ABSTRACT), null, new DiscriminatorModel('mode', ['a' => ClassName::fromFqcn('App\Dto\Pick')]));
        $pick = static fn (array $values): ClassModel => EmitterFixture::model('App\Dto\Pick', null, [EmitterFixture::property('label', ScalarType::string(), true)])
            ->withHierarchy(ClassKind::from(ClassKind::FINAL), $choice->name(), null)
            ->withDiscriminatorValues(new DiscriminatorValues('mode', $values))
        ;
        $target = EmitterFixture::target('8.2', Mutability::IMMUTABLE);

        $kept = (new PhpParserEmitter())->emit($pick(['a', 'b']), $target, $choice->properties());
        $dropped = (new PhpParserEmitter())->emit($pick(['b', 'c']), $target, $choice->properties());

        self::assertStringContainsString("public function __construct(public string \$label, ?string \$mode = 'a', ?int \$size = null)", $kept);
        self::assertStringContainsString('public function __construct(public string $label, ?string $mode, ?int $size = null)', $dropped);
        self::assertStringContainsString('parent::__construct($mode, $size);', $dropped);
    }

    public function testGivesTheDiscriminatorNoSetterOrWither(): void
    {
        $emitter = new PhpParserEmitter();
        foreach (['7.4', '8.0', '8.5'] as $php) {
            self::assertStringNotContainsString('withKind', $emitter->emit(EmitterFixture::shape(), EmitterFixture::target($php, Mutability::IMMUTABLE)), $php);
        }

        $mutable = $emitter->emit(EmitterFixture::shape(Mutability::MUTABLE), EmitterFixture::target('8.2', Mutability::MUTABLE, AccessorStyle::GETTERS));
        self::assertStringNotContainsString('setKind', $mutable);
        self::assertStringContainsString('public function getKind(): string', $mutable);

        // The only variant of a base keeps the discriminator as its own property.
        $solo = EmitterFixture::model('App\Dto\Solo', null, [EmitterFixture::property('kind', ScalarType::string(), true)])
            ->withDiscriminatorValues(new DiscriminatorValues('kind', ['solo']))
        ;
        $code = $emitter->emit($solo, EmitterFixture::target('8.5', Mutability::IMMUTABLE));
        self::assertStringContainsString("public function __construct(public string \$kind = 'solo')", $code);
        self::assertStringNotContainsString('withKind', $code);
    }

    public function testRejectsDiscriminatorValuesOfAnUndeclaredProperty(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('App\Dto\Tag checks the discriminator $kind, which it neither declares nor inherits.');

        (new PhpParserEmitter())->emit(EmitterFixture::tag()->withDiscriminatorValues(new DiscriminatorValues('kind', ['tag'])), EmitterFixture::target('8.2', Mutability::IMMUTABLE));
    }
```

Существующий `testProtectsTheConstructorOfAnAbstractBase` не меняется (`Shape` остаётся с
`protected function __construct(public string $kind)`).

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter PhpParserEmitterTest`
Expected: FAIL — нет `if ($kind !== …)`, `withKind` присутствует, `Square` без default.

- [ ] **Step 3: Write minimal implementation**

`ClassShape` (импорты `ClassName`, `DiscriminatorModel`, `DiscriminatorValues`, `DefaultValue`):

```php
    public function className(): ClassName
    {
        return $this->class->name();
    }

    public function selection(PropertyModel $property): ?DiscriminatorValues
    {
        return $this->class->discriminatorValuesOf($property->name());
    }

    /**
     * A discriminator selected by one value defaults to it; selected by several, it keeps its own default only when
     * that is one of them.
     */
    public function defaultOf(PropertyModel $property): ?DefaultValue
    {
        $selection = $this->selection($property);
        $default = $property->default();
        if (!$selection instanceof DiscriminatorValues) {
            return $default;
        }

        $values = $selection->values();
        if (count($values) === 1) {
            return new DefaultValue($values[0]);
        }

        return $default instanceof DefaultValue && in_array($default->value(), $values, true) ? $default : null;
    }

    /**
     * A copy with another discriminator would skip the constructor's check, or select another class when read back.
     */
    public function hasMutators(PropertyModel $property): bool
    {
        $discriminator = $this->class->discriminator();

        return !$this->selection($property) instanceof DiscriminatorValues
            && !($discriminator instanceof DiscriminatorModel && $discriminator->propertyName() === $property->wireName());
    }

    /**
     * @return list<array{PropertyModel, DiscriminatorValues}> root discriminator first
     */
    public function checks(): array
    {
        $checks = [];
        foreach ($this->class->discriminatorValues() as $values) {
            foreach ($this->all() as $property) {
                if ($values->isChecked() && $property->name() === $values->property()) {
                    $checks[] = [$property, $values];
                }
            }
        }

        return $checks;
    }
```

`TypeRenderer`: переименовать `private function enums()` в `public function nativeEnums()` (три вызова внутри).

`PhpParserEmitter` (импорты `DiscriminatorValues`, `DefaultValue` уже есть, `PhpParser\Node\Expr\BinaryOp\BooleanAnd`,
`PhpParser\Node\Expr\BinaryOp\NotIdentical`, `PhpParser\Node\Expr\NullsafePropertyFetch`, `PhpParser\Node\Expr\Throw_`,
`PhpParser\Node\Name\FullyQualified`, `PhpParser\Node\Stmt\If_`):

`assertSupported()` — в конец:

```php
        $names = array_map(static fn (PropertyModel $property): string => $property->name(), array_merge($inherited, $class->properties()));
        foreach ($class->discriminatorValues() as $values) {
            if (!in_array($values->property(), $names, true)) {
                throw new LogicException(sprintf('%s checks the discriminator $%s, which it neither declares nor inherits.', $fqcn, $values->property()));
            }
        }
```

`constructor()` — проверки первыми, затем вызов родителя; параметры в новом порядке:

```php
        $form = $shape->form();
        $params = [];
        $tags = [];
        $body = [];
        foreach ($shape->checks() as [$property, $values]) {
            $body[] = $this->check($shape, $property, $values, $types);
        }

        if ($shape->inherited() !== []) {
            $args = array_map(static fn (PropertyModel $property): Arg => new Arg(new Variable($property->name())), $this->constructorOrder($shape->inherited()));
            $body[] = new Expression(new StaticCall(new Name('parent'), '__construct', $args));
        }

        foreach ($this->parameters($shape) as $property) {
            $param = $this->param($property, $types, $shape->defaultOf($property));
            // …the rest of the loop is unchanged
```

Новые методы:

```php
    /**
     * Required parameters first (spec §5.2): an optional one before a required one is deprecated since PHP 8.0. A
     * discriminator defaulting to the one value that selects the class counts as optional, so it moves behind them;
     * the parent call keeps the parent's order, as it passes variables.
     *
     * @return list<PropertyModel>
     */
    private function parameters(ClassShape $shape): array
    {
        $required = [];
        $optional = [];
        foreach ($this->constructorOrder($shape->all()) as $property) {
            if ($shape->defaultOf($property) instanceof DefaultValue) {
                $optional[] = $property;
            } else {
                $required[] = $property;
            }
        }

        return array_merge($required, $optional);
    }

    /**
     * `new Cat('dog')` must fail: read back, the object would claim to be a Dog.
     */
    private function check(ClassShape $shape, PropertyModel $property, DiscriminatorValues $selection, TypeRenderer $types): If_
    {
        $parameter = new Variable($property->name());
        $values = $selection->values();
        $condition = new NotIdentical($parameter, $this->defaultValue($values[0], $property->type(), $types));
        foreach (array_slice($values, 1) as $value) {
            $condition = new BooleanAnd($condition, new NotIdentical($parameter, $this->defaultValue($value, $property->type(), $types)));
        }

        // The wire name is free text; sprintf() must not read a "%" in it as a conversion.
        $format = sprintf('"%%s" does not select %s by "%s".', $shape->className()->shortName(), str_replace('%', '%%', $property->wireName()));
        $message = new FuncCall(new Name('sprintf'), [new Arg(new String_($format)), new Arg($this->printable($parameter, $property->type(), $types))]);
        $throw = new Expression(new Throw_(new New_(new FullyQualified('InvalidArgumentException'), [new Arg($message)])));

        return new If_($condition, ['stmts' => [$throw]]);
    }

    /**
     * A native enum case is not a string; its backing value is.
     */
    private function printable(Variable $parameter, TypeModel $type, TypeRenderer $types): Expr
    {
        $inner = $type instanceof NullableType ? $type->inner() : $type;
        if (!$inner instanceof EnumType || !$types->nativeEnums()) {
            return $parameter;
        }

        return $type instanceof NullableType ? new NullsafePropertyFetch($parameter, 'value') : new PropertyFetch($parameter, 'value');
    }
```

`defaultValue()` уже превращает значение enum в `Name::CASE` (на 7.4/8.0 — константа того же вида) и снимает
`NullableType`, поэтому одна ветка служит и для сравнения.

Мутаторы:
- `declare()`: `if ($shape->declaresInheritedWithers() && $shape->hasMutators($property)) {`;
- `accessors()`: `if ($form->hasSetters() && $shape->hasMutators($property)) {` и
  `if ($shape->hasWithers() && $shape->hasMutators($property)) {`;
- `witherBody()`, ветка `NEW_SELF`: `foreach ($this->parameters($shape) as $other) {` вместо
  `$this->constructorOrder($shape->all())`.

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter 'PhpParserEmitterTest|TypeRendererTest'`
Expected: PASS.

Run: `vendor/bin/phpunit`
Expected: FAIL только в `GoldenEmitterTest` (Shape/Circle изменились, у Square/Wallet/EuroWallet нет файлов) и
`GoldenProjectTest` (Pet/Cat/Dog, Vehicle/Car). Перезаписать снапшоты:

Run: `UPDATE_SNAPSHOTS=1 vendor/bin/phpunit --filter 'GoldenEmitterTest|GoldenProjectTest'`, затем
`git diff --stat tests/Fixtures` — изменены только `Shape`, `Circle` (+ новые `Square`, `Wallet`, `EuroWallet`) в
12 профилях и `Pet`, `Cat`, `Dog`, `Vehicle`, `Car` в 5 целях golden-проекта. Любой другой изменившийся файл —
регрессия, исправить до коммита. Подробный просмотр содержимого — Task 4 и Task 6.

Run: `vendor/bin/phpunit`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
make fix && vendor/bin/phpunit
git add src/Infrastructure/Emitter tests/Support/EmitterFixture.php tests/Unit/Infrastructure/Emitter tests/Fixtures
git commit -m "feat(emitter): a variant's constructor accepts only the discriminator values that select it"
```

### Task 4: runtime-smoke и просмотр emitter-фикстур

**Files:**
- Review: `tests/Fixtures/Emitter/*/{Shape,Circle,Square,Wallet,EuroWallet}.php.golden` (12 профилей, записаны в Task 3)
- Modify: `tests/Targets/smoke.php`

**Interfaces:**
- Consumes: golden-файлы `Square`, `Wallet`, `EuroWallet` (Task 3).

- [ ] **Step 1: Write the failing test**

`tests/Targets/smoke.php`: добавить `use App\Dto\EuroWallet; use App\Dto\Square; use App\Dto\Wallet;`, после
`require $profile . 'Circle.php.golden';`:

```php
require $profile . 'Square.php.golden';
require $profile . 'Wallet.php.golden';
require $profile . 'EuroWallet.php.golden';
```

После `function check(…)`:

```php
function rejects(callable $create, string $message): void
{
    try {
        $create();
    } catch (InvalidArgumentException $exception) {
        check($exception->getMessage() === $message, 'message: ' . $exception->getMessage());

        return;
    }

    check(false, 'rejected: ' . $message);
}
```

После строки `check($circle instanceof Shape …, 'discriminated variant');`:

```php
check(read(new Circle('round', 1.0), 'kind') === 'round', 'second discriminator value');
rejects(static fn (): Circle => new Circle('square', 1.0), '"square" does not select Circle by "kind".');

$square = new Square(3.0);
check($square instanceof Shape && read($square, 'kind') === 'square' && read($square, 'side') === 3.0, 'discriminator default');
rejects(static fn (): Square => new Square(3.0, 'circle'), '"circle" does not select Square by "kind".');
foreach ([$circle, $square] as $variant) {
    check(!method_exists($variant, 'withKind') && !method_exists($variant, 'setKind'), 'no discriminator mutators');
}

if (method_exists($square, 'withSide')) {
    check(read($square->withSide(4.0), 'kind') === 'square', 'a wither keeps the discriminator');
}

$euro = constant('App\\Dto\\Currency::EUR');
$wallet = new EuroWallet(10);
check($wallet instanceof Wallet && read($wallet, 'currency') === $euro && read($wallet, 'balance') === 10, 'enum discriminator default');
rejects(
    static fn (): EuroWallet => new EuroWallet(10, constant('App\\Dto\\Currency::IN_PROGRESS')),
    '"in-progress" does not select EuroWallet by "currency".',
);
```

(`rejects(…, …,)` с висячей запятой в вызове допустим с PHP 7.3.)

- [ ] **Step 2: Run test to verify it fails**

Smoke выполняется только в `make test-targets`. Чтобы увидеть, что он ловит регрессию, временно вернуть в
`tests/Fixtures/Emitter/8.2-immutable/Circle.php.golden` строку `public function __construct(string $kind, public float $radius)`
без блока `if` и запустить на 8.2 (вне песочницы):

Run: `docker run --rm -v "$PWD:/app" -w /app php:8.2-cli php tests/Targets/smoke.php tests/Fixtures/Emitter/8.2-immutable/`
Expected: `failed: rejected: "square" does not select Circle by "kind".`, код 1. Затем восстановить файл
(`git checkout tests/Fixtures/Emitter/8.2-immutable/Circle.php.golden`).

- [ ] **Step 3: Просмотреть golden-файлы**

Проверить глазами:
- `8.1-immutable/Circle.php.golden`: проверка `'circle'`/`'round'` до `parent::__construct($kind)`, нет `withKind`,
  `withRadius` → `new self($this->kind, $radius)`;
- `8.1-immutable/Square.php.golden`: `__construct(public readonly float $side, string $kind = 'square')`,
  `withSide` → `new self($side, $this->kind)`;
- `7.4-immutable/Shape.php.golden`, `8.0-immutable/Shape.php.golden`, `8.5-immutable/Shape.php.golden`: нет `withKind`;
  `7.4-mutable-getters/Shape.php.golden`: есть `getKind`, нет `setKind`;
- `8.1-immutable/EuroWallet.php.golden`: `Currency $currency = Currency::EUR`, `$currency->value` в сообщении;
  `7.4-immutable/EuroWallet.php.golden`: `string $currency = Currency::EUR`, `@phpstan-param Currency::* $currency`.

- [ ] **Step 4: Проверить вывод на целевых версиях**

Run: `vendor/bin/phpunit` — PASS. Затем `make test-targets` (вне песочницы — нужен Docker).
Expected: `php -l`, smoke (исключения с точными сообщениями, default, нет `withKind`/`setKind`) и PHPStan max без
ошибок на 7.4…8.5 — в частности без `notIdentical.alwaysFalse`.

- [ ] **Step 5: Commit**

```bash
make fix && vendor/bin/phpunit
git add tests/Targets/smoke.php
git commit -m "test: runtime smoke covers discriminator checks, defaults and missing mutators"
```

### Task 5: `dto.withers`

**Files:**
- Modify: `src/Domain/Target/TargetProfile.php`, `src/Domain/Target/ClassForm.php`
- Modify: `src/Application/Config/DtoSettings.php`, `src/Application/Config/ConfigFactory.php`, `src/Application/Config/TargetResolver.php`
- Modify: `tests/Support/EmitterFixture.php` (`target(…, bool $withers = true)`)
- Test: `tests/Unit/Domain/Target/ClassFormTest.php`, `tests/Unit/Application/Config/ConfigFactoryTest.php`,
  `tests/Unit/Application/Config/TargetResolverTest.php`, `tests/Unit/Infrastructure/Emitter/PhpParserEmitterTest.php`

**Interfaces:**
- Produces: `TargetProfile::__construct(…, bool $strict, bool $withers = true)`, `TargetProfile::hasWithers(): bool`;
  `DtoSettings::__construct(…, AllOfStrategy $allOfStrategy, bool $withers = true)`, `DtoSettings::withers(): bool`;
  ключ `dto.withers` (bool, по умолчанию `true`).

- [ ] **Step 1: Write the failing test**

`ClassFormTest`: из `impossibleForms()` удалить строку `'immutable without withers'`; в
`testFollowsTheShapeTableOfTheSpec` добавить `self::assertTrue($target->hasWithers());`; добавить:

```php
    /**
     * @dataProvider withoutWithers
     */
    public function testLeavesWithersOutWhenTheConfigSaysSo(string $php, string $expected): void
    {
        $target = new TargetProfile(
            PhpVersion::fromString($php),
            MetadataMode::from(MetadataMode::NONE),
            Mutability::from(Mutability::IMMUTABLE),
            AccessorStyle::from(AccessorStyle::AUTO),
            DateTimeClass::from(DateTimeClass::IMMUTABLE),
            true,
            false,
        );

        self::assertFalse($target->hasWithers());
        self::assertSame($expected, $this->summary($target->classFormFor(Mutability::from(Mutability::IMMUTABLE))));
        // The mutable form does not depend on the key.
        $mutable = $php === '7.4' ? 'declared private getters setters withers:none' : 'promoted private getters setters withers:none';
        self::assertSame($mutable, $this->summary($target->classFormFor(Mutability::from(Mutability::MUTABLE))));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function withoutWithers(): array
    {
        return [
            '7.4' => ['7.4', 'declared private getters withers:none'],
            '8.2' => ['8.2', 'promoted public readonly-class withers:none'],
            '8.5' => ['8.5', 'promoted public readonly-class withers:none'],
        ];
    }
```

Добавить тест на ослабленную фабрику:

```php
    public function testAcceptsAnImmutableFormWithoutWithers(): void
    {
        $form = ClassForm::immutable(true, true, ReadonlyMode::from(ReadonlyMode::CLASS_), WitherStyle::from(WitherStyle::NONE));

        self::assertTrue($form->withers()->isNone());
    }
```

`ConfigFactoryTest`:
- `testAppliesDefaultsToAMinimalConfig`: `self::assertTrue($config->dto()->withers());`;
- `testReadsAFullConfig`: в `'dto'` добавить `'withers' => false`, проверить `self::assertFalse($config->dto()->withers());`;
- `invalidConfigs()`: `'withers not bool' => [['version' => 1, 'dto' => ['withers' => 'no'], 'sources' => [$source]], '"withers" must be true or false', '/dto/withers'],`.

`TargetResolverTest`: приватный `config()` получает последний параметр `bool $withers = true` и передаёт его пятым
аргументом `DtoSettings`; новый тест:

```php
    public function testCarriesTheWitherSettingIntoTheTarget(): void
    {
        $without = $this->resolve($this->config('8.2', null, 'App\Dto', [], false), null);
        $with = $this->resolve($this->config('8.2'), null);

        self::assertNotNull($without);
        self::assertNotNull($with);
        self::assertFalse($without->hasWithers());
        self::assertTrue($with->hasWithers());
    }
```

`EmitterFixture::target()`:

```php
    public static function target(string $php, string $mutability, string $accessors = AccessorStyle::AUTO, ?string $metadata = null, bool $withers = true): TargetProfile
    {
        $version = PhpVersion::fromString($php);

        return new TargetProfile(
            $version,
            MetadataMode::from($metadata ?? MetadataMode::defaultFor($version)->value()),
            Mutability::from($mutability),
            AccessorStyle::from($accessors),
            DateTimeClass::from(DateTimeClass::IMMUTABLE),
            true,
            $withers,
        );
    }
```

`PhpParserEmitterTest`:

```php
    public function testLeavesWithersOutWhenTheTargetHasNone(): void
    {
        $emitter = new PhpParserEmitter();

        self::assertStringNotContainsString('function with', $emitter->emit(EmitterFixture::sample(), EmitterFixture::target('8.2', Mutability::IMMUTABLE, AccessorStyle::AUTO, null, false)));
        self::assertStringNotContainsString('function with', $emitter->emit(EmitterFixture::animal(), EmitterFixture::target('7.4', Mutability::IMMUTABLE, AccessorStyle::AUTO, null, false)));
        self::assertStringNotContainsString(
            'function with',
            $emitter->emit(EmitterFixture::dog(), EmitterFixture::target('8.1', Mutability::IMMUTABLE, AccessorStyle::AUTO, null, false), EmitterFixture::animal()->properties()),
        );
        self::assertStringContainsString(
            'public function setId(int $id): self',
            $emitter->emit(EmitterFixture::sample(Mutability::MUTABLE), EmitterFixture::target('8.2', Mutability::MUTABLE, AccessorStyle::GETTERS, null, false)),
        );
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter 'ClassFormTest|ConfigFactoryTest|TargetResolverTest|testLeavesWithersOutWhenTheTargetHasNone'`
Expected: FAIL — `Call to undefined method …TargetProfile::hasWithers()`, `Unknown key "withers"`.

- [ ] **Step 3: Write minimal implementation**

`TargetProfile`: поле `private bool $withers;`, последний параметр конструктора `bool $withers = true`
(присваивание рядом с `$this->strict`), метод:

```php
    /**
     * Whether immutable DTOs get `with*()` methods (`dto.withers`); mutable ones keep their setters either way.
     */
    public function hasWithers(): bool
    {
        return $this->withers;
    }
```

`classFormFor()`:

```php
        $readonly = $this->supports(Capability::from(Capability::READONLY_PROPERTIES));
        if (!$this->withers) {
            $withers = WitherStyle::NONE;
        } elseif ($this->supports(Capability::from(Capability::CLONE_WITH))) {
            $withers = WitherStyle::CLONE_WITH;
        } elseif ($readonly) {
```

`ClassForm::immutable()`: удалить блок `if ($withers->isNone()) { throw … }`; PHPDoc — «Private properties get
getters; every property gets a wither unless the config turns them off.»

`DtoSettings`: поле `private bool $withers;`, параметр `bool $withers = true` последним, метод
`public function withers(): bool`.

`ConfigFactory::dto()`:

```php
        $section->rejectUnknownKeys(['mutability', 'accessors', 'dateTimeClass', 'allOfStrategy', 'withers']);

        return new DtoSettings(
            Mutability::from($section->choice('mutability', Mutability::IMMUTABLE, $this->values(Mutability::cases()))),
            AccessorStyle::from($section->choice('accessors', AccessorStyle::AUTO, $this->values(AccessorStyle::cases()))),
            DateTimeClass::from($section->choice('dateTimeClass', DateTimeClass::IMMUTABLE, $this->values(DateTimeClass::cases()))),
            AllOfStrategy::from($section->choice('allOfStrategy', AllOfStrategy::EXTENDS, $this->values(AllOfStrategy::cases()))),
            $section->bool('withers', true),
        );
```

`TargetResolver::resolve()`: седьмым аргументом `new TargetProfile(…)` передать `$config->dto()->withers()`.

`ClassShape::hasWithers()`/`declaresInheritedWithers()` уже возвращают `false` для `WitherStyle::NONE` — менять не
нужно.

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
make fix && vendor/bin/phpunit
git commit -am "feat(config): dto.withers: false leaves with*() out of immutable DTOs"
```

### Task 6: golden-проект — enum-дискриминатор и новые выводы Pet/Cat/Dog, Vehicle/Car

**Files:**
- Modify: `tests/Fixtures/Projects/golden/api/openapi.yaml`
- Create/Modify: `tests/Fixtures/Projects/golden/expected/{7.4,8.0,8.1,8.2,8.5}/*.php.golden`

- [ ] **Step 1: Добавить схемы в конец `components/schemas` golden-спецификации**

```yaml
    Shipment:
      description: A shipment, told apart by an enum.
      oneOf:
        - $ref: '#/components/schemas/Parcel'
        - $ref: '#/components/schemas/Letter'
      discriminator:
        propertyName: mode
        mapping:
          parcel: '#/components/schemas/Parcel'
          box: '#/components/schemas/Parcel'
          letter: '#/components/schemas/Letter'
    ShipmentMode:
      type: string
      enum: [parcel, box, letter]
    Parcel:
      type: object
      required: [mode, weight]
      properties:
        mode: {$ref: '#/components/schemas/ShipmentMode'}
        weight: {type: number}
    Letter:
      type: object
      required: [mode]
      properties:
        mode: {$ref: '#/components/schemas/ShipmentMode'}
        stamp: {type: string}
```

- [ ] **Step 2: Run the golden test to see it fail**

Run: `vendor/bin/phpunit --filter GoldenProjectTest`
Expected: FAIL — нет `Shipment.php.golden`, `ShipmentMode.php.golden`, `Parcel.php.golden`, `Letter.php.golden`
(Pet/Cat/Dog и Vehicle/Car перезаписаны в Task 3 и здесь только просматриваются).

- [ ] **Step 3: Обновить снапшоты и просмотреть вывод**

Run: `UPDATE_SNAPSHOTS=1 vendor/bin/phpunit --filter GoldenProjectTest`

Проверить в `expected/8.2/`:
- `Cat.php.golden`: `__construct(string $name, string $petType = 'cat', public ?int $lives = 9)`, проверка
  `$petType !== 'cat'`, `parent::__construct($petType, $name)`, нет `withPetType`, `withName` →
  `new self($name, $this->petType, $this->lives)`;
- `Dog.php.golden`: значение по умолчанию `'Dog'` (неявный mapping);
- `Car.php.golden`: `__construct(string $kind = 'Car', public ?int $doors = null)`, нет `withKind`;
- `Parcel.php.golden`: `__construct(ShipmentMode $mode, public float $weight)`, проверка
  `$mode !== ShipmentMode::PARCEL && $mode !== ShipmentMode::BOX`, сообщение с `$mode->value`;
- `Letter.php.golden`: `ShipmentMode $mode = ShipmentMode::LETTER` перед `public ?string $stamp = null`;
- в `expected/7.4|8.0|8.5/Pet.php.golden` и `Vehicle.php.golden` нет `withPetType`/`withKind`;
- `expected/7.4/Letter.php.golden`: `string $mode = ShipmentMode::LETTER` и `@phpstan-param ShipmentMode::* $mode`.

Диагностик быть не должно (`GoldenProjectTest` требует пустой список).

- [ ] **Step 4: Проверить вывод на целевых версиях**

Run: `vendor/bin/phpunit` — PASS; `make test-targets` (вне песочницы)
Expected: `php -l` и PHPStan max на 7.4…8.5 без ошибок.

- [ ] **Step 5: Commit**

```bash
git add tests/Fixtures/Projects/golden
git commit -m "test: golden project covers checked, defaulted and enum discriminators"
```

### Task 7: документация, база знаний, финальные проверки

**Files:**
- Modify: `docs/openapi-support.md`, `docs/configuration.md`, `docs/extensions-spi.md`, `CHANGELOG.md`
- Modify: `.claude/docs/known-issues.md`, `.claude/docs/architecture.md`, `.claude/docs/domain-model.md`

- [ ] **Step 1: Документация**
  - `docs/openapi-support.md`:
    - в таблице «Composition» строку `oneOf`/`anyOf` с `discriminator` дополнить: «the constructor of each variant
      accepts only the values that select it (`\InvalidArgumentException` otherwise); one such value is the
      default; the discriminator has no `withX()`/`setX()`»;
    - из «Known limitations» убрать «Discriminator values are not enforced…»; оставить отдельным пунктом «A bare
      name in `discriminator.mapping` is resolved against the file holding the discriminator, not the root
      document.»; добавить пункты: «A mutable DTO with `accessors: public-properties` still lets code assign the
      discriminator directly.»; «Only a string, integer or enum discriminator is checked; another type gives a
      warning. A mapping value of an open (concrete) class selects that class, not its subclasses.»;
      «The discriminator parameter carries no literal PHPDoc type (`'cat'|'kitty'`): PHPStan would then report the
      constructor's own check as always false.»
  - `docs/configuration.md`: строка `withers: true                  # false: immutable DTOs get no with*()` в
    примере `dto:`; строка таблицы `| \`withers\` | \`true\` | \`true\`, \`false\` |`; пункт «**`withers`**: `false`
    leaves the `with*()` methods out of immutable DTOs (half of the output on a large spec); setters of mutable DTOs
    stay.»
  - `docs/extensions-spi.md`: в строке `property()` / `class()` упомянуть `discriminatorValues()` — значения
    дискриминаторов, выбирающих финальный класс.
  - `CHANGELOG.md`, `## [Unreleased]`:
    - `### Added`: «`dto.withers: false` leaves the `with*()` methods out of immutable DTOs.»
    - `### Changed`:

      ````markdown
      - The constructor of a discriminated variant checks the discriminator: a value that does not select the class
        throws `\InvalidArgumentException`. A variant selected by one value takes it as the default, and the
        parameter moves behind the required ones. The discriminator has no `withX()`/`setX()` any more, in the
        variants or in the base:

        ```php
        // before: public function __construct(string $petType, string $name, ?int $lives = 9)
        final readonly class Cat extends Pet
        {
            public function __construct(string $name, string $petType = 'cat', public ?int $lives = 9)
            {
                if ($petType !== 'cat') {
                    throw new \InvalidArgumentException(sprintf('"%s" does not select Cat by "petType".', $petType));
                }

                parent::__construct($petType, $name);
            }
        }
        ```

        Positional calls change: `new Cat('cat', 'Tom')` becomes `new Cat('Tom')`; named arguments and Symfony's
        Serializer are unaffected.
      ````
  - `.claude/docs/known-issues.md`: строку про ruling CR-015 заменить: «Свойство-дискриминатор варианта проверяется
    конструктором (E2): `SelectingValues` в `Hierarchy::link()`; open-предок не выбирает потомков; ближайший
    дискриминатор свойства решает; проверка, которую гарантирует тип, не выводится (PHPStan
    `notIdentical.alwaysFalse`); литеральный `@phpstan-param` не выводится — с ним проверка мертва для PHPStan, а
    `new self($this->kind, …)` даёт `argument.type`. Мутаторы дискриминатора подавляет `ClassShape::hasMutators()`;
    public-properties mutable DTO всё ещё позволяет присвоить его напрямую.»
  - `.claude/docs/architecture.md`: в абзац этапа 4b — `Hierarchy::link` заканчивается `SelectingValues`;
    `ClassShape::defaultOf/checks/hasMutators`, `PhpParserEmitter::parameters()` (порядок параметров ≠ порядок
    `parent::__construct`); `dto.withers` → `TargetProfile::hasWithers()` → `WitherStyle::NONE`.
  - `.claude/docs/domain-model.md`: `DiscriminatorValues` (непустые значения в типе свойства, `isChecked`) и
    инварианты `ClassModel`: значения только у `FINAL`, один набор на свойство.

- [ ] **Step 2: Полный прогон**

Run: `make fix && make verify && make infection` (в песочнице), затем `make test-targets && make bc-check` (вне
песочницы — Docker и тег предыдущего релиза).
Expected: всё зелёное; MSI 100% (выживших мутантов закрыть тестами; особое внимание — `covers()`, `holds()`,
ветке `NullsafePropertyFetch` и `str_replace('%', '%%', …)`); `bc-check` без нарушений (только добавления). Новый
ключ конфигурации → следующий релиз ядра минорный, 1.2.0.

- [ ] **Step 3: Commit**

```bash
git commit -am "docs: discriminator checks and dto.withers"
```
