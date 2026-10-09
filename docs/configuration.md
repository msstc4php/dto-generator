# Configuration reference

The generator reads `dto-generator.yaml` (or `dto-generator.json`; `--config` names another file). Paths in it are
relative to the config file. An unknown key is an error, so a typo never passes silently.

```yaml
version: 1                       # required, always 1

target:
  php: auto                      # auto | '7.4' … '8.5' — quote it: YAML reads 8.10 as 8.1
  metadata: auto                 # auto | attributes | annotations | none
  strict: true                   # true: a construct the target cannot express is an error

dto:
  mutability: immutable          # immutable | mutable
  accessors: auto                # auto | getters | public-properties
  dateTimeClass: DateTimeImmutable   # DateTimeImmutable | DateTime
  allOfStrategy: extends         # extends | merge

formats:                         # your own string formats → PHP classes
  money: { type: App\Money\Money }

attributeAliases:                # x- keys that expand into attributes
  x-audit: { class: App\Attr\Audited, args: { level: '{value}' } }

verifyClasses: auto              # auto | true | false
discoverExtensions: true         # load extensions declared by installed packages
extensions:                      # extension classes to load explicitly
  - App\Generator\MyExtension
extensionConfig:                 # one section per extension, by its name()
  symfony: { validator: auto }

sources:                         # required, at least one
  - spec: openapi/public.yaml
    namespace: App\Dto\Api
    outputDir: src/Dto/Api
    include: ['*']
    exclude: []
```

## `version`

Required; must be `1`.

## `target`

| Key | Default | Values |
|---|---|---|
| `php` | `auto` | `auto`, or a version from `7.4` to `8.5` |
| `metadata` | `auto` | `auto`, `attributes`, `annotations`, `none` |
| `strict` | `true` | `true`, `false` |

- **`php: auto`** takes the lowest version allowed by `require.php` of the nearest `composer.json` (next to the config
  or in a parent directory). Without one, or when it cannot be read, the target is `7.4`, with a warning in the latter
  case. A bound outside 7.4–8.5 gives a warning and the nearest supported version.
- **`metadata`** decides how attributes from `x-php-attributes`, aliases and extensions are written: PHP 8 attributes,
  Doctrine-style annotations in PHPDoc, or not at all. `auto` means `annotations` below PHP 8.0 and `attributes` from
  8.0 on. `attributes` on a 7.4 target is an error.
- **`strict`** applies when the output needs a language feature the target lacks, such as `new` in an attribute
  argument on PHP 8.0: `true` makes it an error, `false` a warning, and the attribute is left out.

## `dto`

| Key | Default | Values |
|---|---|---|
| `mutability` | `immutable` | `immutable`, `mutable` |
| `accessors` | `auto` | `auto`, `getters`, `public-properties` |
| `dateTimeClass` | `DateTimeImmutable` | `DateTimeImmutable`, `DateTime` |
| `allOfStrategy` | `extends` | `extends`, `merge` |

- **`mutability`.** Immutable DTOs have `with*()` methods returning a copy; mutable ones have setters. A schema can
  override it with `x-dto-mutable`.
- **`accessors`.** `auto` gives getters to immutable DTOs on 7.4 and 8.0 and public `readonly` properties from 8.1 on;
  mutable DTOs get getters and setters. `public-properties` on an immutable DTO needs PHP 8.1 (`readonly`) and is a
  config error below it. See [the class shapes](openapi-support.md#class-shape-per-target).
- **`dateTimeClass`** is the class for `format: date` and `format: date-time`.
- **`allOfStrategy`** is how `allOf` with one `$ref` plus own properties is generated: `extends` makes a subclass of
  the referenced class, `merge` copies its properties into one class. `allOf` with several `$ref` always merges. A
  schema can override it with `x-php-all-of`.

## `formats`

Maps a `format` value to a PHP class:

```yaml
formats:
  money: { type: App\Money\Money }
  uuid: { type: Symfony\Component\Uid\Uuid }   # replaces the built-in string mapping
```

A property with `format: money` gets the type `App\Money\Money`. Constructing that object from the wire value is the
job of your serializer. An unknown format gives the base type (`string`, `int`…) and a warning. Extensions can register
formats too; two extensions registering the same format is an error.

## `attributeAliases`

Short `x-` keys that expand into an attribute. See [aliases](x-extensions.md#aliases).

## `verifyClasses`

Whether the classes and constants that attributes name (from `x-php-attributes`, aliases and extensions) must exist,
checked through your project's autoloader. A missing one is an error at its place in the schema; classes generated in
the same run count as existing.

- `auto` (default): on when a `vendor/autoload.php` is found for the nearest `composer.json` above the config, unless
  the environment variable `DTO_GENERATOR_VERIFY_CLASSES` is `0`.
- `true` / `false`: always on / off, whatever the environment says. `true` without a `vendor/autoload.php` is an
  error.

Verification includes your autoloader and evaluates constant expressions, so it runs your project's code. The Docker
image sets `DTO_GENERATOR_VERIFY_CLASSES=0` for that reason.

## `extensions`, `discoverExtensions`, `extensionConfig`

- **`extensions`** — classes implementing `MSSTC4PHP\DtoGenerator\Contract\Extension`, loaded in this order.
- **`discoverExtensions`** (default `true`) — also load the extensions that installed packages declare in
  `extra.dto-generator.extensions` of their `composer.json`. Discovery sees the `vendor` directory the generator itself
  is installed in: a global installation or one in a separate `tools/` project does not see the project's packages.
- **`extensionConfig`** — a section per extension, keyed by the extension's `name()`, passed to it as is. The
  [Symfony bridge](https://github.com/msstc4symfony/dto-generator-bridge#configuration) reads `symfony`.

Order of application: the built-in `x-php-attributes` support, then `extensions` in config order, then discovered
extensions by package name. Attributes are written in that order.

## `sources`

| Key | Required | Meaning |
|---|---|---|
| `spec` | yes | The OpenAPI document (YAML or JSON) |
| `namespace` | yes | Namespace of the generated classes |
| `outputDir` | yes | Where the classes and the manifest go |
| `include` | no, `['*']` | Glob patterns over the names in `components/schemas` |
| `exclude` | no, `[]` | Glob patterns to leave out |

- A schema outside `include` is still generated when an included schema refers to it, since the property type needs
  it.
- With several sources, a `$ref` into a file that belongs to another source uses that source's namespace. A `$ref`
  into a file that belongs to no source puts the schema into the namespace of the source that refers to it. A schema
  reached from two sources without belonging to either is an error: its namespace would be ambiguous.
- `outputDir` must not be shared with files you write by hand: the generator deletes the classes whose schemas are
  gone.

## Environment variables

| Variable | Meaning |
|---|---|
| `DTO_GENERATOR_MEMORY_LIMIT` | `memory_limit` for the CLI, such as `512M`; by default a limit below 1G is raised to 1G |
| `DTO_GENERATOR_VERIFY_CLASSES` | `0` turns `verifyClasses: auto` off |
