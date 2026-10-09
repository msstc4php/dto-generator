# `x-` keywords

The generator reads these vendor extensions from the schemas. Keys starting with `x-php-` or `x-dto-` are reserved:
an unknown one is an error, so a typo is caught. Other `x-` keys are ignored unless an alias or an extension claims
them; the [Symfony bridge](https://github.com/msstc4symfony/dto-generator-bridge) reads `x-validator-*` and
`x-serializer-*`.

| Key | On | Value | Effect |
|---|---|---|---|
| `x-php-class-name` | schema | class name | Name of the generated class or enum |
| `x-php-name` | property | identifier | Name of the PHP property |
| `x-php-type` | schema, property, `items`, `additionalProperties` | FQCN | Use this class instead of generating one |
| `x-php-skip` | schema, property | `true` / `false` | Leave it out of the output |
| `x-dto-mutable` | schema | `true` / `false` | Overrides `dto.mutability` for this class |
| `x-php-all-of` | schema | `extends` / `merge` | Overrides `dto.allOfStrategy` for this `allOf` |
| `x-php-attributes` | schema, property | list | Attributes to write |
| `x-enum-descriptions` | schema with `enum` | map | PHPDoc per enum case |
| `x-enum-varnames` | schema with `enum` | list | Names of the enum cases |
| an alias from `attributeAliases` | schema, property | any | An attribute built from a template |

## Names

Class names are the schema names in PascalCase; property names are the wire names in camelCase (`first_name` →
`$firstName`). Reserved words get a `_` suffix in class names; property names are normalised into valid identifiers.
Two names that end up equal are an error that suggests these keys.

```yaml
Order:
  x-php-class-name: PurchaseOrder        # class PurchaseOrder
  properties:
    '@type':
      type: string
      x-php-name: kind                   # $kind; the wire name stays "@type"
```

Only the first word of a name is lower-cased (`URL_PATH` → `urlPATH`, `HTTPStatus` → `httpStatus`); use `x-php-name`
for another form. Extensions see the original wire name, so the Symfony bridge writes `SerializedName('@type')`.

## `x-php-type`

Maps a schema or a property to a class of yours. On a schema, no class is generated and every `$ref` to it gets your
type:

```yaml
Money:
  type: object
  x-php-type: App\Money\Money
  properties: {amount: {type: integer}, currency: {type: string}}
```

`total: {$ref: '#/components/schemas/Money'}` becomes a property of type `\App\Money\Money`. The generator does not check
that your class matches the schema; building it from the wire data is up to your serializer. For a `format` used in
many places, [`formats`](configuration.md#formats) in the config is shorter.

## `x-php-skip`

- On a property: the property is not generated. Skipping a required property gives a warning.
- On a schema: no class is generated. A `$ref` to it becomes `mixed`, with a warning.

## `x-dto-mutable` and `x-php-all-of`

```yaml
Draft:
  x-dto-mutable: true          # setters, while the rest of the project is immutable
  ...
Admin:
  x-php-all-of: merge          # copy User's properties instead of extending User
  allOf:
    - $ref: '#/components/schemas/User'
    - properties: {role: {type: string}}
```

## `x-enum-descriptions`

```yaml
state:
  type: string
  enum: [new, paid]
  x-enum-descriptions:
    new: Just created.
    paid: Payment received.
```

Each description becomes the PHPDoc of its enum case, or of its constant before PHP 8.1. A key that is not an enum
value is an error.

## `x-enum-varnames`

```yaml
code:
  type: integer
  enum: [1, 2]
  x-enum-varnames: [Active, Blocked]     # case ACTIVE = 1; case BLOCKED = 2 (instead of VALUE_1, VALUE_2)
```

The list holds one name per non-null enum value, by position, as in openapi-generator (a value listed twice keeps
its first name). Names are normalised like the values (`Active` → `ACTIVE`); a wrong length, a name with no usable
characters or two names giving one case is an error.

## `x-php-attributes`

A list of attributes for the class (on a schema) or the property:

```yaml
x-php-attributes:
  - class: App\Attr\Sensitive
  - class: App\Attr\Mask
    args:                                     # a map gives named arguments, a list positional ones
      keep: 4
      mode: { const: App\Mask::TAIL }         # App\Mask::TAIL — a constant or an enum case
      target: { class: App\Model\User }       # App\Model\User::class
      inner: { new: { class: App\Attr\Rule, args: [1] } }   # new App\Attr\Rule(1)
```

| Value | Written as |
|---|---|
| scalar or `null` | a literal |
| list | `[...]` |
| map (outside `args`) | `['key' => ...]` |
| `{const: 'A::B'}` | `A::B` |
| `{class: 'Foo'}` | `Foo::class` |
| `{new: {class: Foo, args: …}}` | `new Foo(...)` — attributes on PHP 8.1+, a nested annotation below 8.0 |
| `{literal: {...}}` | the map as is, for a map whose only key happens to be `const`, `class` or `new` |

From PHP 8.0 on these are attributes; below 8.0 they are Doctrine-style annotations, where named arguments become
`key=value` and a positional one becomes `value` (see [`target.metadata`](configuration.md#target)). `new` on a PHP
8.0 target cannot be written: an error, or, when `target.strict` is `false`, a warning and the attribute is left out. Property attributes go on the
promoted constructor parameter (from PHP 8.0) or on the property (below 8.0).

With [`verifyClasses`](configuration.md#verifyclasses) on, every class and constant named here must exist.

## Aliases

An alias turns a short `x-` key into an attribute. Declare it in the config:

```yaml
attributeAliases:
  x-sensitive:
    class: App\Attr\Sensitive
    args: { level: '{value}' }
  x-audit:
    class: App\Attr\Audited
    args: { level: '{value.level}', by: '{value.user}' }
```

and use it in the schemas:

```yaml
properties:
  email: { type: string, x-sensitive: high }                        # #[Sensitive(level: 'high')]
  phone: { type: string, x-audit: { level: 2, user: admin } }       # #[Audited(level: 2, by: 'admin')]
```

- `{value}` is the whole value of the key; when the placeholder is the entire string, the value keeps its type
  (number, list, map).
- `{value.<key>}` is a field of a map value; a value without that field is an error.
- An alias must start with `x-` and stay outside `x-php-`, `x-dto-`, `x-enum-descriptions`, `x-enum-varnames` and the keys extensions
  claim.
