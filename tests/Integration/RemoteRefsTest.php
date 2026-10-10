<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Integration;

use MSSTC4PHP\DtoGenerator\Application\Service\Generate\Input;
use MSSTC4PHP\DtoGenerator\Application\Service\Generate\Mode;
use MSSTC4PHP\DtoGenerator\Application\Service\Generate\Output;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostic;
use MSSTC4PHP\DtoGenerator\DtoGenerator;
use MSSTC4PHP\DtoGenerator\Tests\Support\RemoteServer;
use MSSTC4PHP\DtoGenerator\Tests\Support\TestRunTemporaryDirectory;
use PHPUnit\Framework\TestCase;

/**
 * Remote $refs end to end (spec F2): fetched into the cache by a run that writes, read from it by a check.
 */
final class RemoteRefsTest extends TestCase
{
    private string $project;

    protected function setUp(): void
    {
        $this->project = sys_get_temp_dir() . '/remote-refs-' . bin2hex(random_bytes(4));
        mkdir($this->project);
    }

    protected function tearDown(): void
    {
        TestRunTemporaryDirectory::remove($this->project);
    }

    public function testGeneratesFromRemoteDocumentsAndChecksFromTheCacheAlone(): void
    {
        $server = RemoteServer::start();
        try {
            $this->project($server->url('/docs/'), $server->url('/docs/money.yaml#/Money'));
            $written = $this->generate(Mode::WRITE);
        } finally {
            $server->stop();
        }

        self::assertSame('ok', $written->status()->value(), implode("\n", $this->messages($written)));
        self::assertFileExists($this->project . '/src/Dto/Order.php');
        self::assertStringContainsString('public Money $price', (string) file_get_contents($this->project . '/src/Dto/Order.php'));
        self::assertStringContainsString('public Currency $currency', (string) file_get_contents($this->project . '/src/Dto/Money.php'));
        self::assertFileExists($this->project . '/.dto-generator/remote/index.json');

        // The server is gone: the check reads the cache.
        $checked = $this->generate(Mode::CHECK);
        self::assertSame('ok', $checked->status()->value(), implode("\n", $this->messages($checked)));
    }

    public function testChecksNothingThatIsNotCached(): void
    {
        $this->project('http://127.0.0.1:9/docs/', 'http://127.0.0.1:9/docs/money.yaml#/Money');

        $checked = $this->generate(Mode::CHECK);

        self::assertSame('generation-failed', $checked->status()->value());
        self::assertSame([
            'error ' . $this->project . '/api.yaml#/components/schemas/Order/properties/price: Remote document "http://127.0.0.1:9/docs/money.yaml" is not cached in ' . $this->project . '/.dto-generator/remote; run generate to fetch it.',
        ], $this->messages($checked));
    }

    private function project(string $prefix, string $ref): void
    {
        file_put_contents($this->project . '/composer.json', '{}');
        file_put_contents($this->project . '/api.yaml', (string) json_encode(['openapi' => '3.1.0', 'components' => ['schemas' => [
            'Order' => ['type' => 'object', 'required' => ['price'], 'properties' => ['price' => ['$ref' => $ref]]],
        ]]]));
        file_put_contents($this->project . '/dto-generator.yaml', (string) json_encode([
            'version' => 1,
            'target' => ['php' => '8.2'],
            'verifyClasses' => false,
            'discoverExtensions' => false,
            'remoteRefs' => ['allow' => [$prefix], 'timeout' => 5],
            'sources' => [['spec' => 'api.yaml', 'namespace' => 'App\\Dto', 'outputDir' => 'src/Dto']],
        ]));
    }

    private function generate(string $mode): Output
    {
        return DtoGenerator::generator()(new Input($this->project . '/dto-generator.yaml', Mode::from($mode)));
    }

    /**
     * @return list<string>
     */
    private function messages(Output $output): array
    {
        return array_map(static fn (Diagnostic $diagnostic): string => $diagnostic->toString(), $output->diagnostics()->all());
    }
}
