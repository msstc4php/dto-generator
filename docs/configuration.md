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
  withers: true                  # false: immutable DTOs get no with*()
  readWriteModels: single        # single | split — read and write models for readOnly/writeOnly
  readWriteSuffixes: { read: Read, write: Write }

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

remoteRefs:                      # remote $refs, off unless allowed
  allow: [https://schemas.example.com/common/]
  cacheDir: .dto-generator/remote
  timeout: 10

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
| `withers` | `true` | `true`, `false` |
| `readWriteModels` | `single` | `single`, `split` |
| `readWriteSuffixes` | `{read: Read, write: Write}` | two different PHP identifiers |

- **`mutability`.** Immutable DTOs have `with*()` methods returning a copy; mutable ones have setters. A schema can
  override it with `x-dto-mutable`.
- **`accessors`.** `auto` gives getters to immutable DTOs on 7.4 and 8.0 and public `readonly` properties from 8.1 on;
  mutable DTOs get getters and setters. `public-properties` on an immutable DTO needs PHP 8.1 (`readonly`) and is a
  config error below it. See [the class shapes](openapi-support.md#class-shape-per-target).
- **`dateTimeClass`** is the class for `format: date` and `format: date-time`.
- **`allOfStrategy`** is how `allOf` with one `$ref` plus own properties is generated: `extends` makes a subclass of
  the referenced class, `merge` copies its properties into one class. `allOf` with several `$ref` always merges. A
  schema can override it with `x-php-all-of`.
- **`withers`**: `false` leaves the `with*()` methods out of immutable DTOs (half of the output on a large spec);
  setters of mutable DTOs stay. With `mutability: mutable` it only affects schemas made immutable with
  `x-dto-mutable: false`, and the config gets a warning.
- **`readWriteModels`**: `single` gives one class per schema and ignores `readOnly`/`writeOnly`. `split` gives a
  class whose model depends on them two: a **read** model (`PetRead`, for responses: without `writeOnly` properties)
  and a **write** model (`PetWrite`, for requests: without `readOnly` ones). A class depends on them when it has a
  `readOnly`/`writeOnly` property (on the property or anywhere along its `$ref` chain, in any member of its `allOf`),
  or holds, extends or lists as a discriminated variant a class that does — so the variants and subclasses of such a
  base are split too, even when their own properties are the same; every other class stays one shared class. The
  suffix also goes after an `x-php-class-name`, and inline classes keep their base name before it (`PetOwnerRead`).
  Switching to `split` renames the dependent classes; the writer deletes the old files.
  - A property marked `readOnly` and `writeOnly`, also in two `allOf` members, is in both models, with a warning.
  - Only properties are directed: `readOnly` on the `items` of an array leaves the item in both models.
  - Do not mark a discriminator property `readOnly`: the write model would lose it, and a request could not select
    its variant.
  - A problem of a split class is reported once, naming its read model.
- **`readWriteSuffixes`**: what the two models add to the class name, for example `{read: Response, write: Request}`.
  The two must differ, letter case ignored; without `readWriteModels: split` they have no effect (a warning). A model
  named like another class is an error.

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

## `remoteRefs`

| Key | Default | Values |
|---|---|---|
| `allow` | `[]` | URL prefixes (`http://` or `https://`, no query or fragment) |
| `cacheDir` | `.dto-generator/remote` | a directory, relative to the config file |
| `timeout` | `10` | seconds per document, 1 to 300 |

```yaml
remoteRefs:
  allow:
    - https://schemas.example.com/common/
```

A `$ref` to a URL (`https://schemas.example.com/common/v1/money.yaml#/Money`) is an error unless its URL starts with
one of the `allow` prefixes; a prefix that ends in `/` covers everything below it, any other one that document. Scheme
and host are compared letter case ignored, and default ports do not count. A relative `$ref` inside a remote document
is resolved against its URL and must be allowed too; it can never reach a file of the project.

- **Fetching.** A run that writes (`generate`) fetches a document it does not have yet with one `GET`: TLS
  certificates are verified, redirects are not followed (a `3xx` is an error naming the new URL, which you then allow
  and refer to), the body may be 10 MB at most, and any status but `200` is an error. JSON or YAML is told by the
  extension of the URL, else by its `Content-Type`, else by its first character.
- **Cache.** Fetched documents are kept in `cacheDir`, with `index.json` recording each URL, when it was fetched (UTC)
  and the SHA-256 of what came back. Later runs read the cache and never go to the network; a cached copy that no
  longer matches its SHA-256 is an error. Commit the directory: builds then need no network, and a change in a schema
  you depend on shows in review. To fetch a document again, delete its file and its entry in `index.json` (or the
  whole directory).
- **Checks.** `--check` and `--dry-run` read the cache only: a document that is not there is an error asking to run
  `generate`.
- Credentials in URLs, other schemes (`file:`, `ftp:`) and specifications given by URL in `sources` are not supported.

## Environment variables

| Variable | Meaning |
|---|---|
| `DTO_GENERATOR_MEMORY_LIMIT` | `memory_limit` for the CLI, such as `512M`; by default a limit below 1G is raised to 1G |
| `DTO_GENERATOR_VERIFY_CLASSES` | `0` turns `verifyClasses: auto` off |
