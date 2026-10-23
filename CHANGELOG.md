# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), versions follow
[Semantic Versioning](https://semver.org/); dates are UTC.

## [Unreleased]

### Changed

- Named schemas whose names differ only in letter case or separators (DocuSign's `notary` and `Notary`, SendGrid's
  `metadata` and `_metadata`), and places a `$ref` points into that end in the same name (Linode's
  `…/properties/replication_type`), no longer stop the generation: the later one is numbered from 2 with a warning,
  after every named schema holds its name. An explicit `x-php-class-name` and a read or write suffix still make a
  clash an error.
- Properties of one class that become the same PHP name (Trello's `prefs/background` and `prefs_background`) no longer
  stop the generation: the later one is numbered from 2 with a warning; an explicit `x-php-name` that clashes is still an
  error. A property name with no usable characters (SendGrid's `""`) leaves the property out with a warning, where it
  was an error.

### Fixed

- An `allOf` that makes a class beside a `oneOf` or `anyOf` without a discriminator (Shipengine) gives that class with a
  warning that the union is not represented, where the pair was an error.
- A schema that holds itself in a list or a map through `$ref` (Vercel's `EdgeConfigItemValue`, a JSON value) is typed
  with `mixed` inside, where it was an error that the `$ref` chain loops.
- A named `allOf` of one `$ref` to a class with a `discriminator` (Twitter's problems, Bitbucket's states) is a
  subclass that adds nothing, where it was another name of the class, so a `mapping` that names it failed. Without a
  `mapping`, every such schema is a subclass, a described copy of the base too; with one, only those it names.
- An `allOf` member that only says `type: object` beside another typed member (Asana, Bitbucket, Plaid) no longer
  makes the `allOf` an error that combines several typed schemas: it adds nothing, so
  `allOf: [$ref: Tag, {type: object}]` is `Tag`, without `null` unless the object member admits it too; beside a member
  that can be no object it stays an error.
- A variant of a discriminated union that declares a property of its parent again with a type PHP lets no subclass
  declare (Bitbucket's `team.links`), or one with no value in common, keeps the parent's property with a warning, where
  it was an error, in the read and the write model alike: unlike other classes, a variant cannot merge its members
  instead of extending.
- `x-enum-descriptions` as a list gives the descriptions by position, as openapi-generator reads it (Zoom), unless
  every position is also an enum value, at another position; it was an error for any enum but `0`, `1`, ….
- A `type` that admits none of the enum values (Zoom's and Vimeo's `type: array` with the values of its items) ignores
  the enum with a warning, where it was an error; the property keeps its type.
- A `default` that does not fit the type (Xero's `default: "false"` of a boolean) is a warning, where it was an error;
  the property defaults to `null`, as before.

## [1.13.0] - 2026-10-23

### Added

- Swagger 2.0 documents: each schema in `definitions` gives a class, read as an OpenAPI 3.0 schema; a string
  `discriminator` names the property, `x-discriminator-value` selects a subtype, `x-nullable: true` admits `null`,
  `type: file` is a string. Operations of a 2.0 document are not read.

## [1.12.0] - 2026-10-22

### Changed

- A class whose `allOf` member declares a property of its parent again with a type PHP lets no subclass declare (a
  class over an object, a list of `$ref` items over a list of objects, a date over a string), as the generic envelope
  of many APIs invites, merges its `allOf` instead of extending, as `x-php-all-of: merge` does, with a warning at the
  property, where it was an error. It is no longer a subtype of the parent; a property both declare takes the type
  both admit. A child type that adds nothing PHP holds leaves the parent's property, with a warning. Another name or
  default, types with no common values, a variant of a discriminated union and a class with `x-php-all-of` keep the
  error.
- A discriminated `oneOf`/`anyOf` beside `properties` whose inline variants only restrict those properties, one of
  them allowing several values of the discriminating property (GitHub's `checks/create` body), ignores the
  discriminator with a warning and gives a class of the properties, where the variant was an error.

## [1.11.0] - 2026-10-22

### Added

- OpenAPI 3.2 documents are read without a warning, as 3.1, whose schemas they share. A `$self` naming another URI
  than the document's location gives a warning, as references keep resolving against that location.
- `sources[].operations` reads what OpenAPI 3.2 adds to operations: the `query` method, `additionalOperations`
  (`link /pets` for `LINK` in the filter), the `itemSchema` of a media type without `schema` (as sequential ones
  such as `application/jsonl` or `text/event-stream` have), whose item gets `…Item`, and `components/mediaTypes`,
  named `<Name>MediaType`. The sequential JSON types (`application/jsonl`, `application/x-ndjson`,
  `application/json-seq`, `…+json-seq`) are preferred as JSON is.
- `discriminator.defaultMapping` (OpenAPI 3.2) names the variant for a missing or unmapped value: its constructor
  does not check the discriminator. `DiscriminatorModel::defaultClass()` and `defaultValue()` give it to extensions,
  and `Discriminator::defaultRef()` reads it in a schema.

### Fixed

- The Docker image builds again: 1.10.0 was published without one, as `roave/security-advisories`, a development
  dependency, now conflicts with the PHP versions the generator supports. `composer audit` still checks the
  dependencies in CI.

## [1.10.0] - 2026-10-22

### Added

- `sources[].operations`: the schemas written in request bodies and responses give classes, named as
  openapi-generator names them (their `title`, else `CreatePetRequest`, `CreatePet201Response`), and so do those of
  `components/requestBodies` and `components/responses` the operations name. Off by default; `true` reads every
  operation, and `include`/`exclude` globs over the operationId (or `<method> <path>`) pick them. Their names give
  way to every name the components give. With it, the GitHub specification gives 7377 classes instead of 5828 (two
  operations it cannot represent left out), OpenAI 4337 instead of 4319.

### Fixed

- Enum cases and properties that only a sign within the name tells apart (`Etc/GMT+1` and `Etc/GMT-1` of Stripe's
  timezones) get the sign spelled out, `ETC_GMT_PLUS_1` and `ETC_GMT_MINUS_1`, instead of an error that both become
  `ETC_GMT_1`. A sign between two letters still joins words, and names a leading sign told apart keep their names.

### Changed

- An inline enum whose name another enum of its file holds shares that enum when the two have the same cases, even
  in another order, with another description or with `null`: the members of an `allOf` that restate an enum no longer
  make a second, numbered one. A schema's own `title` no longer keeps it from sharing the class of a schema that says
  the same, and the schema holding the name derived from its place can lend it its class too. Of the GitHub
  specification's 5 name warnings, 1 is left, for two enums with different values.
- The warning about a `default: {}` that cannot be `new Name()` because the class requires properties names all of
  them as the document does: `it lacks "currentNumberScheduled", which the constructor requires`. Such a default
  breaks the schema's own `required` (the 5 left in the Kubernetes specification), so `null` stays.

## [1.9.0] - 2026-10-21

### Added

- On PHP 8.1 and newer, a `default: {}` of a property whose type is a generated class is `new Name()` in the
  constructor, instead of `null` with a warning, when the class is not abstract and takes no required argument. Of
  the 31 such warnings of the Kubernetes specification, 5 are left, for an `{}` that lacks a required property.

### Changed

- An inline schema whose `title` names a class another schema holds takes the name derived from its place without a
  warning, as it would without the title; only a name numbered from 2 still warns. Of the GitHub specification's
  name warnings 5 are left, of the OpenAI one's none. Class names do not change.

## [1.8.1] - 2026-10-21

### Fixed

- A line starting with "?" after two or more lines of a multi-line plain scalar is no longer taken for a key written
  after "?": with symfony/yaml 6 and newer such a document was rejected with an error about the rewritten text instead
  of the original one.

## [1.8.0] - 2026-10-20

### Added

- An `allOf` with one member that is a `oneOf`/`anyOf` of objects without a discriminator, written there or behind a
  `$ref`, is the union of a class per variant with the rest of the `allOf` (`<Schema><Variant>`, extending the variant
  with the `extends` strategy), instead of an error. The OpenAI specification now generates whole, without `exclude`.
- `discoverExtensions: {exclude: [...]}` turns discovery on but leaves out the extensions of the packages it lists, or
  single extension classes, without loading them; an entry that leaves out nothing gives a warning.
- `classNames` and `propertyNames` of a source set `x-php-class-name` and `x-php-name` on the schemas their keys
  reference (`'#/components/schemas/fields': FieldList`), so a document you cannot change that gives two schemas or
  properties one PHP name (Jira: the schemas `fields` and `Fields`, the properties `issuetype` and `issueType`) can
  still be generated; a key that names no schema read gives a warning. The errors about such names point to them.
- OpenAPI 3.0 documents (and the documents they refer to that state no version) are read with the keywords 3.0 writes
  otherwise: `nullable: true` admits `null` beside a `type`, a `$ref`, an `allOf`, a `oneOf` or an `anyOf`, instead
  of a warning that it has no effect, and a boolean `exclusiveMinimum`/`exclusiveMaximum` makes the `minimum`/`maximum`
  beside it exclusive (`minimum: 0` and `maximum: 10`, both exclusive, give `int<1, 9>`), instead of being ignored. Such a document
  gives no warning about its version any more. A document without a version that 3.0 and 3.1 documents both refer to
  gives a warning. Stripe, Twilio and Jira lose hundreds of warnings, and their nullable properties are nullable.

- An inline schema whose name another schema already holds gets that schema's class or enum when the two say the
  same in one file, subschemas included, instead of a numbered copy (`…2`, `…Option1`) and a warning, or an error
  when both set the same `x-php-class-name`. OpenAI's `Beta` copies and GitHub's repeated webhook schemas lose such
  duplicates. A class an `allOf` gives the properties of other schemas is shared with none.

- `$ref` to an anchor (`#Pet`, `common.yaml#Pet`) resolves to the schema of that document with `$anchor: Pet` (or
  `$dynamicAnchor`), local or remote, instead of an error; an anchor no schema or several declare is an error.

### Changed

- A property two `allOf` members give two classes is of the class that extends the other (`allOf: [$ref: Wide, …]`
  with the `extends` strategy, also through other classes), instead of an error. A class merged from `Wide` does not
  count: it keeps nothing of `Wide` but its properties. With a read or write model view the classes do not meet.
- A variant of a discriminated `oneOf`/`anyOf` that the `mapping` leaves out is selected by the one value its
  discriminator property allows (`const` or a one-value `enum`, also beside a `$ref`, in a one-member `allOf` around
  it or in every branch of a `oneOf`/`anyOf` around it but `null`), when nothing else claims the value (the mapping,
  another variant's value or schema name, an inline variant's value), instead of by its schema name, which such a
  property never holds; a subclass that inherits the value of another variant keeps its schema name. Such unions
  without a mapping (the OpenAI specification has hundreds of variants like that) select their variants instead of
  none.
- The CLI shows a warning that recurs word for word once, at its first place, with how many more there are (`-v`
  lists them): an unknown `format` gave hundreds of lines for Stripe, Twilio and OpenAI. The JSON report is unchanged.
- An `enum` of booleans (`deleted: {type: boolean, enum: [true]}`, as in Stripe) is a `bool`, `true` or `false` with
  one value, instead of a warning that the enum has no string or integer value.

### Fixed

- A class that a class of only the read or only the write model extends (an `allOf` of it and more properties in a
  `readOnly` or `writeOnly` property) is generated open, in both models, instead of an error that the two builds
  disagree on it. Being open, its properties are `protected` and, on PHP 8.1–8.4, it has no `withX()`.
- A YAML document with keys written after `?` (Twilio writes its long schema names so) is read: a key that is a plain
  or quoted scalar is taken as such; symfony/yaml rejected the whole document.
- A class that has all the properties of a variant of a discriminated union no longer meets it in an `allOf`: the
  variant accepts only the discriminator values that select it, which its schema does not tell.

## [1.7.0] - 2026-10-16

### Changed

- A property two `allOf` members give two classes is of the class that has all the other's properties, with the same
  schemas, requires the ones it requires, admits `null` only if the other does and may have more, when the other says
  nothing but its properties, which it requires and annotations, instead of an error. Keys of JSON objects in the
  property schemas (their keywords, defaults and enum values) may come in any order; a schema without a type does not
  narrow one of type object. Schemas reached through a `$ref` count only within one file; with a read or write model
  view the classes do not meet.
- A child that declares its parent's enum property again with an enum sharing only some of its values leaves the
  property to the parent, with a warning that it shares values with it, instead of an error; the Symfony bridge checks
  the values both allow.
- An `allOf` member that is a `oneOf`/`anyOf` reports that no class adds properties to each variant.

### Security

- `symfony/yaml` is required in a release without CVE-2026-109413 (unbounded recursion in the inline lexer):
  `^5.4.54 || ^6.4.45 || ^7.4.18 || ^8.1.6`. Releases 7.0–7.3 and 8.0 are no longer accepted.

Found by generating the OpenAI specification, which now generates with its two WebSocket event unions left out.

## [1.6.0] - 2026-10-15

### Added

- `$ref`s in the raw schemas of keywords (`not`, `if`, `then`, `else`, `contains`, `propertyNames`, `prefixItems`,
  `patternProperties`, `dependentSchemas`, `unevaluatedItems`…) are loaded, after every other reference, and
  `SchemaReferences::follow()` resolves them for extensions. A schema only they reach gets no class of its own and its
  `x-` keys are not checked; one that cannot be loaded or is no schema object (`false`) gives a warning instead of an
  error and stays out, as only an extension reads it; an error anywhere in it, a nested property too, leaves out the
  whole schema. A remote one under `remoteRefs.allow` is fetched and cached like
  any other.
- `SchemaReferences::at()`: the schema loaded at a location, such as the schema of an ancestor.
- A discriminated `oneOf`/`anyOf` with no properties of its own, a variant of which already extends another class
  (through its own `allOf` or as the variant of another union), is an empty `interface` the variants implement,
  instead of an error: PHP gives a class one parent but any number of interfaces. A union that has properties, that a
  class extends through `allOf`, or that is a variant of a union that stays a class, stays a class. Inner unions are
  decided before the unions that list them; of unions that could each take a shared variant, one that stays a class
  takes it, else the first by class name; the order of the schemas plays no part. An interface variant of such a union
  extends it. The variants' constructors check the interface's discriminator values, and have no mutators for the
  property, as for an abstract base; a class that several unions map with different values accepts each. Extensions
  see `ClassKind::INTERFACE` (a new value of `ClassKind`) and `ClassModel::interfaces()`; the interface carries the
  discriminator.
- Inline object variants of a discriminated `oneOf`/`anyOf` are classes extending the base (their title, or
  `<Base>Option<N>`), selected by the one value their discriminator property has (`const` or a one-value `enum`, behind
  a `$ref` or in a member of their `allOf`), instead of an error. An inline union (a property's schema) with such
  variants is a class named after its place. Two inline variants with one value are an error.

### Changed

- A class that two discriminated ancestors map with different values (`cat` in `Animal`'s mapping, `Cat` in
  `Feline`'s) accepts both in its constructor, where it refused the farther one that deserializing through that
  ancestor gives; with two values its discriminator property has no default any more. A mapping that names a base in
  between still leaves the check to that base's own discriminator.

Found by generating the GitHub, OpenAI and Stripe specifications (GitHub now generates 5842 classes):

- A property several `allOf` members describe with different types gets the narrower one, when one admits every value
  of the other, instead of an error; inline objects of it (or of its items) in several members become one class when
  nothing else tells them apart. Inline classes and enums set aside this way are not generated.
- A child's schema that declares a property of its parent again and only narrows its values (null, a refinement of the
  same scalar, the cases of an enum) leaves it to the parent, with a warning, instead of an error.
- An inline schema whose class name another schema holds takes the name derived from its place, or that name
  numbered from 2, with a warning, instead of an error.
- The default of a union property that an enum member and a scalar member both admit stays a literal; one only an
  enum admits (next to a class or another scalar) is the enum case, where the literal was invalid PHP.
- Property names and enum values that only a leading sign tells apart (`+1` and `-1`), or a sign alone (`*`), are
  spelled out (`plus1`, `minus1`, `ASTERISK`) instead of an error.

## [1.5.0] - 2026-10-14

### Added

- `ClassContext::ancestors()`: the classes a class extends, nearest first, with their own properties, so an extension
  can name inherited properties (the Symfony bridge checks conditions and requirements on them).

### Fixed

- A list of values of any type is documented as `array<int, mixed>` in `@var` and `@param`, and as `list<mixed>` in
  `@phpstan-var` and `@phpstan-param`: Symfony PropertyInfo reads `list<mixed>` as a class named `list` (5.4)
  or throws a `TypeError` on `?list<mixed>` (8.1), so Symfony Serializer could not denormalize a DTO with a
  `type: array` without `items`.

## [1.4.1] - 2026-10-13

### Fixed

- The Docker image clones the bridge and its runtime with git instead of reading them through the GitHub API, whose
  anonymous rate limit failed the publication of 1.4.0.

## [1.4.0] - 2026-10-13

### Added

- `remoteRefs.headers` sends headers with the fetch of the remote documents under an `https://` prefix, such as a
  token for a private GitLab or Artifactory; `${NAME}` in a value reads the environment variable `NAME` only when a
  document is fetched, so secrets stay out of the config and checks need none.
- `generate --refresh-remote` (and `Generate\Input::withRefreshedRemote()`) fetches every remote document the run
  reads again, replacing its cached copy; a check or a dry run cannot refresh.
- `x-php-read-write: single | split` on an object schema overrides `dto.readWriteModels` for its class: one schema
  can get read and write models while the rest keep one, or keep one model in a split project.
- Every release tag publishes the Docker image to `ghcr.io/msstc4php/dto-generator` for `linux/amd64` and
  `linux/arm64`, tagged `X.Y.Z`, and `X.Y`, `X`, `latest` when it is the newest of their line.
- `ExtensionRegistry::claimKeywords()`: an extension that checks a keyword the generator calls unsupported (such as
  `contains`) claims it and marks each occurrence it checks, or warns about, in `keywordChecks()` of its
  `PropertyContext`/`ClassContext` (`Domain\Diagnostic\KeywordChecks`); the generator warns about the occurrences
  left unmarked (`unchecked()`, each a `ClaimedKeyword` with `keywordLocation()`), as before, after the other
  diagnostics. A test double implementing `ExtensionRegistry` needs the new method. The contract of `ClassEnricher` says that a class is enriched before its properties.

### Changed

- Remote documents are fetched through the proxy of `https_proxy`/`HTTPS_PROXY` (https) or `http_proxy` (http),
  except for the hosts of `no_proxy`/`NO_PROXY`, as curl does (`all_proxy` is not read); they used to be fetched
  directly whatever the environment said. Only `http://` proxies are supported: where these variables name an HTTPS
  or SOCKS proxy, fetches now fail, and `no_proxy=*` restores direct fetching.
- The errors about a broken cached copy of a remote document suggest `generate --refresh-remote` instead of editing
  `index.json`.
- `dto.readWriteSuffixes` without `readWriteModels: split` no longer gives a warning: it names the models of the
  schemas with `x-php-read-write: split`.

## [1.3.1] - 2026-10-12

### Fixed

- The Docker image knows the repository of `msstc4symfony/dto-generator-bridge-runtime`, which the bridge requires
  from 1.2 on; without it Composer settled for an older bridge, or failed with a local bridge checkout.

## [1.3.0] - 2026-10-12

### Added

- `dto.readWriteModels: split` gives every class whose model depends on `readOnly`/`writeOnly` a read model (no
  `writeOnly` properties) and a write model (no `readOnly` ones), named with `dto.readWriteSuffixes` (`Read`/`Write`
  by default). A class depends on them through its own properties (the keyword on the property or along its `$ref`
  chain) and through the classes it holds, extends or lists as discriminated variants; the rest stay shared.
  `single`, the default, keeps the previous output.
- `remoteRefs.allow` lets a `$ref` name an `http(s)` URL under one of its prefixes, including relative `$ref`s inside
  a remote document. A run that writes fetches each document once into `remoteRefs.cacheDir`
  (`.dto-generator/remote`, with an `index.json` of URLs, fetch times and SHA-256 sums); later runs, `--check` and
  `--dry-run` read the cache only. No redirects, verified TLS, 64 KB of headers, 10 MB of body,
  `remoteRefs.timeout` seconds for the whole answer. Escaped dot segments count as dots; an encoded `/` or `\` and
  characters a URL cannot hold are refused; an invalid `index.json` stops the run.

- `Generate\Input::withOutputRoot()` writes every source's output directory under one root, at its path relative to
  the config's directory, and `withoutFetching()` reads remote documents from the cache only, also when the run
  writes. `Generate\Output::classes()` maps each generated class to its file, `documents()` lists the config and the local
  documents the run read and `remoteCache()` the cache directory of remote ones, for a framework that builds the DTOs
  on deploy.
  `GeneratedFile` names the class it declares (`className()`). `Path::within()` tells where a path lies in a directory.
- `Path::resolve()` against a root (`/`, `C:/`) no longer doubles the slash, which read as a UNC share.
- `generate --output-root=DIR` does the same from the command line; a relative `DIR` is resolved against the working
  directory.

### Changed

- A remote `$ref` outside `remoteRefs.allow` reports what to allow ("… which remoteRefs.allow does not cover") instead
  of "is not supported". `Domain\Schema\Reference::target()` returns the location of a URL too, never `null`.


## [1.2.0] - 2026-10-09

### Added

- `DtoGenerator::prepareProcess()` gives a console command that wraps the generator the CLI's process settings:
  `memory_limit`, errors on stderr, exit code `2` on a fatal error.
- The public API (the extension SPI, the model classes it hands out and the entry points) carries `@api`; a test keeps
  the tag and the backward-compatibility check in step.
- Inline objects and enums in a `oneOf`/`anyOf` become classes: named after the member's `title`, else
  `<Parent><Property>Option<N>`. Those in the `items`, `additionalProperties` or union members of a named alias
  become `<Alias>Item`, `<Alias>Value`, `<Alias>Option<N>`. Both used to be errors.
- `const` gives the property its type with a PHPDoc literal (`@phpstan-var 'card'`) instead of `mixed`; a `const`
  member of `allOf` narrows the type of the rest. A `default` other than the `const` (or outside a mixed enum) is an
  error, also through `$ref` and `allOf`.
- `x-enum-varnames` names the enum cases.
- A warning for keywords no generated type expresses (`prefixItems`, `patternProperties`, `if`/`then`/`else`,
  `not`…) and for OpenAPI 3.0 `nullable: true`.
- `dto.withers: false` leaves the `with*()` methods out of immutable DTOs; with `mutability: mutable` it gives a
  warning, as setters stay.

### Changed

- An `enum` mixing strings and integers gives a union of its literals (`'low'|1`) with a warning instead of an error.
- The constructor of a discriminated variant checks the discriminator: a value that does not select the class throws
  `\InvalidArgumentException`. A class selected by a single value takes it as the default, and the parameter moves
  behind the required ones. An open class selected by the mapping accepts its own values when it is instantiated
  itself and its subclasses' values only from them. No class in a discriminated chain (the discriminated bases,
  their ancestors and their subclasses) has a `withX()`/`setX()` for a property a discriminator of that chain reads.
  The rule is per property: a subclass whose own chain does not read it, while its parent's does (a sibling `allOf`
  subclass of a shared base, or a base with a different discriminator), gets the mutators back. It declares the
  setter and, on 7.4, 8.0 and 8.5, the cloning wither itself; its subclasses inherit them. On 8.1–8.4 an open class
  has no withers, so its final subclasses declare the `new self` wither; with `dto.withers: false`, or mutable DTOs with
  `accessors: public-properties`, there is none to restore. Only string, integer and enum discriminators are checked; another
  type, an untyped one included, gives a warning:

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

  Positional calls change: `new Cat('cat', 'Tom')` becomes `new Cat('Tom')`; named arguments are unaffected.
  An optional nullable discriminator selected by several values, none of them its default, loses `= null` and
  becomes required: callers that omit it get an `ArgumentCountError`. Symfony's Serializer denormalizing through
  the base and its `DiscriminatorMap` is unaffected, but denormalizing straight into a variant with another
  class's value now throws `\InvalidArgumentException` from the constructor, which Symfony does not turn into a
  validation error (it only catches `TypeError` there): `#[MapRequestPayload] Cat $cat` with `"petType": "dog"`
  answers 500 instead of accepting it. A checked nullable discriminator declared by a concrete class (a variant
  or an open class) gets a non-nullable property and getter on PHP 7.4 (`string`), while PHP 8.0+ keeps `?string`;
  a rejected `null` reads `null` in the message.

## [1.1.1] - 2026-10-09

### Fixed

- `make docker-build` with a checkout of the Symfony bridge works again: the image reports the latest tag as its
  version (`VERSION` defaults to `1.1.0`), which satisfies the bridge's `^1.1` requirement.

### Changed

- Raised the lowest dependency versions to releases without known security advisories: `nikic/php-parser` ^5.1,
  `symfony/yaml` ^5.4.52 on the 5.4 line.

### Documentation

- README rewritten in English, with installation, requirements, a quick start and the full CLI reference.
- New reference pages: [configuration](docs/configuration.md), [`x-` keywords](docs/x-extensions.md),
  [OpenAPI support](docs/openapi-support.md) and [writing an extension](docs/extensions-spi.md).
- `SECURITY.md` and this changelog.

### Internal

- CI checks the public API against the latest release (Roave BC check) and runs `composer audit`; tooling aligned with
  the Symfony bundles (deptrac 4.6, Infection 0.32).

## [1.1.0] - 2026-10-08

### Added

- `ClassContext::selectingDiscriminator()` gives class enrichers the discriminator of the nearest ancestor whose
  mapping selects the class, or `null`, so an extension can tell which discriminator values name a variant.
- `PropertyModel::isAdditionalProperties()` marks the `$additionalProperties` property that collects the keys a
  schema does not declare; the Symfony bridge 1.0.1 uses it.

## [1.0.2] - 2026-10-07

### Fixed

- YAML aliases that expand into a huge document (a "billion laughs" file) are refused instead of exhausting memory.
- The CLI raises a `memory_limit` below 1G to 1G; `DTO_GENERATOR_MEMORY_LIMIT` sets another one, with a warning when
  it is not a valid limit.
- A PHP fatal error, out-of-memory included, ends the CLI with exit code `2` instead of `255` (PHP 7.4 keeps `255`),
  and PHP error output goes to stderr, keeping `--format=json` output parseable.
- A file counts as generated only when the `@generated` header comes right after `<?php`, not anywhere in it; a
  file with something above the header is a conflict instead of being overwritten.
- A stale generated file is deleted only when it still has the header and its content matches the manifest; a file
  edited by hand is reported as a conflict.
- The Composer plugin skips generation, with a message, when the generator has been uninstalled in the same
  Composer run.

## [1.0.1] - 2026-10-07

### Fixed

- Raised the lowest supported `symfony/console` (^5.4.47) and `symfony/yaml` (^5.4.45) to releases that pass the
  test suite on every supported PHP version.
- The Docker image installs the Symfony bridge from its actual repository.

## [1.0.0] - 2026-10-06

First release.

- PHP DTO classes from the `components/schemas` of OpenAPI 3.1 documents, for target PHP versions 7.4 to 8.5.
- Immutable or mutable DTOs, getters or public properties, enums or constant classes depending on the target.
- Objects, arrays, maps, enums, nullable and union types, defaults, formats, internal and cross-file `$ref`, `allOf`
  as inheritance or merge, `oneOf`/`anyOf` with or without a discriminator, inline schemas.
- `x-php-*` and `x-dto-*` keywords, `x-php-attributes`, attribute aliases, PHP 8 attributes or Doctrine-style
  annotations.
- An extension SPI with discovery through `extra.dto-generator.extensions`; extensions see the project's locked
  package versions.
- A CLI with `--check`, `--dry-run` and a JSON report; a Composer plugin; a Docker image with the Symfony bridge.
- A manifest per output directory: stale classes are deleted, files without the `@generated` header are never
  overwritten.

[Unreleased]: https://github.com/msstc4php/dto-generator/compare/v1.13.0...HEAD
[1.13.0]: https://github.com/msstc4php/dto-generator/compare/v1.12.0...v1.13.0
[1.12.0]: https://github.com/msstc4php/dto-generator/compare/v1.11.0...v1.12.0
[1.11.0]: https://github.com/msstc4php/dto-generator/compare/v1.10.0...v1.11.0
[1.10.0]: https://github.com/msstc4php/dto-generator/compare/v1.9.0...v1.10.0
[1.9.0]: https://github.com/msstc4php/dto-generator/compare/v1.8.1...v1.9.0
[1.8.1]: https://github.com/msstc4php/dto-generator/compare/v1.8.0...v1.8.1
[1.8.0]: https://github.com/msstc4php/dto-generator/compare/v1.7.0...v1.8.0
[1.7.0]: https://github.com/msstc4php/dto-generator/compare/v1.6.0...v1.7.0
[1.6.0]: https://github.com/msstc4php/dto-generator/compare/v1.5.0...v1.6.0
[1.5.0]: https://github.com/msstc4php/dto-generator/compare/v1.4.1...v1.5.0
[1.4.1]: https://github.com/msstc4php/dto-generator/compare/v1.4.0...v1.4.1
[1.4.0]: https://github.com/msstc4php/dto-generator/compare/v1.3.1...v1.4.0
[1.3.1]: https://github.com/msstc4php/dto-generator/compare/v1.3.0...v1.3.1
[1.3.0]: https://github.com/msstc4php/dto-generator/compare/v1.2.0...v1.3.0
[1.2.0]: https://github.com/msstc4php/dto-generator/compare/v1.1.1...v1.2.0
[1.1.1]: https://github.com/msstc4php/dto-generator/compare/v1.1.0...v1.1.1
[1.1.0]: https://github.com/msstc4php/dto-generator/compare/v1.0.2...v1.1.0
[1.0.2]: https://github.com/msstc4php/dto-generator/compare/v1.0.1...v1.0.2
[1.0.1]: https://github.com/msstc4php/dto-generator/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/msstc4php/dto-generator/releases/tag/v1.0.0
