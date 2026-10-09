# Writing an extension

An extension adds attributes to the generated classes and properties, and PHP types for string formats. The
[Symfony bridge](https://github.com/msstc4symfony/dto-generator-bridge) is one; this page shows how to write your own.

Extensions run inside the generator, so their code must run on **PHP 7.4**, the generator's minimum. Use only the
public API: the `MSSTC4PHP\DtoGenerator\Contract` namespace, the model classes it hands out (`Domain\Model`,
`Domain\Schema`, `Domain\Diagnostic`, `Domain\Target`), the helpers in `Domain\Shared` (such as `Json`) and the
exceptions in `Domain\Exception`. Releases are checked for backward compatibility on these; the rest of the package is
internal and may change in any release.

## The interfaces

| Interface | Role |
|---|---|
| `Contract\Extension` | `name()` — the key of the extension's section in `extensionConfig`; `register(ExtensionRegistry $registry, array $config)` — called once per run with that section |
| `Contract\ExtensionRegistry` | `addPropertyEnricher()`, `addClassEnricher()`, `addFormat()`, `claimExtensionKeys()` |
| `Contract\PropertyEnricher` | `enrichProperty(PropertyContext $context): list<AttributeModel>` |
| `Contract\ClassEnricher` | `enrichClass(ClassContext $context): list<AttributeModel>` |
| `Contract\FormatMapping` | the PHP type of a string `format` |

The generator creates an extension with `new`, without arguments.

## Example

This extension reads `x-example` from property schemas, writes `#[Doc\Example('…')]`, and maps `format: iban` to a
class:

```php
<?php

declare(strict_types=1);

namespace Acme\DtoExample;

use MSSTC4PHP\DtoGenerator\Contract\Extension;
use MSSTC4PHP\DtoGenerator\Contract\ExtensionRegistry;
use MSSTC4PHP\DtoGenerator\Contract\FormatMapping;
use MSSTC4PHP\DtoGenerator\Contract\PropertyContext;
use MSSTC4PHP\DtoGenerator\Contract\PropertyEnricher;
use MSSTC4PHP\DtoGenerator\Domain\Model\ArgumentValue;
use MSSTC4PHP\DtoGenerator\Domain\Model\AttributeArgument;
use MSSTC4PHP\DtoGenerator\Domain\Model\AttributeModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassName;
use MSSTC4PHP\DtoGenerator\Domain\Model\ClassType;
use MSSTC4PHP\DtoGenerator\Domain\Model\ImportAlias;

final class ExampleExtension implements Extension, PropertyEnricher
{
    private bool $enabled = true;

    public function name(): string
    {
        return 'example';
    }

    public function register(ExtensionRegistry $registry, array $config): void
    {
        $this->enabled = ($config['enabled'] ?? true) !== false;
        $registry->claimExtensionKeys('x-example');
        $registry->addFormat('iban', new FormatMapping(new ClassType(ClassName::fromFqcn('Acme\Money\Iban'))));
        $registry->addPropertyEnricher($this);
    }

    public function enrichProperty(PropertyContext $context): array
    {
        $example = $context->schema()->extensions()->get('x-example');
        if (!$this->enabled || $example === null) {
            return [];
        }

        if (!is_string($example)) {
            $context->diagnostics()->warning('"x-example" must be a string.', $context->schema()->location()->child('x-example'));

            return [];
        }

        return [new AttributeModel(
            ClassName::fromFqcn('Acme\Doc\Attribute\Example'),
            [AttributeArgument::positional(ArgumentValue::literal($example))],
            new ImportAlias('Acme\Doc\Attribute', 'Doc'),
        )];
    }
}
```

With this in `dto-generator.yaml`:

```yaml
extensions: [Acme\DtoExample\ExampleExtension]
extensionConfig:
  example: {enabled: true}
```

the schema

```yaml
Payment:
  type: object
  properties:
    account: {type: string, format: iban, x-example: DE89370400440532013000}
```

gives on PHP 8.2:

```php
use Acme\Doc\Attribute as Doc;

final readonly class Payment
{
    public function __construct(
        #[Doc\Example('DE89370400440532013000')] public ?\Acme\Money\Iban $account = null,
    ) {
    }
}
```

## What the contexts offer

`PropertyContext` and `ClassContext` are read-only; an enricher returns attributes and never changes the model.

| Method | Returns |
|---|---|
| `property()` / `class()` | the model: name, wire name, type, required, default, discriminator… |
| `owner()` (property) | the class the property belongs to |
| `schema()` | the source schema with all its keywords and `x-` keys (`keyword()`, `extensions()->get()`) |
| `references()` | `resolve($schema)` follows a `$ref` chain to its end; `chain($schema)` lists every schema on the way |
| `target()` | the target: `php()`, `metadata()`, `supports(Capability)`, `isStrict()` |
| `packages()` | the versions in the project's `composer.lock`: `has('symfony/validator')`, `version(…)` |
| `diagnostics()` | `warning()` and `error()` at a `SchemaLocation`, reported with the others |
| `isInline()` (class) | whether the class was lifted from an inline object, whose `x-` keys belong to the property |
| `selectingDiscriminator()` (class) | the discriminator whose mapping selects this class, or `null` |

Keywords beside a `$ref` (`{$ref: Email, maxLength: 64}`) stay on the property's schema, and the referenced schema has
its own: read `schema()` first, then `references()->resolve(schema())`, or walk `references()->chain()` when every
schema on the way must apply.

## Attributes

`AttributeModel(ClassName $class, list<AttributeArgument> $arguments = [], ?ImportAlias $import = null)`:

- arguments are `AttributeArgument::positional($value)` or `AttributeArgument::named('name', $value)`, with values from
  `ArgumentValue::literal()`, `listOf()`, `mapOf()`, `constant()`, `classReference()` and `newInstance()`;
- the optional `ImportAlias` writes `use Namespace as Alias;` and shortens the attribute to `Alias\Short`, the way
  `#[Assert\NotNull]` is written by hand.

The generator writes attributes as PHP 8 attributes or as annotations, depending on `target.metadata`; extensions
do not deal with the syntax. An argument that the target cannot express — `newInstance()` on PHP 8.0, say — is
reported like one from `x-php-attributes`. Check `target()` when your attribute needs a newer PHP or library.

Attributes appear in a fixed order: built-in `x-php-attributes` first, then extensions in the order they are loaded.

## Formats and `x-` keys

- `addFormat('iban', new FormatMapping($type))` maps a string format to a type, usually a `ClassType`. A
  `ScalarType::string('non-empty-string')` keeps a string and refines its PHPDoc. Two extensions registering one format
  is an error; a format in the project's config wins over an extension's.
- `claimExtensionKeys('x-example', 'x-example-*')` declares the keys the extension reads (globs allowed), so the
  user cannot take them for an alias. Keys in the `x-php-`/`x-dto-` vocabulary cannot be claimed. Two extensions may
  claim the same key; then both read it.

## Errors

Report schema problems through `diagnostics()` with the schema's location (`$schema->location()->child('x-example')`).
An exception thrown by an enricher is turned into an error naming the extension; it does not stop the other
diagnostics from being reported, but nothing is written.

## Shipping an extension

Declare it in your package's `composer.json`, and the generator loads it when your package is installed next to it:

```json
{
    "require": {"msstc4php/dto-generator": "^1.1"},
    "extra": {"dto-generator": {"extensions": ["Acme\\DtoExample\\ExampleExtension"]}}
}
```

Projects can turn discovery off with `discoverExtensions: false` and list extensions in `extensions` instead.
