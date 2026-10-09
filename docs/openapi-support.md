# OpenAPI support

The generator reads OpenAPI 3.1 documents in YAML or JSON and generates a class for each schema in
`components/schemas` that `sources[].include` selects, plus the schemas those refer to. Schemas follow JSON Schema
2020-12, as OpenAPI 3.1 does. Paths and operations are not read.

## Types

| Schema | PHP type | PHPDoc |
|---|---|---|
| `type: string` | `string` | `non-empty-string` with `minLength` ≥ 1 |
| `type: integer` | `int` | `int<min, max>`, `positive-int`, `non-negative-int` from `minimum`/`maximum`/`exclusive*` |
| `type: number` | `float` | |
| `type: boolean` | `bool` | |
| `format: date-time`, `date` | `\DateTimeImmutable` (or `\DateTime`, see `dto.dateTimeClass`) | |
| `format: int32`, `int64` / `float`, `double` | `int` / `float` | |
| `format: email`, `uri`, `uuid`, `hostname`, `ipv4`, `ipv6`, `time`, `byte`, `binary` | `string` | |
| a format from `formats` in the config | that class | |
| `type: [T, 'null']`, or a `oneOf`/`anyOf` of `T` and `null` | `?T` | |
| `type: [string, integer]`, or `oneOf`/`anyOf` without a discriminator | `string\|int` (PHP 8.0+); no native type on 7.4 | `string\|int` |
| `type: array` with `items` | `array` | `list<T>` |
| an object with only `additionalProperties: <schema>` | `array` | `array<array-key, T>` |
| `properties` and `additionalProperties: <schema>` | an extra `$additionalProperties` property | `array<array-key, T>` |
| `type: object` without `properties` | `array` | `array<array-key, mixed>` |
| a schema without `type` (`{}`) | `mixed` (no native type on 7.4) | |
| `$ref` | the referenced class or enum | |
| `x-php-type` | that class | |
| `const: 'card'`, `const: 5`, `const: true` | `string`, `int`, `bool` | `'card'`, `5`, `true` |
| `type: number` with `const: 2`; `const: 1.5` | `float` | |
| a `oneOf`/`anyOf` of `const` members | the union of their literals (`'a'\|'b'`) | |
| `enum` mixing strings and integers (`[low, 1]`) | `string\|int` (PHP 8.0+); no native type on 7.4 | `'low'\|1`, with a warning |

Map keys are `array-key`, not `string`: PHP turns a JSON key such as `"200"` into the integer `200`.

An unknown `format` falls back to the base type with a warning.

PHPDoc comes in two layers. `@var`, `@param` and `@return` carry what any PHPDoc reader understands (`list<T>`,
`?string`), and only when it says more than the native type. `@phpstan-var`, `@phpstan-param` and `@phpstan-return`
carry the precise type (`non-empty-string`, `int<1, 100>`, `Status::*`) when it differs. Older readers, such as the
PHPDoc extractor of Symfony 5.4, mistake `non-empty-string` for a class name; they never see it.

## Required properties and defaults

- A property listed in `required` and not nullable is a required constructor argument.
- Any other property is nullable, defaulting to the schema's `default` or to `null`.
- Required arguments come first, then optional ones, each in the order of `properties`.
- A `default` that does not fit the type is an error; a default on a required property is ignored.
- A default for an object (a date included) or a map cannot be a PHP constant expression: the property defaults to
  `null`, with a warning.

## Composition

