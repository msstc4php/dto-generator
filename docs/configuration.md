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
  collections:                   # lists as collection objects instead of arrays; off without this key
    class: App\Collection\{item}List
    typeArguments: []            # [] | [item] | [int, item]
    typedCollection: none        # none | immutable | mutable

formats:                         # your own formats → PHP classes or scalars
  money: { type: App\Money\Money }

attributeAliases:                # x- keys that expand into attributes
  x-audit: { class: App\Attr\Audited, args: { level: '{value}' } }

verifyClasses: auto              # auto | true | false
discoverExtensions: true         # load extensions declared by installed packages; or { exclude: [acme/pkg] }
extensions:                      # extension classes to load explicitly
  - App\Generator\MyExtension
extensionConfig:                 # one section per extension, by its name()
  symfony: { validator: auto }

remoteRefs:                      # remote $refs, off unless allowed
  allow: [https://schemas.example.com/common/]
  cacheDir: .dto-generator/remote
  timeout: 10
  headers:                       # sent when fetching, over https only
    - { prefix: https://schemas.example.com/, name: Authorization, value: 'Bearer ${SCHEMAS_TOKEN}' }

sources:                         # required, at least one
  - spec: openapi/public.yaml
    namespace: App\Dto\Api
    outputDir: src/Dto/Api
    include: ['*']
    exclude: []
    classNames: {}               # '#/components/schemas/fields': FieldList
    propertyNames: {}            # '#/components/schemas/Fields/properties/issueType': issueTypeObject
    operations: false            # true, or { include: [...], exclude: [...] } over operationIds
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
| `collections` | none (lists are arrays) | see [`dto.collections`](#dtocollections) |

- **`mutability`.** Immutable DTOs have `with*()` methods returning a copy; mutable ones have setters. A schema can
  override it with `x-dto-mutable`.
- **`accessors`.** `auto` gives getters to immutable DTOs on 7.4 and 8.0 and public `readonly` properties from 8.1 on;
  mutable DTOs get getters and setters. `public-properties` on an immutable DTO needs PHP 8.1 (`readonly`) and is a
  config error below it. See [the class shapes](openapi-support.md#class-shape-per-target).
- **`dateTimeClass`** is the class for `format: date`, `format: date-time` (any
  [spelling](openapi-support.md#formats)) and `format: date-time-rfc-2822`.
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
  - `x-php-read-write: single | split` on an object schema overrides the setting for its class; inline objects
    follow the setting, not their owner. A `single` class ignores the `readOnly`/`writeOnly` of its properties but
    still gets both models when it holds, extends or lists a class that has them (a warning). With
    `allOfStrategy: merge`, a class follows its own setting, not that of the schemas whose properties it copies.
- **`readWriteSuffixes`**: what the two models add to the class name, for example `{read: Response, write: Request}`.
  The two must differ, letter case ignored; they also name the models of schemas with `x-php-read-write: split`. A
  model named like another class is an error.

### `dto.collections`

A property that is a list gets the type of a collection class instead of `array` + `@var list<T>`:

```yaml
dto:
  collections:
    class: App\Collection\{item}List
```

`tags: {type: array, items: {$ref: '#/components/schemas/Tag'}}` becomes `TagList $tags` (or `?TagList`), on every
target. The generated DTOs only declare the type; building the collection from the wire data is up to your
serializer (the Symfony bridge does it).

| Key | Default | Values |
|---|---|---|
| `class` | (required) | a class name, with `{item}` in its short name or without |
| `typeArguments` | `[]` with `{item}`, `[item]` without | `[]`, `[item]`, `[int, item]` |
| `typedCollection` | `none` | `none`, `immutable`, `mutable` |

- **`{item}`** stands for the short name of the item type: a class (`Tag`, `TagRead` in a read model), an enum from
  PHP 8.1 (before, the enum's `String` or `Integer`), or `Integer`, `Float`, `String`, `Boolean`. A list of anything
  else (a union, nullable items, nested lists) stays an array, with a warning; an untyped list (`{type: array}`)
  stays an array quietly. Without `{item}`, one class holds every list. `{item}` is a short name, so `App\Shop\Tag`
  and `App\Other\Tag` would share `TagList`: a class without `typeArguments` that two item types get, or that the
  run also generates from a schema, is an error.
- **`typeArguments`** is what the PHPDoc type passes the class: nothing for a class made for one item type
  (`TagList`), the item for a generic one (`ListOf<Tag>`), or `[int, item]` for Doctrine's
  `ArrayCollection<int, Tag>`. The arguments are written in `@phpstan-var` only, with the refinements of the item:
  `@var` names the bare class, because Symfony's PropertyInfo reads a generic iterable class there as a list and
  never builds the collection object.
- **`typedCollection`** writes `#[\MSSTC4PHP\DtoCollection\Attribute\TypedCollection(<class>, Variant::Immutable)]`
  (or `Mutable`) on every generated class a property lists through `class`, so that
  [dto-collection-generator](https://github.com/msstc4php/dto-collection-generator) builds the collection. It needs
  `{item}` in `class`, a PHP 8.1 target and attributes (`metadata: auto` or `attributes`); `verifyClasses` checks that
  dto-collection is installed. Enums carry no attributes,
  and scalar lists have no class to mark: list their collections in `dto-collection-generator.yaml`.
- Only a property's own list becomes a collection: lists inside a union, a map or another list stay arrays, and so do
  `additionalProperties`. [`x-php-collection`](x-extensions.md#x-php-collection) picks another class for one list, or
  keeps it an array.
- In an `allOf`, a member that only constrains the list (`minItems`, untyped items) keeps the other member's
  collection; two members that choose different classes for the same items, or one with `x-php-collection: false`, are
  an error.
- A collection object is no constant expression, so a `default` of the property gives `null`: `[]` silently, any
  other value with a warning.
- `with*()` copies the reference to the collection object: an immutable DTO wants an immutable collection.

## `formats`

Maps a `format` value to a PHP class, or names a format of your API known for `string`, `int`, `float` or `bool`
(`integer`, `number`, `boolean` as well, in any letter case):

```yaml
formats:
  money: { type: App\Money\Money }
  uuid: { type: Symfony\Component\Uid\Uuid }   # replaces the built-in string mapping
  ssh-key: { type: string }                      # a format of your API: no warning
```

A property with `format: money` gets the type `App\Money\Money`. A scalar changes no type: a string with
`format: ssh-key` keeps its `const`, `minLength` and the rest, only without the warning; the format on an integer
still warns.
Constructing an object from the wire value is the job of your serializer. A format is looked up as the document writes
it, then by the name the generator reads it as (see [formats](openapi-support.md#formats)): `uuid` covers `guid` and
`UUID` too, `ssh-key` covers `SSH_key`. A format the generator does not know gives the base type (`string`, `int`…)
and a warning. Extensions can register formats too; two extensions registering one format (in any spelling) is an
error, as are two spellings of one format here. A format here, a class or a scalar, wins over an extension's in any
spelling.

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
  An object turns discovery on and leaves out what its `exclude` lists: a package name (`acme/money-extension`, lower
  case as Composer writes it) leaves out every extension the package declares, a class (`Acme\Money\MoneyExtension`,
  in any case) one extension. An excluded extension is not loaded at all, so an extension that cannot be loaded or is
  named like another stops failing the run. An entry that leaves out nothing gives a warning. Listing a class in
  `extensions` still loads it; warnings about malformed declarations of an excluded package remain.

  ```yaml
  discoverExtensions:
    exclude: [acme/money-extension, Vendor\Audit\AuditExtension]
  ```
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
| `include` | no, `['*']` | Glob patterns over the names in `components/schemas` (`definitions` in Swagger 2.0) |
| `exclude` | no, `[]` | Glob patterns to leave out |
| `classNames` | no, `{}` | Class or enum names by schema: `'<$ref>': Name`, as `x-php-class-name` |
| `propertyNames` | no, `{}` | PHP property names by property schema: `'<$ref>': name`, as `x-php-name` |
| `operations` | no, `false` | Classes for the schemas of request bodies and responses: `true`, or `include`/`exclude` globs; see [below](#operations) |

- A schema outside `include` is still generated when an included schema refers to it, since the property type needs
  it.
- With several sources, a `$ref` into a file that belongs to another source uses that source's namespace. A `$ref`
  into a file that belongs to no source puts the schema into the namespace of the source that refers to it. A schema
  reached from two sources without belonging to either is an error: its namespace would be ambiguous.
- `outputDir` must not be shared with files you write by hand: the generator deletes the classes whose schemas are
  gone.
- `classNames` and `propertyNames` name what a document you do not change names twice, such as two schemas whose
  names differ in case only. A key is a `$ref` from the specification (`'#/components/schemas/fields'`, or into
  another file, `'common.json#/Type'`), which is where an error about the name points; it must be quoted, as YAML
  reads the rest of a line after `#` as a comment. The name is set as if the document had `x-php-class-name` or
  `x-php-name` there, over one it has, and is checked like one: an error about it points where the keyword would be in
  the document (`…/fields/x-php-class-name`). A key that names no schema the generator reads (a typo, a schema left
  out, or a place that holds no schema, such as a `default`) gives a warning, and two names for one schema an error.
  A schema only a keyword such as `not` or `if` holds gets no class, so a name for it is not used either.

  ```yaml
  sources:
    - spec: jira.json
      namespace: App\Jira
      outputDir: src/Jira
      classNames:
        '#/components/schemas/fields': FieldList           # Fields is another schema
      propertyNames:
        '#/components/schemas/Fields/properties/issueType': issueTypeObject   # beside "issuetype"
  ```

### `operations`

Off by default, so that a newer generator adds no classes by itself. `true` reads every operation; an object picks
them with glob patterns, `include` (default `['*']`) and `exclude` (default `[]`), over the operationId, or
`<method> <path>` for an operation without one: the method in lower case, the path as written (`get /pets/{petId}`).
`{}` (and `[]`, which YAML and JSON cannot tell from it) reads every operation too. The OpenAPI 3.2 `query` method and
the methods of `additionalOperations` (`link /pets/{petId}` for `LINK`) count as the others; an entry of
`additionalOperations` for a method that has its own field gives a warning and is not read.

```yaml
sources:
  - spec: github.yaml
    namespace: App\GitHub
    outputDir: src/GitHub
    operations: { exclude: ['checks/create', 'repos/get-content'] }
```

- A schema written in a request body or response gives a class or enum named after its `title`, as openapi-generator
  names it, or else after its place: `<operationId>Request` and `<operationId><status>Response` (`CreatePetRequest`,
  `CreatePet201Response`, `CreatePetDefaultResponse`). An array or a map of inline objects gives `…Item` and
  `…Value`, as a named alias does; a scalar gives nothing. `x-php-class-name` (or `classNames`) names it otherwise.
- A request body or response behind a `$ref` into `components/requestBodies` or `components/responses` of the same
  document is read there, once, when an operation that is read names it: `NewPetRequest`, `NotFoundResponse`. Any
  other `$ref`, and a path item behind a `$ref`, gives a warning and no class.
- The names give way as inline names do, and only after every class the components give has its name, so turning
  `operations` on renames none of those: a name a component holds goes to its title's place name, to the component's
  class when the two say the same, or else is numbered from 2, with a warning.
- Of a body's media types, the first JSON one (`application/json`, `…+json`, and the sequential `application/jsonl`,
  `application/x-ndjson`, `application/json-seq`, `…+json-seq`) is read, else the first that has a schema, even
  when it is behind a `$ref` that gives nothing. Without a `schema`, an `itemSchema` (OpenAPI 3.2, written for
  sequential media types such as `application/jsonl` or `text/event-stream`) gives the class of one item:
  `StreamLogs200ResponseItem`. A media type behind a `$ref` into `components/mediaTypes` is read there, once:
  `LogMediaType`, `LogMediaTypeItem`.
- A schema that only names another, a `$ref` or an `allOf` of one `$ref` with only annotations beside it
  (`title`, `description`, `example`, `examples`, `deprecated`, `externalDocs`, `$comment`, `nullable`, `readOnly`,
  `writeOnly`, `x-…`), gives no class: the other has one. A missing request body or response a `$ref` names gives a
  warning.
- An operation whose schema the generator cannot represent fails the run, as such a component does; leave it out with
  `exclude`.

## `remoteRefs`

| Key | Default | Values |
|---|---|---|
| `allow` | `[]` | URL prefixes (`http://` or `https://`, no query or fragment) |
| `cacheDir` | `.dto-generator/remote` | a directory, relative to the config file |
| `timeout` | `10` | seconds per document, 1 to 300 |
| `headers` | none | list of `{prefix, name, value}`, see below |

```yaml
remoteRefs:
  allow:
    - https://schemas.example.com/common/
```

A `$ref` to a URL (`https://schemas.example.com/common/v1/money.yaml#/Money`) is an error unless its URL starts with
one of the `allow` prefixes: a prefix that ends in `/` covers everything below it; any other one covers that URL and
everything below it plus `/`. Scheme and host are compared letter case ignored and default ports do not count, but a
host must be spelled as in `allow` (a trailing dot or a Unicode name instead of its punycode is refused). Escapes of
unreserved characters are decoded before the check, so `%2e%2e` is `..`; a path holding an encoded `/`, `\` or `%`,
`%00`, a `;` or bytes that are not UTF-8, a backslash, a space or another character a URL cannot hold is an error:
servers turn them into separators or dot segments. A relative `$ref` inside a remote document is resolved
against its URL and must be allowed too; another scheme (`file:`) is an error, so it can never reach a file of the
project.

- **Fetching.** A run that writes (`generate`) fetches a document it does not have yet with one HTTP/1.0 `GET`: TLS
  certificates are verified, redirects are not followed (a `3xx` is an error naming the new URL, which you then allow
  and refer to), TLS 1.2 or newer is required, headers may take 64 KB and the body 10 MB, a body shorter than its
  `Content-Length` or one sent in chunks is an error, the whole answer must arrive within `timeout` seconds
  (resolving the host name is not counted), and any status but `200` is an error. JSON or YAML is told by the
  extension of the URL, else by a YAML `Content-Type`, else by whether it starts with `{`.
- **Headers.** Each entry of `headers` adds a header to the fetch of every document under its `prefix`, which must be an
  `https://` URL: credentials never travel in clear text, and since redirects are not followed they never reach another
  host. `${NAME}` in `value` stands for the environment variable `NAME`, read only when a document is fetched, so
  `--check` and runs that find everything cached need no secret. Variables are looked up in `$_SERVER`, then `$_ENV`,
  then `getenv()`, so the values Symfony's Dotenv loads count too; a value set later with `putenv()` alone does not
  replace one already in `$_SERVER`. Keep secrets in the environment (a CI secret), never in the config, which you
  commit. A variable that is not set or is empty, or a value holding a control character other than a tab, is an error,
  and no value ever appears in a message or in the cache. A name starting with `HTTP_` is refused: under CGI and FPM the
  client of a request sets such variables from its headers. Of several entries with the same name (letter case ignored)
  for one URL, the one with the longest prefix wins; two entries with the same name and prefix are an error, and an
  entry whose prefix no `allow` prefix overlaps gets a warning, since it is never sent. `Host`, `Connection`,
  `Content-Length`, `Transfer-Encoding` and `Proxy-*` cannot be set; `Accept` and `User-Agent` replace the generator's
  own. For Basic authentication put `Basic <base64 of user:password>` into the variable.

  ```yaml
  remoteRefs:
    allow: [https://gitlab.example.com/api/v4/projects/12/repository/files/]
    headers:
      - prefix: https://gitlab.example.com/api/
        name: PRIVATE-TOKEN
        value: ${GITLAB_TOKEN}
  ```
- **Proxies.** Like curl, the generator takes `https_proxy` (or `HTTPS_PROXY`) for https URLs and only the lower-case
  `http_proxy` for http ones (`HTTP_PROXY` can come from a request header under CGI); unlike curl it ignores
  `all_proxy`/`ALL_PROXY`. A proxy is `http://[user[:password]@]host[:port]`, the scheme may be left out and the port
  defaults to 1080; an HTTPS or SOCKS proxy is an error for every fetch that would use it, so set `no_proxy=*` (or list
  the schema hosts in it) to fetch directly instead. https goes through a `CONNECT` tunnel, so TLS and the certificate
  check still run with the server itself and the proxy sees neither the request nor its headers; plain http is sent to
  the proxy. `no_proxy` (or `NO_PROXY`) lists hosts reached directly, separated by commas or spaces: a name also covers
  its subdomains (dots around it change nothing), `host:port` only that port, an IP address only itself however it is
  spelled, `*` every host; CIDR ranges are not supported. Messages name a proxy by host and port, never with its
  credentials.
- **Cache.** Fetched documents are kept in `cacheDir`, with `index.json` recording each URL, when it was fetched (UTC)
  and the SHA-256 of what came back. Later runs read the cache and never go to the network; a cached copy that no
  longer matches its SHA-256, a symbolic link, and an `index.json` that is not valid (say, after a merge conflict) are
  errors. Commit the directory: builds then need no network, and a change in a schema you depend on shows in review.
  The SHA-256 catches accidents, not someone who may commit both a file and its hash: review the directory like code.
  The empty `.index.lock` serializes runs that share the cache and need not be committed. To fetch the documents a run
  reads again, run `generate --refresh-remote`: it replaces their copies, which also repairs a broken one, and deletes
  the files no entry names any more. Entries of documents that no specification reads any longer stay until you
  delete them.
- **Checks.** `--check` and `--dry-run` read the cache only: a document that is not there is an error asking to run
  `generate`.
- Credentials in URLs, other schemes (`file:`, `ftp:`) and specifications given by URL in `sources` are not supported.

## Environment variables

| Variable | Meaning |
|---|---|
| `DTO_GENERATOR_MEMORY_LIMIT` | `memory_limit` for the CLI, such as `512M`; by default a limit below 1G is raised to 1G |
| `DTO_GENERATOR_VERIFY_CLASSES` | `0` turns `verifyClasses: auto` off |
| `DTO_GENERATOR_YAML_CACHE` | `0` turns the cache of decoded YAML off |

**Cache of decoded YAML.** symfony/yaml takes seconds on a large spec (GitHub's 10 MB one: about 2.3 s). A YAML document
of 16 KB or more is kept decoded in `$XDG_CACHE_HOME/dto-generator/yaml`, else `~/.cache/dto-generator/yaml` (each only
as an absolute path), by the SHA-256 of its text and of the code that reads it (the generator's decoder, all of
symfony/yaml, and the PHP version), so a later run reads an unchanged document in a hundredth of the time and a changed
reader never sees an old entry. The directories are created with mode 0700 on the first entry, under a directory of the
user's own, and used only while they are the user's own, no links, and closed to the group and others. There is no cache
on Windows, where modes say nothing of that. An entry unread for 30 days is deleted, and so are the least recently read
beyond 256 MB. Nothing is written into the project.
