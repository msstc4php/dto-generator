<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Integration\Emitter;

use MSSTC4PHP\DtoGenerator\Domain\Target\AccessorStyle;
use MSSTC4PHP\DtoGenerator\Domain\Target\Mutability;
use MSSTC4PHP\DtoGenerator\Domain\Target\PhpVersion;
use MSSTC4PHP\DtoGenerator\Infrastructure\Emitter\PhpParserEmitter;
use MSSTC4PHP\DtoGenerator\Tests\Support\EmitterFixture;
use PHPUnit\Framework\TestCase;

/**
 * Byte-exact output per target profile; `UPDATE_SNAPSHOTS=1` rewrites the files, `make test-targets` runs them.
 */
final class GoldenEmitterTest extends TestCase
{
    private const DIR = __DIR__ . '/../../Fixtures/Emitter/';

    /**
     * @dataProvider profiles
     */
    public function testMatchesTheGoldenFiles(string $profile, string $php, string $mutability, string $accessors, bool $withers): void
    {
        $emitter = new PhpParserEmitter();
        $target = EmitterFixture::target($php, $mutability, $accessors, null, $withers);
        $classes = EmitterFixture::classes($mutability);
        if (PhpVersion::fromString($php)->isAtLeast(PhpVersion::fromString('8.1'))) {
            $classes[] = EmitterFixture::rules($mutability);
        }

        foreach ($classes as $class) {
            $path = self::DIR . $profile . '/' . $class->name()->shortName() . '.php.golden';
            $code = $emitter->emit($class, $target, EmitterFixture::inherited($class));
            if (getenv('UPDATE_SNAPSHOTS') === '1') {
                if (!is_dir(dirname($path))) {
                    mkdir(dirname($path), 0777, true);
                }

                file_put_contents($path, $code);
            }

            self::assertStringEqualsFile($path, $code);
        }

        foreach (EmitterFixture::enums() as $enum) {
            $path = self::DIR . $profile . '/' . $enum->name()->shortName() . '.php.golden';
            $code = $emitter->emitEnum($enum, $target);
            if (getenv('UPDATE_SNAPSHOTS') === '1') {
                file_put_contents($path, $code);
            }

            self::assertStringEqualsFile($path, $code);
        }
    }

    public function testEmitsIdenticalCodeOnEveryRun(): void
    {
        $target = EmitterFixture::target('8.2', Mutability::IMMUTABLE);

        self::assertSame((new PhpParserEmitter())->emit(EmitterFixture::sample(), $target), (new PhpParserEmitter())->emit(EmitterFixture::sample(), $target));
    }

    public function testCoversEveryProfileDirectory(): void
    {
        $paths = glob(self::DIR . '*', GLOB_ONLYDIR);
        self::assertIsArray($paths);
        $directories = array_map(static fn (string $path): string => basename($path), $paths);

        self::assertSame(array_keys(self::profiles()), $directories);
    }

    /**
     * @return array<string, array{string, string, string, string, bool}>
     */
    public static function profiles(): array
    {
        $profiles = [];
        foreach ([
            ['7.4', Mutability::IMMUTABLE, AccessorStyle::AUTO],
            ['7.4', Mutability::MUTABLE, AccessorStyle::GETTERS],
            ['7.4', Mutability::MUTABLE, AccessorStyle::PUBLIC_PROPERTIES],
            ['8.0', Mutability::IMMUTABLE, AccessorStyle::AUTO],
            ['8.0', Mutability::MUTABLE, AccessorStyle::PUBLIC_PROPERTIES],
            ['8.1', Mutability::IMMUTABLE, AccessorStyle::AUTO],
            ['8.1', Mutability::IMMUTABLE, AccessorStyle::GETTERS],
            ['8.2', Mutability::IMMUTABLE, AccessorStyle::AUTO],
            ['8.2', Mutability::MUTABLE, AccessorStyle::GETTERS],
            ['8.5', Mutability::IMMUTABLE, AccessorStyle::AUTO],
            ['8.5', Mutability::IMMUTABLE, AccessorStyle::GETTERS],
            ['8.5', Mutability::MUTABLE, AccessorStyle::PUBLIC_PROPERTIES],
        ] as [$php, $mutability, $accessors]) {
            $name = $php . '-' . $mutability . ($accessors === AccessorStyle::AUTO ? '' : '-' . ($accessors === AccessorStyle::GETTERS ? 'getters' : 'public'));
            $profiles[$name] = [$name, $php, $mutability, $accessors, true];
        }

        // `dto.withers: false` on every target, each with its own way of copying.
        foreach (['7.4', '8.0', '8.1', '8.2', '8.5'] as $php) {
            $name = $php . '-immutable-nowithers';
            $profiles[$name] = [$name, $php, Mutability::IMMUTABLE, AccessorStyle::AUTO, false];
        }

        ksort($profiles);

        return $profiles;
    }
}
