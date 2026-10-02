<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Integration\Infrastructure;

use MSSTC4PHP\DtoGenerator\Application\Port\DiscoveredExtension;
use MSSTC4PHP\DtoGenerator\Application\Port\DiscoveredExtensions;
use MSSTC4PHP\DtoGenerator\Infrastructure\Environment\InstalledJsonExtensionDiscovery;
use PHPUnit\Framework\TestCase;

final class InstalledJsonExtensionDiscoveryTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/dto-generator-installed-' . bin2hex(random_bytes(4)) . '.json';
    }

    protected function tearDown(): void
    {
        if (is_file($this->file)) {
            chmod($this->file, 0644);
            unlink($this->file);
        }
    }

    public function testReadsTheExtensionsComposer2Packages(): void
    {
        $this->write(['packages' => [
            ['name' => 'acme/bridge', 'extra' => ['dto-generator' => ['extensions' => ['Acme\Bridge\One', 'Acme\Bridge\Two']]]],
            ['name' => 'acme/plain'],
            ['name' => 'acme/other', 'extra' => ['branch-alias' => []]],
        ]]);

        $found = (new InstalledJsonExtensionDiscovery($this->file))->discover();

        self::assertSame(['acme/bridge Acme\Bridge\One', 'acme/bridge Acme\Bridge\Two'], $this->describe($found));
        self::assertSame([], $found->problems());
    }

    public function testReadsTheListOfComposer1(): void
    {
        $this->write([['name' => 'acme/bridge', 'extra' => ['dto-generator' => ['extensions' => ['Acme\Bridge\One']]]]]);

        self::assertSame(['acme/bridge Acme\Bridge\One'], $this->describe((new InstalledJsonExtensionDiscovery($this->file))->discover()));
    }

    public function testReportsDeclarationsThatAreNoListsOfClassNames(): void
    {
        $this->write(['packages' => [
            ['name' => 'acme/string', 'extra' => ['dto-generator' => ['extensions' => 'Acme\One']]],
            ['name' => 'acme/mixed', 'extra' => ['dto-generator' => ['extensions' => ['Acme\Good', 'not a class', 3]]]],
            ['name' => 'acme/section', 'extra' => ['dto-generator' => 'Acme\One']],
            ['name' => 'acme/map', 'extra' => ['dto-generator' => ['extensions' => ['a' => 'Acme\One']]]],
            ['extra' => ['dto-generator' => ['extensions' => ['Acme\Nameless']]]],
            ['name' => '', 'extra' => ['dto-generator' => ['extensions' => ['Acme\Empty']]]],
        ]]);

        $found = (new InstalledJsonExtensionDiscovery($this->file))->discover();

        self::assertSame(['acme/mixed Acme\Good'], $this->describe($found));
        self::assertSame(
            [
                'Package "acme/string" declares extra.dto-generator.extensions that is no list of class names.',
                'Package "acme/mixed" declares "not a class" in extra.dto-generator.extensions, which is no class name.',
                'Package "acme/mixed" declares 3 in extra.dto-generator.extensions, which is no class name.',
                'Package "acme/section" declares extra.dto-generator that is no object.',
                'Package "acme/map" declares extra.dto-generator.extensions that is no list of class names.',
                'A package without a name in ' . $this->file . ' is skipped.',
                'A package without a name in ' . $this->file . ' is skipped.',
            ],
            $found->problems(),
        );
    }

    public function testFindsNothingWithoutTheFile(): void
    {
        $found = (new InstalledJsonExtensionDiscovery($this->file))->discover();

        self::assertSame([], $found->extensions());
        self::assertSame([], $found->problems());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public function unusableFiles(): iterable
    {
        yield 'invalid JSON' => ['{', 'is not valid JSON'];
        yield 'no packages' => ['{"packages": 1}', 'has no list of packages'];
        yield 'scalar' => ['"x"', 'has no list of packages'];
        yield 'object without packages' => ['{"dev": true}', 'has no list of packages'];
    }

    /**
     * @dataProvider unusableFiles
     */
    public function testReportsAFileItCannotUse(string $content, string $reason): void
    {
        file_put_contents($this->file, $content);

        $found = (new InstalledJsonExtensionDiscovery($this->file))->discover();

        self::assertSame([], $found->extensions());
        self::assertSame([$this->file . ' ' . $reason . '; no extensions are discovered.'], $found->problems());
    }

    public function testReportsAFileItCannotRead(): void
    {
        file_put_contents($this->file, '{}');
        chmod($this->file, 0000);
        if (is_readable($this->file)) {
            self::markTestSkipped('root can read any file');
        }

        self::assertSame([$this->file . ' cannot be read; no extensions are discovered.'], (new InstalledJsonExtensionDiscovery($this->file))->discover()->problems());
    }

    public function testReadsTheInstallationTheGeneratorRunsFrom(): void
    {
        // This repository's own vendor; its packages may declare extensions some day, but never wrongly.
        self::assertSame([], (new InstalledJsonExtensionDiscovery())->discover()->problems());
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function write(array $data): void
    {
        file_put_contents($this->file, json_encode($data));
    }

    /**
     * @return list<string>
     */
    private function describe(DiscoveredExtensions $found): array
    {
        return array_map(static fn (DiscoveredExtension $extension): string => $extension->package() . ' ' . $extension->className()->fqcn(), $found->extensions());
    }
}
