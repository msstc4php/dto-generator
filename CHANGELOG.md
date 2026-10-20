# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), versions follow
[Semantic Versioning](https://semver.org/); dates are UTC.

## [Unreleased]

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

[Unreleased]: https://github.com/msstc4php/dto-generator/compare/v1.7.0...HEAD
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
