<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Functional\Cli;

use MSSTC4PHP\DtoGenerator\DtoGenerator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class GenerateCommandTest extends TestCase
{
    private const CONFIG = <<<'YAML'
        version: 1
        target:
          php: '8.2'
        sources:
          - spec: api/openapi.yaml
            namespace: App\Dto
            outputDir: src/Dto
        YAML;

    private const SPEC = <<<'YAML'
        openapi: 3.1.0
        components:
          schemas:
            User:
              type: object
              required: [id]
              properties:
                id: { type: integer }
                tag: { $ref: '#/components/schemas/Tag' }
            Tag:
              type: object
              properties:
                label: { type: string }
        YAML;

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/dto-generator-cli-' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/api', 0777, true);
        file_put_contents($this->dir . '/dto-generator.yaml', self::CONFIG);
        file_put_contents($this->dir . '/api/openapi.yaml', self::SPEC);
    }

    protected function tearDown(): void
    {
        self::remove($this->dir);
    }

    public function testWritesTheOutput(): void
    {
        [$code, $display] = $this->execute([]);

        self::assertSame(0, $code);
        self::assertSame("Written: 2, deleted: 0, unchanged: 0.\n", $display);
        self::assertStringContainsString('final readonly class User', (string) file_get_contents($this->dir . '/src/Dto/User.php'));
        self::assertFileExists($this->dir . '/src/Dto/.dto-generator.manifest.json');
    }

    public function testLeavesAnUpToDateOutputAlone(): void
    {
        $this->execute([]);

        self::assertSame([0, "Written: 0, deleted: 0, unchanged: 2.\n"], $this->execute([]));
        self::assertSame([0, "Up to date.\n"], $this->execute(['--check' => true]));
    }

    public function testReportsAnOutOfDateOutputWithoutWriting(): void
    {
        $this->execute([]);
        $before = (string) file_get_contents($this->dir . '/src/Dto/User.php');
        file_put_contents($this->dir . '/api/openapi.yaml', str_replace('id: { type: integer }', 'id: { type: string }', self::SPEC));

        [$code, $display] = $this->execute(['--check' => true]);

        self::assertSame(1, $code);
        self::assertSame("Out of date:\n  update src/Dto/User.php\n", $display);
        self::assertSame($before, file_get_contents($this->dir . '/src/Dto/User.php'));
    }

    public function testShowsThePlanOnADryRun(): void
    {
        [$code, $display] = $this->execute(['--dry-run' => true]);

        self::assertSame(0, $code);
        self::assertSame("create src/Dto/Tag.php\ncreate src/Dto/User.php\nWould write: 2, delete: 0, unchanged: 0.\n", $display);
        self::assertDirectoryDoesNotExist($this->dir . '/src');
    }

    public function testKeepsTheOutputWhenTheSchemaIsBroken(): void
    {
        $this->execute([]);
        $before = (string) file_get_contents($this->dir . '/src/Dto/User.php');
        file_put_contents($this->dir . '/api/openapi.yaml', str_replace("#/components/schemas/Tag'", "#/components/schemas/Gone'", self::SPEC));

        [$code, $display] = $this->execute([]);

        self::assertSame(2, $code);
        self::assertSame(
            "error api/openapi.yaml#/components/schemas/User/properties/tag: \$ref \"#/components/schemas/Gone\" does not resolve: {$this->dir}/api/openapi.yaml has nothing at \"/components/schemas/Gone\".\nGeneration failed: 1 error(s).\n",
            $display,
        );
        self::assertSame($before, file_get_contents($this->dir . '/src/Dto/User.php'));
    }

    public function testFailsOnAConfigError(): void
    {
        file_put_contents($this->dir . '/dto-generator.yaml', self::CONFIG . "\nunknown: 1\n");

        [$code, $display] = $this->execute([]);

        self::assertSame(3, $code);
        self::assertStringEndsWith("Configuration failed: 1 error(s).\n", $display);
        self::assertStringStartsWith('error dto-generator.yaml', $display);
    }

    public function testNeedsAConfig(): void
    {
        unlink($this->dir . '/dto-generator.yaml');

        self::assertSame([3, "error: No dto-generator.yaml or dto-generator.json here; pass --config.\n"], $this->execute([]));
    }

    public function testFallsBackToAJsonConfigAndAcceptsARelativePath(): void
    {
        rename($this->dir . '/dto-generator.yaml', $this->dir . '/custom.yaml');
        file_put_contents($this->dir . '/dto-generator.json', '{"version": 1, "target": {"php": "8.2"}, "sources": [{"spec": "api/openapi.yaml", "namespace": "App\\\\Json", "outputDir": "json"}]}');

        self::assertSame(0, $this->execute([])[0]);
        self::assertFileExists($this->dir . '/json/User.php');
        self::assertSame(0, $this->execute(['--config' => 'custom.yaml'])[0]);
        self::assertFileExists($this->dir . '/src/Dto/User.php');
    }

    public function testRejectsCheckTogetherWithDryRun(): void
    {
        self::assertSame([3, "error: --check and --dry-run cannot be combined.\n"], $this->execute(['--check' => true, '--dry-run' => true]));
    }

    public function testRejectsAnUnknownFormat(): void
    {
        self::assertSame([3, "error: --format must be \"text\" or \"json\".\n"], $this->execute(['--format' => 'xml']));
    }

    public function testReportsAsJson(): void
    {
        [$code, $display] = $this->execute(['--format' => 'json', '--check' => true]);

        self::assertSame(1, $code);
        self::assertSame(
            [
                'status' => 'out-of-date',
                'diagnostics' => [],
                'changes' => [['kind' => 'create', 'path' => 'src/Dto/Tag.php'], ['kind' => 'create', 'path' => 'src/Dto/User.php']],
            ],
            json_decode($display, true),
        );
    }

    public function testReportsDiagnosticsAsJson(): void
    {
        file_put_contents($this->dir . '/dto-generator.yaml', self::CONFIG . "\nunknown: 1\n");

        $report = json_decode($this->execute(['--format' => 'json'])[1], true);

        self::assertIsArray($report);
        self::assertSame('config-failed', $report['status']);
        self::assertSame([], $report['changes']);
        self::assertIsArray($report['diagnostics']);
        self::assertSame(['severity', 'location', 'message'], array_keys((array) $report['diagnostics'][0]));
    }

    /**
     * @param array<string, string|bool> $options
     *
     * @return array{int, string}
     */
    private function execute(array $options): array
    {
        $tester = new CommandTester(DtoGenerator::console($this->dir)->find('generate'));
        $code = $tester->execute($options);

        return [$code, $tester->getDisplay()];
    }

    private static function remove(string $path): void
    {
        if (is_dir($path)) {
            foreach ((array) scandir($path) as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    self::remove($path . '/' . $entry);
                }
            }

            rmdir($path);
        } elseif (file_exists($path)) {
            unlink($path);
        }
    }
}
