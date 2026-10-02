<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Extension;

use MSSTC4PHP\DtoGenerator\Contract\ClassContext;
use MSSTC4PHP\DtoGenerator\Contract\ClassEnricher;
use MSSTC4PHP\DtoGenerator\Contract\Extension;
use MSSTC4PHP\DtoGenerator\Contract\ExtensionRegistry;
use MSSTC4PHP\DtoGenerator\Contract\FormatMapping;
use MSSTC4PHP\DtoGenerator\Contract\PropertyContext;
use MSSTC4PHP\DtoGenerator\Contract\PropertyEnricher;
use MSSTC4PHP\DtoGenerator\Domain\Builder\ExtensionVocabulary;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Model\AttributeModel;
use MSSTC4PHP\DtoGenerator\Domain\Model\TypeModel;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaLocation;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Json;
use Throwable;

/**
 * What the extensions registered (spec §8): enrichers in registration order, formats and claimed `x-*` keys. A failing
 * extension becomes an error naming it, and generation goes on to report everything else.
 *
 * @phpstan-import-type JsonValue from Json
 */
final class Registry implements ExtensionRegistry
{
    private Diagnostics $diagnostics;

    private SchemaLocation $location;

    private string $current = '';

    /** @var list<PropertyEnricher> */
    private array $propertyEnrichers = [];

    /** @var list<ClassEnricher> */
    private array $classEnrichers = [];

    /** @var array<string, string> enricher object hash → extension name */
    private array $owners = [];

    /** @var array<int|string, TypeModel> */
    private array $formats = [];

    /** @var array<int|string, string> format → extension name */
    private array $formatOwners = [];

    /** @var list<non-empty-string> */
    private array $claims = [];

    /**
     * @param SchemaLocation $location where problems of the extensions themselves are reported: the config
     */
    public function __construct(Diagnostics $diagnostics, SchemaLocation $location)
    {
        $this->diagnostics = $diagnostics;
        $this->location = $location;
    }

    /**
     * @param array<int|string, JsonValue> $config
     */
    public function register(Extension $extension, array $config): void
    {
        $this->current = $extension->name();
        try {
            $extension->register($this, $config);
        } catch (Throwable $exception) {
            $this->diagnostics->error(sprintf('Extension "%s" failed to register: %s', $this->current, $exception->getMessage()), $this->location);
        } finally {
            $this->current = '';
        }
    }

    public function addPropertyEnricher(PropertyEnricher $enricher): void
    {
        $this->propertyEnrichers[] = $enricher;
        $this->owners[spl_object_hash($enricher)] = $this->current;
    }

    public function addClassEnricher(ClassEnricher $enricher): void
    {
        $this->classEnrichers[] = $enricher;
        $this->owners[spl_object_hash($enricher)] = $this->current;
    }

    public function addFormat(string $format, FormatMapping $mapping): void
    {
        $owner = $this->formatOwners[$format] ?? null;
        if ($owner !== null) {
            $this->diagnostics->error(sprintf('Extensions "%s" and "%s" both register format "%s".', $owner, $this->current, $format), $this->location);

            return;
        }

        $this->formats[$format] = $mapping->type();
        $this->formatOwners[$format] = $this->current;
    }

    public function claimExtensionKeys(string ...$globs): void
    {
        foreach ($globs as $glob) {
            $problem = $this->claimProblem($glob);
            if ($problem !== null) {
                $this->diagnostics->error(sprintf('Extension "%s" cannot claim "%s": %s.', $this->current, $glob, $problem), $this->location);
            } elseif ($glob !== '') {
                $this->claims[] = $glob;
            }
        }
    }

    public function isClaimed(string $key): bool
    {
        foreach ($this->claims as $glob) {
            if ($this->matches($glob, $key)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int|string, TypeModel> $configured formats from the config, which win
     *
     * @return array<int|string, TypeModel>
     */
    public function formats(array $configured): array
    {
        return array_replace($this->formats, $configured);
    }

    /**
     * @return list<AttributeModel>
     */
    public function enrichProperty(PropertyContext $context): array
    {
        $attributes = [];
        foreach ($this->propertyEnrichers as $enricher) {
            try {
                array_push($attributes, ...$enricher->enrichProperty($context));
            } catch (Throwable $exception) {
                $this->diagnostics->error(
                    sprintf('Extension "%s" failed on property "%s": %s', $this->owners[spl_object_hash($enricher)], $context->property()->wireName(), $exception->getMessage()),
                    $context->schema()->location(),
                );
            }
        }

        return $attributes;
    }

    /**
     * @return list<AttributeModel>
     */
    public function enrichClass(ClassContext $context): array
    {
        $attributes = [];
        foreach ($this->classEnrichers as $enricher) {
            try {
                array_push($attributes, ...$enricher->enrichClass($context));
            } catch (Throwable $exception) {
                $this->diagnostics->error(
                    sprintf('Extension "%s" failed on class %s: %s', $this->owners[spl_object_hash($enricher)], $context->class()->name()->fqcn(), $exception->getMessage()),
                    $context->schema()->location(),
                );
            }
        }

        return $attributes;
    }

    private function claimProblem(string $glob): ?string
    {
        if (strncmp($glob, 'x-', 2) !== 0) {
            return 'extension keys start with "x-"';
        }

        // A claim must leave every core key, and every x-php-*/x-dto-* key the core rejects as a typo, to the core.
        foreach (array_merge(ExtensionVocabulary::KNOWN, ['x-php-', 'x-dto-']) as $core) {
            if ($this->matches($glob, $core) || strncmp($glob, $core, strlen($core)) === 0) {
                return 'it covers keys of the core vocabulary';
            }
        }

        return null;
    }

    private function matches(string $glob, string $key): bool
    {
        $pattern = strtr(preg_quote($glob, '/'), ['\*' => '.*', '\?' => '.']);

        return preg_match('/\A' . $pattern . '\z/', $key) === 1;
    }
}
