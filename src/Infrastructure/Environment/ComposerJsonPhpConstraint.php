<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Infrastructure\Environment;

use JsonException;
use MSSTC4PHP\DtoGenerator\Application\Port\ProjectPhpConstraint;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Path;

final class ComposerJsonPhpConstraint implements ProjectPhpConstraint
{
    public function find(string $directory): ?string
    {
        $current = Path::normalize($directory);
        while (true) {
            $file = $current . '/composer.json';
            if (is_file($file)) {
                return $this->phpRequirement($file);
            }

            $parent = Path::directory($current);
            if ($parent === $current) {
                return null;
            }

            $current = $parent;
        }
    }

    /**
     * The nearest composer.json describes the project even when it does not pin PHP, so the search stops there.
     */
    private function phpRequirement(string $file): ?string
    {
        $content = is_readable($file) ? file_get_contents($file) : false;
        if ($content === false) {
            return null;
        }

        try {
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            return null;
        }

        $require = is_array($data) ? ($data['require'] ?? null) : null;
        $php = is_array($require) ? ($require['php'] ?? null) : null;

        return is_string($php) ? $php : null;
    }
}
