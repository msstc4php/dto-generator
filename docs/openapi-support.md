# OpenAPI support

The generator reads OpenAPI 3.0, 3.1 and [3.2](#openapi-32) documents, and the definitions of [Swagger 2.0](#swagger-20) ones, in YAML or JSON and generates a class for each schema in
`components/schemas` that `sources[].include` selects, plus the schemas those refer to. Schemas follow JSON Schema
2020-12, as OpenAPI 3.1 does; [3.0 keywords](#openapi-30) are read as 3.1 writes them. With
[`sources[].operations`](configuration.md#operations), the schemas written in request bodies and responses give
classes too; parameters, headers, callbacks and `webhooks` are not read.

## Types

| Schema | PHP type | PHPDoc |
|---|---|---|
| `type: string` | `string` | `non-empty-string` with `minLength` ≥ 1 |
| `type: integer` | `int` | `int<min, max>`, `positive-int`, `non-negative-int` from `minimum`/`maximum`/`exclusive*` |
| `type: number` | `float` | |
| `type: boolean` | `bool` | `true`/`false` from a one-value `enum` (`enum: [true]`), as from `const` |
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
- A `default` that does not fit the type (Xero's `default: "false"` of a boolean) gives a warning and the property
  defaults to `null`; a default on a required property is ignored.
- A default for an object (a date included) or a map cannot be a PHP constant expression: the property defaults to
  `null`, with a warning. On PHP 8.1 and newer, the empty object `{}` as the default of a generated class is
  `new Name()`, when that class is not abstract and its constructor needs no argument; a default leading back to its
  own class (`{}` of `A` sets `{}` of `B`, which sets `{}` of `A`) is `null`. A `default: []` there reads as `{}`, as
  decoded JSON tells the two apart no more than PHP arrays do.

## Composition

| Schema | Output |
|---|---|
| `enum` of strings or of integers | PHP 8.1+: a backed `enum`. 7.4 and 8.0: a `final class` of constants; the property is `string`/`int` with `@phpstan-var Name::*` |
| `enum` mixing strings and integers | no PHP enum: a union of the literals (see [Types](#types)), with a warning |
| `enum` whose values `type` admits none of (`type: array` with the values of its items, as Zoom and Vimeo write; `type: string` with integers) | the enum is ignored, with a warning: the property keeps its type (`list<string>`) |
| `allOf` with one `$ref` plus own properties, `extends` strategy | `class Child extends Base`; `Base` is not `final` |
| an `allOf` member that only says `type: object` (no properties, `required`, composition, enum, `const`, `patternProperties`, discriminator or schema for other properties; `additionalProperties: true` and annotations allowed) beside members that are objects | no member: `allOf: [$ref: Tag, {type: object, description: …}]` is `Tag`, as Asana, Bitbucket and Plaid write it; it still rules out `null` unless it admits it, and a union stays as it is (`Tag` or a string stays `Tag|string`). Its other keywords (`minProperties`, `not`, …) give no PHP type and are left to validation. Beside a member that can be no object, however written (a string with a date format or an `x-php-type`, an enum, a list), no value passes: an error |
| a schema that holds itself in a list or a map, through `$ref` (a JSON value, as Vercel's `EdgeConfigItemValue`: a string, number, boolean, object or list of such values) | the type of the outer level, with `mixed` inside: `string\|float\|bool\|array<array-key, mixed>\|list<mixed>\|null`. A `$ref` chain that leads back to itself without a list or map between is an error, as no value ends it |
| a named `allOf` of one `$ref`, reached directly or through a chain of `$ref` (members that only annotate — `title`, `description`, `example`, `externalDocs`, `readOnly`, `nullable` and the like — or only say `type: object` add nothing), to a class with a `discriminator` that has no `mapping` or whose `mapping` names the schema | a class extending it, that adds nothing: how Twitter writes the problems its `mapping` names. Without a `mapping`, every such schema is one, as the discriminator then names variants by their schema names: a "nullable" or described copy of the base included. To any other schema, and to a base whose `mapping` leaves it out, such an `allOf` is another name of it, no class |
| `allOf` with several `$ref`, or the `merge` strategy | one class with all the properties. A property several members describe gets the narrower type when one admits every value of the other (`string` and a date, `number` and `integer`, `T|null` and `T`, a list of anything and a list of `T`, an object of any properties and a class, a class and one that has all its properties, alike, requires them, admits `null` only if the first does and may have more, when the first says nothing but its properties, which it requires and annotations and is no variant of a discriminated union, whose values it checks; a class and one that extends it, also through other classes, without read or write model views); inline objects of that property (or of its items or values) in several members become one class with the properties of all, unless a `$ref` reaches one of them, they are alternatives of a union, or the second says more than its properties (its own `allOf`, `additionalProperties`, `x-` keys, keywords of the class); other types are an error |
| a child's schema declaring a property of its parent again | the parent's property stays, with a warning, when the child only narrows its values (null, a refinement of the same scalar, the cases of an enum, an enum sharing some values with the parent's) with the same name and default (PHP lets no subclass narrow the type; the Symfony bridge checks the narrowing); a class, a date, a list or a map over the parent's type, which PHP lets no subclass declare, merges the class's `allOf` instead of extending, as `x-php-all-of: merge` does, with a warning at the property: the class is no longer a subtype of the parent, each such property takes the type both schemas admit, and a property either requires is required. A child type that adds nothing PHP holds (`{items: …}` without `type` over a typed list) leaves the parent's property, with a warning. Another PHP name or default, types with no common values (outside a variant, below) and a class with `x-php-all-of` keep the error |
| a variant of a discriminated union (or a class extending one) declaring a property of its parent again with the same PHP name and default, but a type PHP lets no subclass declare (Bitbucket's `team.links`, a `TeamLinks` over `AccountLinks`) | the parent's property stays, with a warning that also says when the variant alone requires it: a variant must extend its base, so its members cannot be merged, `x-php-all-of: extends` or not. The narrower type, and a requirement only the variant makes, are lost in the variant, alike in the read and the write model |
| `oneOf`/`anyOf` with a `discriminator` | an `abstract` base class with the common properties; the variants extend it. A variant the `mapping` leaves out is selected by the one value its discriminator property allows (`const` or a one-value `enum`, here, behind a `$ref`, in a one-member `allOf` around it, in every branch of a `oneOf`/`anyOf` around it but `null`, or in a member of its `allOf` that is no other variant), when nothing else claims that value (the mapping, another variant's value or schema name, the value of an inline variant), and by its schema name otherwise: a property that allows one value never holds the schema name OpenAPI defaults to. A subclass that inherits its one value from another variant keeps its schema name, which a document following its own schema never sends. The constructor of each variant accepts only the values that select it (`\InvalidArgumentException` otherwise); a class selected by a single value takes it as the default; an open (concrete) class accepts its own values when it is instantiated itself and the values of its subclasses only from them (`parent::__construct()`). No class in a discriminated chain (the discriminated bases, their ancestors and their subclasses) has a `withX()`/`setX()` for a property a discriminator of that chain reads. The rule is per property: a subclass whose own chain does not read it, while its parent's does (a sibling `allOf` subclass of a shared base, or a base with a different discriminator), gets the mutators back. It declares the setter and, on 7.4, 8.0 and 8.5, the cloning wither itself; its subclasses inherit them. On 8.1–8.4 an open class has no withers, so its final subclasses declare the `new self` wither; with `dto.withers: false`, or mutable DTOs with `accessors: public-properties`, there is none to restore |
| `oneOf`/`anyOf` without a discriminator | a union type |
| a `oneOf`/`anyOf` with a `discriminator` beside `properties` whose inline variants only restrict those properties, one of them allowing several values of the discriminating property (`status: {enum: [queued, in_progress]}`) | the discriminator is ignored, with a warning: the variants name no class, and the schema is a class of its properties |
| `allOf` that makes a class beside a `oneOf`/`anyOf` without a `discriminator`, in the same schema (Shipengine's `calculate_rates_request_body`) | the class of the `allOf` and the schema's own properties, named or inline, with a warning that the union is not represented. The Symfony bridge checks it only when its members are inline objects of `properties` and `required`; members behind `$ref`, as Shipengine's, get a warning there too. With a `discriminator` the pair stays an error |
| `allOf` with one member that is a `oneOf`/`anyOf` of objects without a discriminator, written there or behind a `$ref`, and more than that member (`allOf: [$ref: Event, {properties: {stream_id}}]`) | the union of a class per variant, which has the variant and the rest of the `allOf`: `allOf: [anyOf: [A, B], E]` is `anyOf: [allOf: [A, E], allOf: [B, E]]`. A named schema names them `<Schema><Variant>` (its `x-php-class-name` as written, then the variant's `x-php-class-name`, schema name or `title`; `Option<N>` without one, for a name without letters or digits, or when two variants' names make the same class name), and with the `extends` strategy they extend the variant; an `allOf` naming such a schema in turn extends these (`Outer: allOf: [$ref: Stream, …]` gives `OuterStreamA extends StreamA extends A`). Inline objects of the shared part take the schema's name (`StreamMeta`). Inline the classes are `<Parent><Property><Variant>`. What applies to the whole value (`readOnly`, `writeOnly`, a description) stays on the union. Diagnostics about a variant point at `…/<Schema>/x-dto-variant/<member>/<variant>`, which no place of the document holds |
| an inline object or enum in a property, its `items` or `additionalProperties` | a named class `<Parent><Property>` (`Order.items[]` → `OrderItemsItem`) |
| an inline object or enum as a member of a `oneOf`/`anyOf` | a named class: the member's `title` in PascalCase (the title of an inline property schema is not used), else `<Parent><Property>Option<N>` (N counts from 1 over `oneOf`, then `anyOf`) |
| an inline object or enum in a named alias (`Pets: {type: array, items: {…}}`) | `<Alias>Item`, `<Alias>Value`, `<Alias>Option<N>` |
| an inline schema whose name another schema already holds | the class or enum of that schema when the two say the same in one file (every keyword but the schema's own `title`, `description` included, in any order, subschemas too: OpenAI's `Beta` copies; for an enum, the same cases and deprecation are enough, so `allOf` members that restate an enum in another order, with another description or with `null` share it, unless such an enum has a warning of its own), unless an `allOf` gives either class the properties of another schema; otherwise it gives way: quietly to the name derived from its place when a title gave the first (or to the class of the schema holding that name, on the same terms), else, with a warning, to that name numbered from 2 (`UserAddress2`) |
| named schemas whose names differ only in letter case or separators (`User`, `user`, `user_profile`, `UserProfile`), or are the same in two files of one namespace, as are a schema a `$ref` points into (`#/components/schemas/Database/properties/hosts`, named by its last segment) and another such | the later one in the document is numbered from its own name (`user` → `User2`, `USER` → `USER3`), with a warning, once every named schema holds its name, so a number never takes the name of another named schema (`Notary2`); an inline schema whose `x-php-class-name` asks for that number gets the error instead. The result follows the order of the document; `x-php-class-name` pins a name. A schema with an explicit `x-php-class-name` that another holds, and a name a read or write suffix gives (`PetRead`), stay errors (the latter suggests `dto.readWriteSuffixes`) |
| a schema whose `x-php-class-name` names a class another schema has | an error suggesting `x-php-class-name`, which `classNames` of the source sets without changing the document; an inline schema that says the same as the one holding the name (an enum: has the same cases) gets its class instead |
| properties of one class that become the same PHP name, letter case ignored (Trello's `prefs/background` and `prefs_background`) | the later one in the document is numbered from 2 past every name a property of the class has by itself, an `x-php-name` included (`$prefsBackground2`), with a warning, alike in the read and the write model and in every `allOf` member that declares it; one whose `x-php-name` asks for a name another holds is an error. Numbers are given within a class: a name a parent declares, or a numbered parent property a child declares again, is still an error that `x-php-name` resolves. An `x-php-name` applies to the declaration that has it, so another `allOf` member naming the property otherwise is an error, as without a clash. The number follows the name as it is (`_1` beside `1` becomes `$_12`), and an explicit name met by a spelled sign (`plus1` beside `+1`) numbers the later one: `$plus12`, or the error when the explicit name comes later. A property name with no usable characters (SendGrid's `""`) leaves the property out of the class, with a warning, unless `x-php-name` names it |
| names that only a leading sign tells apart (`+1`, `-1`), or a sign alone (`*`) | the signs spelled out: `$plus1`, `$minus1`, case `ASTERISK`; a lone `+1` stays `$_1`, and so does one whose spelled name another name holds (`+1` beside `plus1` stays `$_1`, `-1` becomes `$minus1`; with `minus1` there too, `-1` is numbered: `$_12`) |
| names that only a sign within tells apart (`Etc/GMT+1`, `Etc/GMT-1`) | the signs some have and others lack spelled out, else every sign, unless one stands between two ASCII letters: cases `ETC_GMT_PLUS_1`, `ETC_GMT_MINUS_1`; `in-progress` beside `in_progress` is still an error, as `-` there joins words. Another value spelled alike can spell more signs of the others (`a_1` beside `a/1+` and `a/1-` makes them `A_SLASH_1_PLUS`), so set `x-enum-varnames` or `x-php-name` where the names must not move |
| a `oneOf`/`anyOf` with a `discriminator` and no properties of its own, that no class extends through `allOf` and that is no variant of a union that stays a class, a variant of which extends another class (through its `allOf`, or as the variant of another union) | an empty `interface` carrying the discriminator; every variant `implements` it, after the class it extends, and its constructor checks the interface's values as for an abstract base (each base's values, when several map it). Inner unions are decided before the unions that list them. A variant listed by several such unions implements each; a union that stays a class takes it as its subclass, else the first union by class name; an interface variant extends the interface |
| an inline `oneOf`/`anyOf` with a `discriminator` and an inline object variant | a class named like any inline object (`<Parent><Property>`), with the variants extending it as below |
| an inline object as a variant of a named `oneOf`/`anyOf` with a `discriminator` | a class extending the base: the member's `title`, else `<Base>Option<N>`; its discriminator value is the one value its discriminator property has (`const` or a one-value `enum`, here or in a member of its `allOf`), as `mapping` cannot name an inline schema |

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

- **Inline objects in an inline discriminated `oneOf`/`anyOf` (a property's schema) that also has properties or an
  `allOf` are not generated:** an error asks to move them to `components/schemas`.
- **A discriminated union that stays a class (it has properties, a class extends it through `allOf`, or it is a
  variant of a union that stays a class), a variant of which extends another class or is an interface,** is an
  error: the variant would need two parents.
- **An `allOf` with a `oneOf`/`anyOf` member that is no union of objects without a discriminator** (a variant that is
  no object or only `null`, `required` or properties beside the union, both `oneOf` and `anyOf`, two such members, a
  variant that leads back to the schema) is an error: no class adds properties to
  each variant. To generate the rest of the document, leave such a schema out with the source's
  [`exclude`](configuration.md#sources).
- **A missing key is not tracked.** An optional property without its key takes the schema's `default`, else `null`;
  a DTO cannot tell a missing key from an explicit `null`, and an optional property that is not nullable accepts
  `null`. JSON Merge Patch needs the raw request.
- **Not interpreted:** `prefixItems`, `patternProperties`, `if`/`then`/`else`, `not`, `dependentSchemas`,
  `dependentRequired`, `unevaluatedProperties`, `unevaluatedItems`, `contains`, `minContains`, `maxContains`,
  `propertyNames`, `additionalItems`, `dependencies`, `$dynamicRef`. Each gives a warning, except in a schema with
  `x-php-type` or `x-php-skip`, and except where an extension checks the keyword (the Symfony bridge 1.3 checks
  `dependentRequired`, `propertyNames`, `contains`, `minContains`, `maxContains`, `patternProperties` and
  `if`/`then`/`else`, 1.4 also `not`, `dependentSchemas`, `prefixItems` and `unevaluatedItems`, unless
  `validator: false`, and warns itself where it cannot);
  the PHP type ignores them. `readOnly`/`writeOnly` are ignored without a warning by default: they describe requests
  and responses, which one DTO does not tell apart. `dto.readWriteModels: split`, or `x-php-read-write: split` on one
  schema, gives such classes a read and a write model ([configuration](configuration.md#dto)); the keywords count on
  properties only, not on the `items` of an array. A class both models share is one class: when only one of them
  extends it (an `allOf` over it in a `readOnly` or `writeOnly` property), it is open in both.
- **OpenAPI 3.0** <a id="openapi-30"></a> documents (`openapi: 3.0.x`) are read as 3.1, with the keywords 3.0
  writes otherwise rewritten as 3.1 writes them, also in an inline `not`:
  - `nullable: true` admits `null`: it joins the `type` (`type: string` becomes `[string, 'null']`); beside a `$ref`,
    an `allOf`, a `oneOf` or an `anyOf` without a `type` it makes that nullable, as most 3.0 documents mean it (the
    specification ignores siblings of a `$ref`);
  - `exclusiveMinimum: true` beside `minimum: 5` is `exclusiveMinimum: 5` (`int<6, max>`), and so for
    `exclusiveMaximum`; without a numeric bound beside it it gives a warning, and a number in its place is read as
    in 3.1, with a warning.

  A document that states no `openapi` version is read in the version of the document that refers to it first; one
  that 3.0 and 3.1 documents both refer to gives a warning. In a 3.1 document `nullable` has no effect and gives a
  warning; use `type: [T, 'null']`.
- **OpenAPI 3.2** <a id="openapi-32"></a> documents (`openapi: 3.2.x`) are read as 3.1: their schemas are the same.
  A `$self` that names another URI than the document's own location gives a warning: references in the document
  still resolve against where it was read from, and a `$ref` to the `$self` URI does not reach the document. A
  `$self` with a fragment is not read either. With [`sources[].operations`](configuration.md#operations), the `query`
  method, `additionalOperations`, the `itemSchema` of sequential media types and `components/mediaTypes` are read;
  so is [`discriminator.defaultMapping`](#discriminator-defaultmapping).
- **Swagger 2.0** <a id="swagger-20"></a> documents (`swagger: '2.0'`, without `openapi`) give a class for each schema
  in `definitions`, read as OpenAPI 3.0 schemas, which they nearly are: `discriminator: petType` names the property
  as `discriminator: {propertyName: petType}` does, `x-nullable: true` admits `null` as `nullable: true` does, and
  `type: file` is binary content (`string`). The documents they refer to are read as 2.0 too, unless they state
  another version. A definition that extends a discriminated one through `allOf` is selected by its
  `x-discriminator-value`, as swagger-codegen reads it, or else by its name. A subtype with nothing of its own
  (`Dog: {allOf: [$ref: Pet]}`) gives no class, as in 3.x, and the parent's discriminator then has no variant to
  select. Keys of `classNames` and `propertyNames` name its schemas by `#/definitions/...`. Their operations are not
  read; `sources[].operations` gives a warning there.
- **`const`** keeps a date or a `formats` class when `format` names one, and keeps the declared `type` (with a
  warning) when the constant does not fit it. A bare `const` member of `allOf` (`allOf: [{$ref: Code}, {const: x}]`)
  narrows the type to its literal; a constant outside that type (`{type: integer, maximum: 10, allOf: [{const: 50}]}`)
  gives a warning and keeps the type. The narrowed type stays nullable when the rest of the schema admits `null`,
  although the constant excludes it: wider than the schema, never narrower.
- **Defaults** must equal every `const` and, for a mixed enum, be one of its values, also when they come through a
  `$ref` or an `allOf` member. An `int` property takes no `2.0` default, as PHP would not compile it.
- **Strings in literal types are plain.** A `const` or enum string with quotes, backslashes, `|`, `*`, `{`, `}`, `@`
  or non-ASCII characters keeps the type `string` without the literal.
- **Hoisted names give way to named schemas.** An inline member whose title or derived name equals a schema in
  `components/schemas` takes another name (see [Composition](#composition)); the named schema keeps its class. An
  inline member that says the same as that schema gets its class.
- **Remote `$ref`** (`https://…`) works only for the URLs [`remoteRefs.allow`](configuration.md#remoterefs) names.
- **Anchors.** `$ref: '#Pet'` or `'common.yaml#Pet'` names the one schema of that document with `$anchor: Pet` (or
  `$dynamicAnchor: Pet`, read as a plain anchor); none or several is an error, at the first `$ref` to it. The whole document is one scope: a `$id` inside it starts none, and a `$ref` by `$id` is not
  resolved. `classNames` and `propertyNames` keys take JSON pointers only.
- **`discriminator.defaultMapping`** <a id="discriminator-defaultmapping"></a> (OpenAPI 3.2, read in every
  version) names the variant for a missing or unmapped value, as `mapping` names one, bare names included; it must
  be one of the variants. Its constructor does
  not check the discriminator, which may hold any value, or none when the variant does not require the property; it
  keeps its own values in the mapping. The default, or the variants of an abstract default, are unchecked by that
  discriminator only: another discriminated base reading the same property still checks the values it maps to them.
  `defaultMapping: null` counts as absent, as `mapping: null` does.
  Selecting the default when deserializing is up to the serializer: see the bridge.
- **A bare name in `discriminator.mapping`** is resolved against the file holding the discriminator, not the root
  document.
- **A mutable DTO with `accessors: public-properties`** still lets code assign the discriminator directly.
- **Only a string, integer or enum discriminator is checked;** another type, an untyped property included, gives a
  warning and no check.
- **An optional discriminator selected by several values** becomes a required parameter unless its default is one of
  them; with a single value it defaults to it.
- **Denormalizing straight into a variant** with a discriminator value of another class now throws
  `\InvalidArgumentException` from the constructor. Symfony's Serializer turns only a `TypeError` there into a
  validation error, so e.g. `#[MapRequestPayload] Cat $cat` answers 500 instead of accepting the foreign value.
  Denormalizing through the base and its `DiscriminatorMap` is unaffected.
- **A checked nullable discriminator declared by a concrete class** (a variant or an open class) has a non-nullable
  property and getter on PHP 7.4 (`getKind(): string`), as the constructor rejects `null` before assigning it; from
  PHP 8.0 the property is promoted and keeps the parameter's type (`?string`). A rejected `null` reads `null` in the
  message: `null does not select Side by "kind".`
- **The discriminator parameter carries no literal PHPDoc type** (`'cat'|'kitty'`): PHPStan would then report the
  constructor's own check as always false.
- **Swagger-2-style inheritance** (a base with a discriminator that the variants extend through `allOf`) makes the
  base `abstract`.
- **A concrete `allOf` base loses its `withX()` methods on PHP 8.1–8.4** once it has a subclass: readonly properties
  cannot be copied into a subclass without `new static`, which is unsafe. 7.4, 8.0 and 8.5 keep them.
- **Paths are lexical:** symlinks are not resolved, so one file reached through two paths gives two schemas.
- **Quote dates and versions in YAML.** `default: 2020-01-01` reads as a timestamp and `8.10` as `8.1`.
