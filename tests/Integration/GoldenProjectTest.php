<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Integration;

use MSSTC4PHP\DtoGenerator\Application\Service\Generate\GeneratedFile;
use MSSTC4PHP\DtoGenerator\Application\Service\Generate\Input;
use MSSTC4PHP\DtoGenerator\Application\Service\Generate\Mode;
use MSSTC4PHP\DtoGenerator\Application\Service\Generate\Output;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostic;
use MSSTC4PHP\DtoGenerator\DtoGenerator;
use PHPUnit\Framework\TestCase;

/**
 * The whole pipeline on a real project, byte for byte per target (spec §11.1); `UPDATE_SNAPSHOTS=1` rewrites
 * expected/, and `make test-targets` lints it and runs PHPStan on it.
 */
final class GoldenProjectTest extends TestCase
{
    private const PROJECT = __DIR__ . '/../Fixtures/Projects/golden';

    /**
     * @dataProvider targets
     */
    public function testMatchesTheExpectedOutput(string $php): void
    {
        $output = $this->generate($php);
        self::assertSame('ok', $output->status()->value(), implode("\n", array_map(static fn (Diagnostic $d): string => $d->toString(), $output->diagnostics()->all())));
        self::assertSame([], $output->diagnostics()->all());

        $expectedDir = self::PROJECT . '/expected/' . $php . '/';
        $generated = [];
        foreach ($output->files() as $file) {
            $path = $expectedDir . $file->relativePath() . '.golden';
            $generated[] = $file->relativePath() . '.golden';
            if (getenv('UPDATE_SNAPSHOTS') === '1') {
                if (!is_dir(dirname($path))) {
                    mkdir(dirname($path), 0777, true);
                }

                file_put_contents($path, $file->contents());
            }

            self::assertStringEqualsFile($path, $file->contents());
        }

        $expected = glob($expectedDir . '*.golden');
        self::assertIsArray($expected);
        sort($generated);
        self::assertSame($generated, array_map(static fn (string $path): string => basename($path), $expected));
    }

    public function testGeneratesTheSameFilesOnEveryRun(): void
    {
        $contents = static fn (Output $output): array => array_map(static fn (GeneratedFile $file): string => $file->contents(), $output->files());

        self::assertSame($contents($this->generate('8.2')), $contents($this->generate('8.2')));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function targets(): array
    {
        return ['7.4' => ['7.4'], '8.0' => ['8.0'], '8.1' => ['8.1'], '8.2' => ['8.2'], '8.5' => ['8.5']];
    }

    private function generate(string $php): Output
    {
        return DtoGenerator::generator()(new Input((string) realpath(self::PROJECT . '/php' . $php . '.yaml'), Mode::from(Mode::DRY_RUN)));
    }
}
