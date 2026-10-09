# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), versions follow
[Semantic Versioning](https://semver.org/); dates are UTC.

## [Unreleased]

### Added

- Inline objects and enums in a `oneOf`/`anyOf` become classes: named after the member's `title`, else
  `<Parent><Property>Option<N>`. Those in the `items`, `additionalProperties` or union members of a named alias
  become `<Alias>Item`, `<Alias>Value`, `<Alias>Option<N>`. Both used to be errors.
- `const` gives the property its type with a PHPDoc literal (`@phpstan-var 'card'`) instead of `mixed`; a `const`
  member of `allOf` narrows the type of the rest. A `default` other than the `const` (or outside a mixed enum) is an
  error, also through `$ref` and `allOf`.
- `x-enum-varnames` names the enum cases.
- A warning for keywords no generated type expresses (`prefixItems`, `patternProperties`, `if`/`then`/`else`,
  `not`…) and for OpenAPI 3.0 `nullable: true`.

### Changed

- An `enum` mixing strings and integers gives a union of its literals (`'low'|1`) with a warning instead of an error.

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

[Unreleased]: https://github.com/msstc4php/dto-generator/compare/v1.1.1...HEAD
[1.1.1]: https://github.com/msstc4php/dto-generator/compare/v1.1.0...v1.1.1
[1.1.0]: https://github.com/msstc4php/dto-generator/compare/v1.0.2...v1.1.0
[1.0.2]: https://github.com/msstc4php/dto-generator/compare/v1.0.1...v1.0.2
[1.0.1]: https://github.com/msstc4php/dto-generator/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/msstc4php/dto-generator/releases/tag/v1.0.0
