# CLAUDE.md

Guidance for Claude Code working in this repository. Deep references live
under `.claude/docs/`; this file stays lean.

## What this is

`msstc4php/dto-generator`: PHP DTO classes from OpenAPI 3.1 schemas, for target
PHP 7.4–8.5. Hexagonal layout (`Domain`, `Application`, `Infrastructure`,
`Presentation`, public SPI in `Contract`), enforced by `deptrac.yaml`. Design:
`docs/internal/specs/2026-10-01-dto-generator-design.md`; stage plans:
`docs/internal/plans/`. User documentation: `README.md` and `docs/*.md`.

The generator itself runs on **PHP 7.4**: no PHP 8 syntax or functions in `src/`
(PHPStan runs with `phpVersion: 70400`, `make check` lints on `php:7.4-cli`).

## Common commands

- `make install`, `make fix`, `make check`, `make test`, `make test-74`.
- `make verify` — the full gate; `make infection` — mutation testing (`minMsi` 99; keep it at 100%).
- `make test-targets` — the generated code on PHP 7.4 … 8.5 (Docker);
  `make docker-smoke` — the image; `make bc-check` — public API against the last tag.
- `UPDATE_SNAPSHOTS=1` regenerates golden files.

## Conventions

- Code comments in English, only the non-obvious "why".
- Every change: tests first, `make fix`, `make verify`, `make infection`.
- User-facing behaviour changes go into `CHANGELOG.md` and the docs in `docs/`.
- The public API is `Contract\*`, the model classes it exposes and
  `DtoGenerator`/`Generate`; `.roave-backward-compatibility-check.xml` lists it.
