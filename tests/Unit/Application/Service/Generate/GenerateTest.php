<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Application\Service\Generate;

use MSSTC4PHP\DtoGenerator\Application\Config\ConfigFactory;
use MSSTC4PHP\DtoGenerator\Application\Config\TargetResolver;
use MSSTC4PHP\DtoGenerator\Application\Port\WriteFailed;
use MSSTC4PHP\DtoGenerator\Application\Service\Config\Load\Action as LoadConfig;
use MSSTC4PHP\DtoGenerator\Application\Service\Generate\Action;
use MSSTC4PHP\DtoGenerator\Application\Service\Generate\FileChange;
use MSSTC4PHP\DtoGenerator\Application\Service\Generate\GeneratedFile;
use MSSTC4PHP\DtoGenerator\Application\Service\Generate\Input;
use MSSTC4PHP\DtoGenerator\Application\Service\Generate\Mode;
use MSSTC4PHP\DtoGenerator\Application\Service\Generate\Output;
use MSSTC4PHP\DtoGenerator\Application\Service\Generate\WritePlan;
use MSSTC4PHP\DtoGenerator\Application\Service\Model\Build\Action as BuildModel;
use MSSTC4PHP\DtoGenerator\Application\Service\Schemas\Load\Action as LoadSchemas;
use MSSTC4PHP\DtoGenerator\Domain\Builder\NameResolver;
use MSSTC4PHP\DtoGenerator\Domain\Builder\SchemaParser;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostic;
use MSSTC4PHP\DtoGenerator\Infrastructure\Emitter\PhpParserEmitter;
use MSSTC4PHP\DtoGenerator\Tests\Support\FixedPhpConstraint;
use MSSTC4PHP\DtoGenerator\Tests\Support\InMemoryDocumentLoader;
use MSSTC4PHP\DtoGenerator\Tests\Support\RecordingWriter;
use PHPUnit\Framework\TestCase;

final class GenerateTest extends TestCase
{
    private const CONFIG = '/project/dto-generator.yaml';

    public function testEmitsEveryClassIntoTheOutputDirOfItsSource(): void
    {
        $writer = new RecordingWriter(new WritePlan([FileChange::create('/project/src/Dto/User.php', 'x')], [], []));

        $output = $this->generate($writer, Mode::WRITE);

        self::assertSame('ok', $output->status()->value());
        self::assertSame(['/project/src/Dto', '/project/other'], $writer->outputDirs);
        self::assertSame(['User.php', 'Tag.php', 'Pet.php'], array_map(static fn (GeneratedFile $file): string => $file->relativePath(), $output->files()));
        self::assertSame($output->files(), $writer->files);
        self::assertStringContainsString("final readonly class User\n", $output->files()[0]->contents());
        self::assertSame('/project/other', $output->files()[2]->outputDir());
        self::assertTrue($writer->applied);
        self::assertTrue($writer->released);
        self::assertNotNull($output->plan());
    }

    public function testKeepsWarningsOnSuccess(): void
    {
        $output = $this->generate(new RecordingWriter(new WritePlan([], [], [])), Mode::WRITE, ['Free' => ['type' => 'object', 'properties' => ['c' => ['type' => 'string', 'format' => 'color']]]]);

        self::assertSame('ok', $output->status()->value());
        self::assertSame(['warning /project/api/openapi.yaml#/components/schemas/Free/properties/c/format: Unknown string format "color"; the property stays a string.'], $this->messages($output));
    }

    public function testStopsOnAConfigError(): void
    {
        $writer = new RecordingWriter(new WritePlan([], [], []));
        $loader = new InMemoryDocumentLoader([self::CONFIG => ['version' => 1, 'unknown' => true]]);

        $output = $this->action($loader, $writer)(new Input(self::CONFIG, Mode::from(Mode::WRITE)));

        self::assertSame('config-failed', $output->status()->value());
        self::assertNotSame([], $this->messages($output));
        self::assertNull($writer->files);
        self::assertNull($output->plan());
    }

    public function testStopsOnASchemaErrorWithoutTouchingTheOutput(): void
    {
        $writer = new RecordingWriter(new WritePlan([], [], []));

        $output = $this->generate($writer, Mode::WRITE, ['Broken' => ['type' => 'object', 'properties' => ['x' => ['$ref' => '#/missing']]]]);

        self::assertSame('generation-failed', $output->status()->value());
        self::assertNull($writer->files);
        self::assertSame([], $output->files());
    }

    public function testReportsConflictsAsErrorsAndWritesNothing(): void
    {
        $writer = new RecordingWriter(new WritePlan([FileChange::create('/project/src/Dto/User.php', 'x')], ['/project/src/Dto/Tag.php' => 'Not ours.'], []));

        $output = $this->generate($writer, Mode::WRITE);

        self::assertSame('generation-failed', $output->status()->value());
        self::assertSame(['error /project/src/Dto/Tag.php#: Not ours.'], $this->messages($output));
        self::assertFalse($writer->applied);
        self::assertTrue($writer->released);
    }

