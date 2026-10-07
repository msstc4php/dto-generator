<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Infrastructure\Writer;

use MSSTC4PHP\DtoGenerator\Application\Service\Generate\GeneratedFile;

/**
 * The manifest format: `{"generator": …, "files": {"<relative path>": "<sha256>"}}`, keys sorted. A file is
 * overwritten when it has the "@generated" header, and deleted as stale only when its hash still matches too.
 */
final class ManifestCodec
{
    public const FILE = '.dto-generator.manifest.json';

    /**
     * @param array<string, string> $files
     */
    public function encode(array $files): string
    {
        ksort($files, SORT_STRING);

        return json_encode(['generator' => 'msstc4php/dto-generator', 'files' => $files], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    }

    /**
     * Null for anything that is not a manifest, including entries that would point outside the directory: a
     * manifest is committed and can be edited in a pull request.
     *
     * @return array<string, string>|null
     */
    public function decode(string $text): ?array
    {
        $decoded = json_decode($text, true);
        $files = is_array($decoded) ? ($decoded['files'] ?? null) : null;
        if (!is_array($files)) {
            return null;
        }

        $manifest = [];
        foreach ($files as $relativePath => $hash) {
            if (!is_string($relativePath) || !is_string($hash) || !GeneratedFile::isSafeRelativePath($relativePath)) {
                return null;
            }

            $manifest[$relativePath] = $hash;
        }

        return $manifest;
    }
}
