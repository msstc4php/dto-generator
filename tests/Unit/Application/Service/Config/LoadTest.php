<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Application\Service\Config;

use InvalidArgumentException;
use MSSTC4PHP\DtoGenerator\Application\Config\ConfigFactory;
use MSSTC4PHP\DtoGenerator\Application\Config\TargetResolver;
use MSSTC4PHP\DtoGenerator\Application\Service\Config\Load\Action;
use MSSTC4PHP\DtoGenerator\Application\Service\Config\Load\Input;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostic;
use MSSTC4PHP\DtoGenerator\Tests\Support\FixedPhpConstraint;
use MSSTC4PHP\DtoGenerator\Tests\Support\InMemoryDocumentLoader;
use PHPUnit\Framework\TestCase;

final class LoadTest extends TestCase
{
    public function testLoadsConfigAndTarget(): void
    {
        $output = $this->action([
            '/project/dto-generator.yaml' => [
                'version' => 1,
                'sources' => [['spec' => 'api/openapi.yaml', 'namespace' => 'App\Dto', 'outputDir' => 'src/Dto']],
            ],
        ])(new Input('/project/dto-generator.yaml'));

        self::assertSame([], $output->diagnostics()->all());
        self::assertNotNull($output->config());
        self::assertNotNull($output->target());
        self::assertSame('8.1', $output->target()->php()->toString());
    }

    public function testReportsAMissingConfigFile(): void
    {
        $output = $this->action([])(new Input('/project/dto-generator.yaml'));

        self::assertNull($output->config());
        self::assertNull($output->target());
        self::assertSame(
            ['error /project/dto-generator.yaml#: File "/project/dto-generator.yaml" does not exist.'],
            array_map(static fn (Diagnostic $d): string => $d->toString(), $output->diagnostics()->all()),
        );
    }

    public function testStopsAfterConfigErrors(): void
    {
        $output = $this->action(['/project/dto-generator.yaml' => ['version' => 2]])(new Input('/project/dto-generator.yaml'));

        self::assertNull($output->config());
        self::assertNull($output->target());
        self::assertTrue($output->diagnostics()->hasErrors());
    }

    public function testRequiresAnAbsolutePath(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be absolute');

        new Input('dto-generator.yaml');
    }

    /**
     * @param array<string, array<array-key, mixed>> $documents
     */
    private function action(array $documents): Action
    {
        return new Action(
            new InMemoryDocumentLoader($documents),
            new ConfigFactory(),
            new TargetResolver(new FixedPhpConstraint('^8.1')),
        );
    }
}
