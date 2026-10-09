<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Application\Config;

use MSSTC4PHP\DtoGenerator\Application\Port\ProjectPhpConstraint;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Exception\IncompatibleTarget;
use MSSTC4PHP\DtoGenerator\Domain\Exception\UnsupportedPhpVersion;
use MSSTC4PHP\DtoGenerator\Domain\Model\Identifier;
use MSSTC4PHP\DtoGenerator\Domain\Target\Capability;
use MSSTC4PHP\DtoGenerator\Domain\Target\MetadataMode;
use MSSTC4PHP\DtoGenerator\Domain\Target\PhpVersion;
use MSSTC4PHP\DtoGenerator\Domain\Target\TargetProfile;

/**
 * Resolves the "auto" settings of spec §4 and checks what only becomes checkable once the target is known.
 */
final class TargetResolver
{
    private ProjectPhpConstraint $constraints;

    public function __construct(ProjectPhpConstraint $constraints)
    {
        $this->constraints = $constraints;
    }

    public function resolve(GeneratorConfig $config, Diagnostics $diagnostics): ?TargetProfile
    {
        $errors = count($diagnostics->errors());
        $settings = $config->target();
        $php = $settings->php() ?? $this->detectPhp($config, $diagnostics);

        try {
            $profile = new TargetProfile(
                $php,
                $settings->metadata() ?? MetadataMode::defaultFor($php),
                $config->dto()->mutability(),
                $config->dto()->accessors(),
                $config->dto()->dateTimeClass(),
                $settings->isStrict(),
                $config->dto()->withers(),
            );
        } catch (IncompatibleTarget $exception) {
            $diagnostics->error($exception->getMessage(), $config->location()->child('target'));

            return null;
        }

        $this->checkNamespaces($config, $profile, $diagnostics);

        return count($diagnostics->errors()) > $errors ? null : $profile;
    }

    private function detectPhp(GeneratorConfig $config, Diagnostics $diagnostics): PhpVersion
    {
        $requirement = $this->constraints->find($config->baseDir());
        $constraint = $requirement->constraint();
        $problem = $requirement->problemDescription();
        if ($problem !== null) {
            $diagnostics->warning(
                sprintf('%s; generating for PHP %s.', $problem, PhpVersion::oldest()->toString()),
                $config->location()->child('target', 'php'),
            );
        }

        if ($constraint === null) {
            return PhpVersion::oldest();
        }

        $lowest = PhpConstraint::lowestMinor($constraint);
        if ($lowest === null) {
            if (trim($constraint) !== '*') {
                $diagnostics->warning(
                    sprintf('composer.json requires PHP "%s", which names no lower bound; generating for PHP %s.', $constraint, PhpVersion::oldest()->toString()),
                    $config->location()->child('target', 'php'),
                );
            }

            return PhpVersion::oldest();
        }

        try {
            return PhpVersion::fromString($lowest);
        } catch (UnsupportedPhpVersion $exception) {
            $fallback = version_compare($lowest, PhpVersion::oldest()->toString(), '<') ? PhpVersion::oldest() : PhpVersion::newest();
            $diagnostics->warning(
                sprintf('composer.json requires PHP "%s"; generating for PHP %s instead.', $constraint, $fallback->toString()),
                $config->location()->child('target', 'php'),
            );

            return $fallback;
        }
    }

    private function checkNamespaces(GeneratorConfig $config, TargetProfile $profile, Diagnostics $diagnostics): void
    {
        if ($profile->supports(Capability::from(Capability::RESERVED_NAMESPACE_SEGMENTS))) {
            return;
        }

        foreach ($config->sources() as $index => $source) {
            foreach (explode('\\', $source->namespace()) as $segment) {
                if (Identifier::isPhp74Keyword($segment)) {
                    $diagnostics->error(
                        $this->reservedSegment($source->namespace(), $segment, $profile),
                        $config->location()->child('sources', (string) $index, 'namespace'),
                    );
                }
            }
        }

        foreach ($config->formats() as $format => $class) {
            foreach ($class->reservedNamespaceSegments() as $segment) {
                $diagnostics->error(
                    $this->reservedSegment($class->namespace(), $segment, $profile),
                    $config->location()->child('formats', (string) $format, 'type'),
                );
            }
        }
    }

    private function reservedSegment(string $namespace, string $segment, TargetProfile $profile): string
    {
        return sprintf(
            'Namespace "%s" contains the reserved word "%s", which PHP %s cannot parse in a namespace (allowed from PHP 8.0).',
            $namespace,
            $segment,
            $profile->php()->toString(),
        );
    }
}