    public function testChecksWithoutWriting(): void
    {
        $outdated = new RecordingWriter(new WritePlan([FileChange::update('/project/src/Dto/User.php', 'x')], [], []));
        $current = new RecordingWriter(new WritePlan([FileChange::unchanged('/project/src/Dto/User.php')], [], []));

        self::assertSame('out-of-date', $this->generate($outdated, Mode::CHECK)->status()->value());
        self::assertTrue($outdated->released);
        self::assertSame('ok', $this->generate($current, Mode::CHECK)->status()->value());
        self::assertFalse($outdated->applied);
        self::assertFalse($current->applied);
    }

    public function testPlansWithoutWritingOnADryRun(): void
    {
        $writer = new RecordingWriter(new WritePlan([FileChange::create('/project/src/Dto/User.php', 'x')], [], []));

        $output = $this->generate($writer, Mode::DRY_RUN);

        self::assertSame('ok', $output->status()->value());
        self::assertFalse($writer->applied);
        self::assertTrue($writer->released);
    }

    public function testReportsAFailedWrite(): void
    {
        $writer = new RecordingWriter(new WritePlan([], [], []), WriteFailed::at('/project/src/Dto/User.php', 'write'));

        $output = $this->generate($writer, Mode::WRITE);

        self::assertSame('generation-failed', $output->status()->value());
        self::assertSame(['error /project/src/Dto/User.php#: Cannot write "/project/src/Dto/User.php".'], $this->messages($output));
    }

    public function testPassesEachOutputDirOnce(): void
    {
        $writer = new RecordingWriter(new WritePlan([], [], []));
        $loader = new InMemoryDocumentLoader([
            self::CONFIG => ['version' => 1, 'target' => ['php' => '8.2'], 'sources' => [
                ['spec' => 'api/a.yaml', 'namespace' => 'App\A', 'outputDir' => 'shared'],
                ['spec' => 'api/b.yaml', 'namespace' => 'App\B', 'outputDir' => 'shared'],
                ['spec' => 'api/c.yaml', 'namespace' => 'App\C', 'outputDir' => 'own'],
            ]],
            '/project/api/a.yaml' => ['openapi' => '3.1.0'],
            '/project/api/b.yaml' => ['openapi' => '3.1.0'],
            '/project/api/c.yaml' => ['openapi' => '3.1.0'],
        ]);

        $this->action($loader, $writer)(new Input(self::CONFIG, Mode::from(Mode::WRITE)));

        self::assertSame(['/project/shared', '/project/own'], $writer->outputDirs);
    }

    /**
     * @param array<string, array<array-key, mixed>> $extraSchemas
     */
    private function generate(RecordingWriter $writer, string $mode, array $extraSchemas = []): Output
    {
        $loader = new InMemoryDocumentLoader([
            self::CONFIG => [
                'version' => 1,
                'target' => ['php' => '8.2'],
                'sources' => [
                    ['spec' => 'api/openapi.yaml', 'namespace' => 'App\Dto', 'outputDir' => 'src/Dto'],
                    ['spec' => 'api/pets.yaml', 'namespace' => 'App\Pets', 'outputDir' => 'other'],
                ],
            ],
            '/project/api/openapi.yaml' => ['openapi' => '3.1.0', 'components' => ['schemas' => [
                'User' => ['type' => 'object', 'required' => ['id'], 'properties' => ['id' => ['type' => 'integer'], 'tag' => ['$ref' => '#/components/schemas/Tag']]],
                'Tag' => ['type' => 'object', 'properties' => ['label' => ['type' => 'string']]],
            ] + $extraSchemas]],
            '/project/api/pets.yaml' => ['openapi' => '3.1.0', 'components' => ['schemas' => [
                'Pet' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]],
            ]]],
        ]);

        return $this->action($loader, $writer)(new Input(self::CONFIG, Mode::from($mode)));
    }

    private function action(InMemoryDocumentLoader $loader, RecordingWriter $writer): Action
    {
        return new Action(
            new LoadConfig($loader, new ConfigFactory(), new TargetResolver(new FixedPhpConstraint(null))),
            new LoadSchemas($loader, new SchemaParser()),
            new BuildModel(new NameResolver()),
            new PhpParserEmitter(),
            $writer,
        );
    }

    /**
     * @return list<string>
     */
    private function messages(Output $output): array
    {
        return array_map(static fn (Diagnostic $diagnostic): string => $diagnostic->toString(), $output->diagnostics()->all());
    }
}
