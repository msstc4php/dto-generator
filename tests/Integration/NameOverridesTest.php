<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Integration;

use MSSTC4PHP\DtoGenerator\Application\Config\ConfigFactory;
use MSSTC4PHP\DtoGenerator\Application\Config\TargetResolver;
use MSSTC4PHP\DtoGenerator\Application\Service\Config\Load\Action as LoadConfig;
use MSSTC4PHP\DtoGenerator\Application\Service\Config\Load\Input as ConfigInput;
use MSSTC4PHP\DtoGenerator\Application\Service\Model\Build\Action as BuildModel;
use MSSTC4PHP\DtoGenerator\Application\Service\Model\Build\BuiltClass;
use MSSTC4PHP\DtoGenerator\Application\Service\Model\Build\Input as BuildInput;
use MSSTC4PHP\DtoGenerator\Application\Service\Schemas\Load\Action as LoadSchemas;
use MSSTC4PHP\DtoGenerator\Application\Service\Schemas\Load\Input as SchemasInput;
use MSSTC4PHP\DtoGenerator\Domain\Builder\NameResolver;
use MSSTC4PHP\DtoGenerator\Domain\Builder\SchemaParser;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostic;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostics;
use MSSTC4PHP\DtoGenerator\Domain\Model\PropertyModel;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Path;
use MSSTC4PHP\DtoGenerator\Infrastructure\Document\FileDocumentLoader;
use MSSTC4PHP\DtoGenerator\Infrastructure\Environment\ComposerJsonPhpConstraint;
use PHPUnit\Framework\TestCase;

final class NameOverridesTest extends TestCase
{
    public function testNamesWhatTheDocumentGivesOneNameTwiceFromTheConfiguration(): void
    {
        $loader = new FileDocumentLoader();
        $loaded = (new LoadConfig($loader, new ConfigFactory(), new TargetResolver(new ComposerJsonPhpConstraint())))(
            new ConfigInput(Path::normalize(__DIR__ . '/../Fixtures/Projects/names/dto-generator.yaml')),
        );
        $config = $loaded->config();
        $target = $loaded->target();
        self::assertNotNull($config);
        self::assertNotNull($target);

        $schemas = (new LoadSchemas($loader, new SchemaParser()))(new SchemasInput($config));
        $model = (new BuildModel(new NameResolver()))(new BuildInput($config, $target, $schemas->graph(), []));

        self::assertSame([], array_map(static fn (Diagnostic $d): string => $d->toString(), array_merge($loaded->diagnostics()->all(), $schemas->diagnostics()->all(), $model->diagnostics()->all())));
        self::assertSame(
            [
                'App\Dto\FieldList' => ['all'],
                'App\Dto\Fields' => ['issuetype', 'issueTypeObject'],
                'App\Dto\IssueType' => ['id'],
            ],
            array_merge(...array_map(
                static fn (BuiltClass $class): array => [$class->model()->name()->fqcn() => array_map(static fn (PropertyModel $property): string => $property->name(), $class->model()->properties())],
                $model->classes(),
            )),
        );
    }

    public function testChecksANameFromTheConfigurationLikeOneOfTheDocument(): void
    {
        $directory = Path::normalize(__DIR__ . '/../Fixtures/Projects/names');
        $diagnostics = new Diagnostics();
        $config = (new ConfigFactory())->create(
            ['version' => 1, 'sources' => [['spec' => 'openapi.yaml', 'namespace' => 'App\\Dto', 'outputDir' => 'src', 'classNames' => ['#/components/schemas/fields' => 'Field-List']]]],
            $directory . '/dto-generator.yaml',
            $diagnostics,
        );
        self::assertNotNull($config);
        $target = (new TargetResolver(new ComposerJsonPhpConstraint()))->resolve($config, $diagnostics);
        self::assertNotNull($target);

        $schemas = (new LoadSchemas(new FileDocumentLoader(), new SchemaParser()))(new SchemasInput($config));
        $model = (new BuildModel(new NameResolver()))(new BuildInput($config, $target, $schemas->graph(), []));

        self::assertSame(
            [
                'error ' . $directory . '/openapi.yaml#/components/schemas/fields/x-php-class-name: "x-php-class-name" must be a PHP identifier that is not a reserved word.',
            ],
            array_map(static fn (Diagnostic $d): string => $d->toString(), array_merge($diagnostics->errors(), $schemas->diagnostics()->errors(), $model->diagnostics()->errors())),
        );
    }
}
