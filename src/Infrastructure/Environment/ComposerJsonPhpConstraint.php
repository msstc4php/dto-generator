<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Infrastructure\Environment;

use JsonException;
use MSSTC4PHP\DtoGenerator\Application\Port\PhpRequirement;
use MSSTC4PHP\DtoGenerator\Application\Port\ProjectPhpConstraint;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Path;

final class ComposerJsonPhpConstraint implements ProjectPhpConstraint
{
    public function find(string $directory): PhpRequirement
    {
        $current = Path::normalize($directory);
        while (true) {
            $file = $current . '/composer.json';
            if (is_file($file)) {
                return $this->read($file);
            }

            $parent = Path::directory($current);
            if ($parent === $current) {
                return PhpRequirement::none();
            }

            $current = $parent;
        }
    }

    /**
     * The nearest composer.json describes the project even when it does not pin PHP, so the search stops there.
     */
    private function read(string $file): PhpRequirement
    {
        $content = is_readable($file) ? file_get_contents($file) : false;
        if ($content === false) {
            return PhpRequirement::unusable($file, 'cannot be read');
        }

        try {
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            return PhpRequirement::unusable($file, 'is not valid JSON');
        }

        if (!is_array($data)) {
            return PhpRequirement::unusable($file, 'must contain an object');
        }

        $require = $data['require'] ?? null;
        $php = is_array($require) ? ($require['php'] ?? null) : null;
        if ($php !== null && !is_string($php)) {
            return PhpRequirement::unusable($file, '"require.php" must be a string');
        }

        return PhpRequirement::found($file, $php);
    }
}
