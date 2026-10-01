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
use MSSTC4PHP\DtoGenerator\Domain\Schema\ResolvedSchema;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Path;
use MSSTC4PHP\DtoGenerator\Infrastructure\Document\FileDocumentLoader;
use MSSTC4PHP\DtoGenerator\Infrastructure\Environment\ComposerJsonPhpConstraint;
use PHPUnit\Framework\TestCase;

final class PetstoreLoadingTest extends TestCase
{
    public function testLoadsTheProjectEndToEnd(): void
    {
        $loader = new FileDocumentLoader();
        $configPath = Path::normalize(__DIR__ . '/../Fixtures/Projects/petstore/dto-generator.yaml');

        $loaded = (new LoadConfig($loader, new ConfigFactory(), new TargetResolver(new ComposerJsonPhpConstraint())))(new ConfigInput($configPath));
        self::assertSame([], array_map(static fn (Diagnostic $d): string => $d->toString(), $loaded->diagnostics()->all()));
        $config = $loaded->config();
        $target = $loaded->target();
        self::assertNotNull($config);
        self::assertNotNull($target);
        self::assertSame('8.2', $target->php()->toString());

        $schemas = (new LoadSchemas($loader, new SchemaParser()))(new SchemasInput($config));

        self::assertSame([], array_map(static fn (Diagnostic $d): string => $d->toString(), $schemas->diagnostics()->all()));
        self::assertSame(
            [['Pet', 0, true], ['Tag', 0, true], ['Money', 0, false], ['Currency', 0, false]],
            array_map(
                static fn (ResolvedSchema $schema): array => [$schema->name(), $schema->source(), $schema->isSelected()],
                $schemas->graph()->all(),
            ),
        );

        $pet = $schemas->graph()->all()[0];
        $price = $pet->schema()->property('price');
        self::assertNotNull($price);
        $money = $schemas->graph()->resolve($price->references()[0]);
        self::assertNotNull($money);
        self::assertStringEndsWith('api/shared/common.json#/definitions/Money', $money->location()->toString());

        $model = (new BuildModel(new NameResolver()))(new BuildInput($config, $target, $schemas->graph()));

        self::assertSame(
            ['error ' . $money->location()->file() . '#/definitions/Currency: "enum" is not supported yet; enums, composition and inline objects arrive in a later version.'],
            array_map(static fn (Diagnostic $d): string => $d->toString(), $model->diagnostics()->all()),
        );
        self::assertSame(
            ['App\Dto\Pet', 'App\Dto\Tag', 'App\Dto\Money'],
            array_map(static fn (BuiltClass $class): string => $class->model()->name()->fqcn(), $model->classes()),
        );
    }
}
