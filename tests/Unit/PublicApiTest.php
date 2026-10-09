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

    /** A class docblock with an `@api` line of its own, right before the declaration. */
    private const API_DOCBLOCK = '~/\*\*(?:(?!\*/).)*^\s*\*\s*@api\s*$(?:(?!\*/).)*\*/\s*(?:final |abstract )?(?:class|interface|trait) ~ms';

    public function testTagsExactlyTheClassesTheBackwardCompatibilityCheckGuards(): void
    {
        $ignored = $this->ignoredRegexes();
        $untagged = [];
        $overtagged = [];
        $public = 0;
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::SRC)) as $file) {
            if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $fqcn = $this->fqcn($file);
            $guarded = $this->isGuarded($fqcn, $ignored);
            $tagged = preg_match(self::API_DOCBLOCK, (string) file_get_contents($file->getPathname())) === 1;
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

    /**
     * Roave ignores a message that any of its baseline regexes matches.
     *
     * @return non-empty-list<string>
     */
    private function ignoredRegexes(): array
    {
        $config = (string) file_get_contents(__DIR__ . '/../../.roave-backward-compatibility-check.xml');
        preg_match_all('~<ignored-regex>(.+?)</ignored-regex>~s', $config, $matches);
        $regexes = array_map('html_entity_decode', $matches[1]);
        self::assertNotSame([], $regexes);

        return $regexes;
    }

    /**
     * A message counts by the first class it names, so a made-up message about the class asks whether it is guarded.
     *
     * @param list<string> $ignored
     */
    private function isGuarded(string $fqcn, array $ignored): bool
    {
        foreach ($ignored as $regex) {
            if (preg_match($regex, sprintf('Method %s#run() was removed', $fqcn)) === 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * PSR-4: one class per file under src/.
     */
    private function fqcn(SplFileInfo $file): string
    {
        return 'MSSTC4PHP\\DtoGenerator\\' . str_replace('/', '\\', substr($file->getPathname(), strlen(self::SRC), -4));
    }
}
