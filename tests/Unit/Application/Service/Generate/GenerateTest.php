<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Unit\Application\Service\Generate;

use MSSTC4PHP\DtoGenerator\Application\Config\ConfigFactory;
use MSSTC4PHP\DtoGenerator\Application\Config\TargetResolver;
use MSSTC4PHP\DtoGenerator\Application\Port\WriteFailed;
use MSSTC4PHP\DtoGenerator\Application\Service\Config\Load\Action as LoadConfig;
use MSSTC4PHP\DtoGenerator\Application\Service\Extension\Load\Action as LoadExtensions;
use MSSTC4PHP\DtoGenerator\Application\Service\Generate\Action;
use MSSTC4PHP\DtoGenerator\Application\Service\Generate\FileChange;
use MSSTC4PHP\DtoGenerator\Application\Service\Generate\GeneratedFile;
use MSSTC4PHP\DtoGenerator\Application\Service\Generate\Input;
use MSSTC4PHP\DtoGenerator\Application\Service\Generate\Mode;
use MSSTC4PHP\DtoGenerator\Application\Service\Generate\Output;
use MSSTC4PHP\DtoGenerator\Application\Service\Generate\WritePlan;
use MSSTC4PHP\DtoGenerator\Application\Service\Model\Build\Action as BuildModel;
use MSSTC4PHP\DtoGenerator\Application\Service\Model\Enrich\Action as EnrichModel;
use MSSTC4PHP\DtoGenerator\Application\Service\Schemas\Load\Action as LoadSchemas;
use MSSTC4PHP\DtoGenerator\Domain\Builder\NameResolver;
use MSSTC4PHP\DtoGenerator\Domain\Builder\SchemaParser;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostic;
use MSSTC4PHP\DtoGenerator\Extension\CustomAttributes\CustomAttributes;
use MSSTC4PHP\DtoGenerator\Infrastructure\Emitter\PhpParserEmitter;
use MSSTC4PHP\DtoGenerator\Infrastructure\Extension\ClassExtensionLoader;
use MSSTC4PHP\DtoGenerator\Tests\Support\Extensions\MoneyFormatExtension;
use MSSTC4PHP\DtoGenerator\Tests\Support\Extensions\PackageVersionExtension;
use MSSTC4PHP\DtoGenerator\Tests\Support\FixedClassVerifier;
use MSSTC4PHP\DtoGenerator\Tests\Support\FixedClassVerifierLocator;
use MSSTC4PHP\DtoGenerator\Tests\Support\FixedExtensionDiscovery;
use MSSTC4PHP\DtoGenerator\Tests\Support\FixedPhpConstraint;
use MSSTC4PHP\DtoGenerator\Tests\Support\FixedProjectPackages;
use MSSTC4PHP\DtoGenerator\Tests\Support\InMemoryDocumentLoader;
use MSSTC4PHP\DtoGenerator\Tests\Support\RecordingWriter;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class GenerateTest extends TestCase
{
    private const CONFIG = '/project/dto-generator.yaml';

    public function testEmitsEveryClassIntoTheOutputDirOfItsSource(): void
    {
        $writer = new RecordingWriter(new WritePlan([FileChange::create('/project/src/Dto/User.php', 'x')], [], []));

        $output = $this->generate($writer, Mode::WRITE);

        self::assertSame('ok', $output->status()->value());
        self::assertSame(['/project/src/Dto', '/project/other'], $writer->outputDirs);
        self::assertSame(['User.php', 'Tag.php', 'Pet.php', 'Currency.php'], array_map(static fn (GeneratedFile $file): string => $file->relativePath(), $output->files()));
        self::assertStringContainsString("enum Currency: string\n", $output->files()[3]->contents());
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

    public function testHandsTheInheritedPropertiesToTheEmitter(): void
    {
        $output = $this->generate(new RecordingWriter(new WritePlan([], [], [])), Mode::WRITE, [
            'Admin' => ['allOf' => [['$ref' => '#/components/schemas/User'], ['properties' => ['level' => ['type' => 'integer']]]]],
        ]);

        self::assertSame('ok', $output->status()->value());
        self::assertStringContainsString("\nreadonly class User\n", $output->files()[0]->contents());
        $admin = $output->files()[2]->contents();
        self::assertStringContainsString("\nfinal readonly class Admin extends User\n", $admin);
        self::assertStringContainsString('public function __construct(int $id, ?Tag $tag = null, public ?int $level = null)', $admin);
        self::assertStringContainsString('parent::__construct($id, $tag);', $admin);
    }

    public function testUsesTheFormatsOfConfiguredExtensions(): void
    {
        $output = $this->generate(
            new RecordingWriter(new WritePlan([], [], [])),
            Mode::WRITE,
            ['Price' => ['type' => 'object', 'required' => ['amount'], 'properties' => ['amount' => ['type' => 'string', 'format' => 'money']]]],
            ['extensions' => [MoneyFormatExtension::class]],
        );

        self::assertSame('ok', $output->status()->value());
        self::assertStringContainsString('@param numeric-string $amount', $output->files()[2]->contents());
    }

    public function testMapsFormatsOfTheConfig(): void
    {
        $output = $this->generate(
            new RecordingWriter(new WritePlan([], [], [])),
            Mode::WRITE,
            ['Item' => ['type' => 'object', 'required' => ['id'], 'properties' => ['id' => ['type' => 'string', 'format' => 'uuid']]]],
            ['formats' => ['uuid' => ['type' => 'App\Uuid']]],
        );

        self::assertSame('ok', $output->status()->value());
        self::assertStringContainsString('public \App\Uuid $id', $output->files()[2]->contents());
    }

    public function testFailsOnAMistakeInTheAttributesOfASchema(): void
    {
        $writer = new RecordingWriter(new WritePlan([], [], []));
        $output = $this->generate($writer, Mode::WRITE, ['Item' => ['type' => 'object', 'x-php-attributes' => 'App\Attr', 'properties' => ['id' => ['type' => 'string']]]]);

        self::assertSame('generation-failed', $output->status()->value());
        self::assertSame(['error /project/api/openapi.yaml#/components/schemas/Item/x-php-attributes: "x-php-attributes" must be a list of attributes.'], $this->messages($output));
    }

    public function testAppliesAttributeAliasesAndChecksWhereTheyApply(): void
    {
        $output = $this->generate(
            new RecordingWriter(new WritePlan([], [], [])),
            Mode::WRITE,
            ['Item' => ['type' => 'object', 'properties' => ['tags' => ['type' => 'array', 'items' => ['type' => 'string', 'x-audit' => 'x']]]]],
            ['attributeAliases' => ['x-audit' => ['class' => 'App\\Attr\\Audited']]],
        );

        self::assertSame(['warning /project/api/openapi.yaml#/components/schemas/Item/properties/tags/items/x-audit: "x-audit" has no effect here.'], $this->messages($output));
    }

    public function testRefusesToVerifyClassesWithoutAnAutoloader(): void
    {
        $writer = new RecordingWriter(new WritePlan([], [], []));
        $output = $this->generate($writer, Mode::WRITE, [], ['verifyClasses' => true]);

        self::assertSame('config-failed', $output->status()->value());
        self::assertSame(['error /project/dto-generator.yaml#/verifyClasses: "verifyClasses" is true, but the Composer project at or above /project has no autoload.php in its vendor-dir.'], $this->messages($output));
    }

    public function testVerifiesTheClassesOfAttributesWhenAnAutoloaderIsFound(): void
    {
        $schemas = ['Item' => ['type' => 'object', 'x-php-attributes' => [['class' => 'App\\Missing']], 'properties' => ['id' => ['type' => 'string']]]];

        $found = $this->generate(new RecordingWriter(new WritePlan([], [], [])), Mode::WRITE, $schemas, [], new FixedClassVerifierLocator(new FixedClassVerifier([])));
        $disabled = $this->generate(new RecordingWriter(new WritePlan([], [], [])), Mode::WRITE, $schemas, [], new FixedClassVerifierLocator(new FixedClassVerifier([]), true));
        $off = $this->generate(new RecordingWriter(new WritePlan([], [], [])), Mode::WRITE, $schemas, ['verifyClasses' => false], new FixedClassVerifierLocator(new FixedClassVerifier([])));
        $forced = $this->generate(new RecordingWriter(new WritePlan([], [], [])), Mode::WRITE, $schemas, ['verifyClasses' => true], new FixedClassVerifierLocator(new FixedClassVerifier([]), true));

        self::assertSame('generation-failed', $found->status()->value());
        self::assertSame(['error /project/api/openapi.yaml#/components/schemas/Item: Attribute class App\\Missing does not exist.'], $this->messages($found));
        self::assertSame('ok', $disabled->status()->value());
        self::assertSame('ok', $off->status()->value());
        self::assertSame('generation-failed', $forced->status()->value());
    }

    public function testFailsOnAnExtensionThatCannotBeLoaded(): void
    {
        $writer = new RecordingWriter(new WritePlan([], [], []));
        $output = $this->generate($writer, Mode::WRITE, [], ['extensions' => ['App\Missing\Extension']]);

        self::assertSame('generation-failed', $output->status()->value());
        self::assertSame(['error /project/dto-generator.yaml#/extensions/0: Extension App\Missing\Extension cannot be loaded: Class App\Missing\Extension does not exist.'], $this->messages($output));
        self::assertFalse($writer->applied);
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

    public function testReleasesTheWriterWhenPlanningFails(): void
    {
        $writer = new RecordingWriter(new WritePlan([], [], []), null, new RuntimeException('Disk gone.'));

        try {
            $this->generate($writer, Mode::WRITE);
            self::fail('The failure was swallowed.');
        } catch (RuntimeException $exception) {
            self::assertSame('Disk gone.', $exception->getMessage());
        }

        self::assertTrue($writer->released);
    }

    public function testHandsTheConsumersPackagesToExtensions(): void
    {
        $output = $this->generate(
            new RecordingWriter(new WritePlan([], [], [])),
            Mode::WRITE,
            [],
            ['extensions' => [PackageVersionExtension::class]],
            null,
            new FixedProjectPackages(['symfony/validator' => 'v7.1.0']),
        );

        self::assertSame([], $this->messages($output));
        self::assertStringContainsString("#[\\App\\Attr\\Validator('v7.1.0')]\nfinal readonly class User\n", $output->files()[0]->contents());
    }

    public function testWarnsAboutAnUnusableLockAndGoesOnWithoutPackages(): void
    {
        $output = $this->generate(
            new RecordingWriter(new WritePlan([], [], [])),
            Mode::WRITE,
            [],
            ['extensions' => [PackageVersionExtension::class]],
            null,
            new FixedProjectPackages([], 'is not valid JSON'),
        );

        self::assertSame('ok', $output->status()->value());
        self::assertSame(['warning /project/composer.lock#: The installed package versions are unknown: the file is not valid JSON.'], $this->messages($output));
        self::assertStringContainsString("#[\\App\\Attr\\Validator('none')]\n", $output->files()[0]->contents());
    }

    /**
     * @param array<string, array<array-key, mixed>> $extraSchemas
     * @param array<string, array<array-key, mixed>|bool> $extraConfig
     */
    private function generate(RecordingWriter $writer, string $mode, array $extraSchemas = [], array $extraConfig = [], ?FixedClassVerifierLocator $verifiers = null, ?FixedProjectPackages $packages = null): Output
    {
        $loader = new InMemoryDocumentLoader([
            self::CONFIG => [
                'version' => 1,
                'target' => ['php' => '8.2'],
                'sources' => [
                    ['spec' => 'api/openapi.yaml', 'namespace' => 'App\Dto', 'outputDir' => 'src/Dto'],
                    ['spec' => 'api/pets.yaml', 'namespace' => 'App\Pets', 'outputDir' => 'other'],
                ],
            ] + $extraConfig,
            '/project/api/openapi.yaml' => ['openapi' => '3.1.0', 'components' => ['schemas' => [
                'User' => ['type' => 'object', 'required' => ['id'], 'properties' => ['id' => ['type' => 'integer'], 'tag' => ['$ref' => '#/components/schemas/Tag']]],
                'Tag' => ['type' => 'object', 'properties' => ['label' => ['type' => 'string']]],
                'Currency' => ['type' => 'string', 'enum' => ['EUR']],
            ] + $extraSchemas]],
            '/project/api/pets.yaml' => ['openapi' => '3.1.0', 'components' => ['schemas' => [
                'Pet' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]],
            ]]],
        ]);

        return $this->action($loader, $writer, $verifiers ?? new FixedClassVerifierLocator(), $packages)(new Input(self::CONFIG, Mode::from($mode)));
    }

    private function action(InMemoryDocumentLoader $loader, RecordingWriter $writer, ?FixedClassVerifierLocator $verifiers = null, ?FixedProjectPackages $packages = null): Action
    {
        return new Action(
            new LoadConfig($loader, new ConfigFactory(), new TargetResolver(new FixedPhpConstraint(null))),
            $verifiers ?? new FixedClassVerifierLocator(),
            $packages ?? new FixedProjectPackages(),
            new LoadExtensions(new ClassExtensionLoader(), static fn (array $aliases): array => [new CustomAttributes($aliases)], new FixedExtensionDiscovery()),
            new LoadSchemas($loader, new SchemaParser()),
            new BuildModel(new NameResolver()),
            new EnrichModel(),
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