| Schema | Output |
|---|---|
| `enum` of strings or of integers | PHP 8.1+: a backed `enum`. 7.4 and 8.0: a `final class` of constants; the property is `string`/`int` with `@phpstan-var Name::*` |
| `enum` mixing strings and integers | no PHP enum: a union of the literals (see [Types](#types)), with a warning |
| `allOf` with one `$ref` plus own properties, `extends` strategy | `class Child extends Base`; `Base` is not `final` |
| `allOf` with several `$ref`, or the `merge` strategy | one class with all the properties; one property with two types is an error |
| `oneOf`/`anyOf` with a `discriminator` | an `abstract` base class with the common properties; the variants extend it |
| `oneOf`/`anyOf` without a discriminator | a union type |
| an inline object or enum in a property, its `items` or `additionalProperties` | a named class `<Parent><Property>` (`Order.items[]` → `OrderItemsItem`) |
| an inline object or enum as a member of a `oneOf`/`anyOf` | a named class: the member's `title` in PascalCase, else `<Parent><Property>Option<N>` (N counts from 1 over `oneOf`, then `anyOf`) |
| an inline object or enum in a named alias (`Pets: {type: array, items: {…}}`) | `<Alias>Item`, `<Alias>Value`, `<Alias>Option<N>` |
| two classes with the same name | an error suggesting `x-php-class-name` |

Recursive schemas (trees, graphs) are fine: a `$ref` is a reference to the class, not a copy.

`$ref` may point inside the document (`#/components/schemas/User`) or into another local file
(`common.yaml#/components/schemas/Money`, `./money.yaml`). The [`sources`](configuration.md#sources) section decides
the namespace of schemas from other files.

## Documentation in the output

- The `description` of a schema becomes the PHPDoc of its class or enum; that of a property becomes the PHPDoc of the
  property (or of the constructor parameter, when promoted).
- `x-enum-descriptions` documents enum cases.
- `deprecated: true` adds `@deprecated`.
- Each file starts with `// @generated by msstc4php/dto-generator — DO NOT EDIT`.

## Target PHP versions

The generator itself runs on PHP 7.4 or later; `target.php` is the version the generated code must run on.

| Feature used | From |
|---|---|
| Typed properties | 7.4 |
| Constructor promotion, union types, attributes, `mixed` | 8.0 |
| `readonly` properties, enums, `new` in attribute arguments | 8.1 |
| `readonly` classes | 8.2 |
| `clone` with properties | 8.5 |

### Class shape per target

| | 7.4 | 8.0 | 8.1 | 8.2 – 8.4 | 8.5 |
|---|---|---|---|---|---|
| immutable | `final class`, private typed properties, `getX()` | + promoted constructor | public `readonly` promoted properties | `final readonly class` | as 8.2 |
| `withX()` of an immutable class | `clone`, then assign | as 7.4 | `new self(...)` | `new self(...)` | `clone($this, ['x' => $x])` |
| mutable, `accessors: getters` | private properties, `getX()`, `setX(): self` | + promoted constructor | as 8.0 | as 8.0 | as 8.0 |
| mutable, `accessors: public-properties` | public typed properties | public promoted properties | as 8.0 | as 8.0 | as 8.0 |

Classes are `final`, except the bases of `allOf` and of discriminated unions. Attributes and annotations follow
[`target.metadata`](configuration.md#target).

## Known limitations

- **Inline objects in a `oneOf`/`anyOf` with a discriminator are not generated:** the mapping needs a `$ref`, so an
  error asks to move them to `components/schemas`.
- **A missing key and `null` are the same.** An optional property is `null` either way.
- **Not interpreted:** `prefixItems`, `patternProperties`, `if`/`then`/`else`, `not`, `dependentSchemas`,
  `dependentRequired`, `unevaluatedProperties`, `unevaluatedItems`, `contains`, `minContains`, `maxContains`,
  `propertyNames`, `additionalItems`, `dependencies`, `$dynamicRef`. Each gives a warning, except in a schema with
  `x-php-type` or `x-php-skip`;
  the PHP type ignores them. `readOnly`/`writeOnly` are ignored without a warning: they describe requests and
  responses, which one DTO does not tell apart.
- **OpenAPI 3.0 `nullable: true` has no effect** and gives a warning; use `type: [T, 'null']`.
- **`const`** keeps a date or a `formats` class when `format` names one, and keeps the declared `type` (with a
  warning) when the constant does not fit it. A `const` beside a type in `allOf` (`allOf: [{$ref: Code}, {const: x}]`)
  only constrains the type. A `default` must equal the `const` or, for a mixed enum, one of its values, also when they
  come through a `$ref` or an `allOf` member; an `int` property takes no `2.0` default, as PHP would not compile it.
- **Strings in literal types are plain.** A `const` or enum string with quotes, backslashes, `|`, `*`, `{`, `}`, `@`
  or non-ASCII characters keeps the type `string` without the literal.
- **Hoisted names give way to named schemas.** An inline member whose title or derived name equals a schema in
  `components/schemas` gets a collision error; the named schema keeps its class.
- **Remote `$ref`** (`https://…`) and anchors (`$anchor`, `#Name`) are not supported.
- **Discriminator values are not enforced.** A variant's discriminator property stays a constructor argument, so
  `new Cat('dog', …)` is accepted. A bare name in `discriminator.mapping` is resolved against the file holding the
  discriminator, not the root document.
- **Swagger-2-style inheritance** (a base with a discriminator that the variants extend through `allOf`) makes the
  base `abstract`.
- **A concrete `allOf` base loses its `withX()` methods on PHP 8.1–8.4** once it has a subclass: readonly properties
  cannot be copied into a subclass without `new static`, which is unsafe. 7.4, 8.0 and 8.5 keep them.
- **Paths are lexical:** symlinks are not resolved, so one file reached through two paths gives two schemas.
- **Quote dates and versions in YAML.** `default: 2020-01-01` reads as a timestamp and `8.10` as `8.1`.
