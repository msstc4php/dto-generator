<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * `@api` marks exactly the classes the Roave BC check guards, so the docs, the check and the code name the same API.
 */
final class PublicApiTest extends TestCase
{
    private const SRC = __DIR__ . '/../../src/';

    public function testTagsExactlyTheClassesTheBackwardCompatibilityCheckGuards(): void
    {
        $config = (string) file_get_contents(__DIR__ . '/../../.roave-backward-compatibility-check.xml');
        self::assertSame(1, preg_match('~<ignored-regex>(.+)</ignored-regex>~', $config, $match));
        $ignored = html_entity_decode($match[1]);

        $untagged = [];
        $overtagged = [];
        $public = 0;
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::SRC));
        foreach ($files as $file) {
            if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $fqcn = 'MSSTC4PHP\\DtoGenerator\\' . str_replace('/', '\\', substr($file->getPathname(), strlen(self::SRC), -4));
            // The check ignores a message when the first class it names is not public API.
            $guarded = preg_match($ignored, sprintf('Method %s#run() was removed', $fqcn)) === 0;
            $tagged = preg_match('~/\*\*(?:(?!\*/).)*@api\b(?:(?!\*/).)*\*/\s*(?:final |abstract )?(?:class|interface|trait) ~s', (string) file_get_contents($file->getPathname())) === 1;
            $public += $guarded ? 1 : 0;
            if ($guarded && !$tagged) {
                $untagged[] = $fqcn;
            } elseif ($tagged && !$guarded) {
                $overtagged[] = $fqcn;
            }
        }

        self::assertGreaterThan(30, $public);
        self::assertSame([], $untagged, 'Guarded by the BC check but not tagged @api.');
        self::assertSame([], $overtagged, 'Tagged @api but not guarded by the BC check.');
    }
}
