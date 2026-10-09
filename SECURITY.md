# Security Policy

## Supported Versions

The package follows semantic versioning. Security fixes are released for the latest
minor on each supported major.

| Version | Supported          |
| ------- | ------------------ |
| 1.x     | :white_check_mark: |
| < 1.0   | :x:                |

## What the generator trusts

- **Specs and config are input, not code.** The generator parses them without
  executing anything; YAML aliases that would expand into a huge document are refused.
- **Your project's code runs only with `verifyClasses`.** Checking attribute classes
  includes the project's `vendor/autoload.php`. `verifyClasses: auto` turns it on when
  that file exists; set `DTO_GENERATOR_VERIFY_CLASSES=0` (the Docker image does) or
  `verifyClasses: false` when the project is not trusted.
- **Extensions are code.** Explicit `extensions` and those discovered from installed
  packages run inside the generator; `discoverExtensions: false` limits them to the
  listed ones.
- **Writes stay in `outputDir`.** Apart from a lock file per output directory in the
  system temporary directory, nothing outside the output directories is written;
  manifest entries that would leave them are refused, and a file without the
  `@generated` header is never overwritten.

## Reporting a Vulnerability

Please report vulnerabilities privately to maxim.shamaev@gmail.com rather than
opening a public issue. You will get an answer within a week.
