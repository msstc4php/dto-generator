<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Integration;

use MSSTC4PHP\DtoGenerator\Application\Config\ConfigFactory;
use MSSTC4PHP\DtoGenerator\Application\Config\TargetResolver;
use MSSTC4PHP\DtoGenerator\Application\Service\Config\Load\Action as LoadConfig;
use MSSTC4PHP\DtoGenerator\Application\Service\Config\Load\Input as ConfigInput;
use MSSTC4PHP\DtoGenerator\Application\Service\Model\Build\Action as BuildModel;
use MSSTC4PHP\DtoGenerator\Application\Service\Model\Build\Input as BuildInput;
use MSSTC4PHP\DtoGenerator\Application\Service\Schemas\Load\Action as LoadSchemas;
use MSSTC4PHP\DtoGenerator\Application\Service\Schemas\Load\Input as SchemasInput;
use MSSTC4PHP\DtoGenerator\Domain\Builder\NameResolver;
use MSSTC4PHP\DtoGenerator\Domain\Builder\SchemaParser;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostic;
use MSSTC4PHP\DtoGenerator\Domain\Shared\Path;
use MSSTC4PHP\DtoGenerator\Infrastructure\Document\FileDocumentLoader;
use MSSTC4PHP\DtoGenerator\Infrastructure\Environment\ComposerJsonPhpConstraint;
use PHPUnit\Framework\TestCase;

final class OpenApi30Test extends TestCase
{
    public function testAdmitsNullWhereOpenApi30SaysNullable(): void
    {
        $loader = new FileDocumentLoader();
        $file = Path::normalize(__DIR__ . '/../Fixtures/Projects/openapi30/dto-generator.yaml');
        $loaded = (new LoadConfig($loader, new ConfigFactory(), new TargetResolver(new ComposerJsonPhpConstraint())))(new ConfigInput($file));
        $config = $loaded->config();
        $target = $loaded->target();
        self::assertNotNull($config);
        self::assertNotNull($target);

        $schemas = (new LoadSchemas($loader, new SchemaParser()))(new SchemasInput($config));
        $model = (new BuildModel(new NameResolver()))(new BuildInput($config, $target, $schemas->graph(), []));

        self::assertSame(
            ['warning ' . dirname($file) . '/openapi.yaml#/openapi: OpenAPI 3.0.3 is read as 3.1, except that "nullable: true" admits null; other keywords that differ in 3.0 (such as a boolean "exclusiveMinimum") are not understood.'],
            array_map(static fn (Diagnostic $d): string => $d->toString(), array_merge($loaded->diagnostics()->all(), $schemas->diagnostics()->all(), $model->diagnostics()->all())),
        );
        $properties = [];
        foreach ($model->classes() as $class) {
            foreach ($class->model()->properties() as $property) {
                $properties[$class->model()->name()->fqcn()][$property->name()] = $property->type()->describe();
            }
        }

        self::assertSame(['App\Dto\ExtendedCat', 'App\Dto\ExtendedDog'], array_values(array_filter(array_keys($properties), static fn (string $name): bool => strpos($name, 'App\Dto\Extended') === 0)));
        self::assertSame(['pet' => 'App\Dto\Pet|null', 'other' => 'App\Dto\Cat|App\Dto\Dog|null'], $properties['App\Dto\Owner']);
        self::assertSame(
            [
                'name' => 'string|null',
                'address' => 'App\Dto\Address|null',
                'billing' => 'App\Dto\Address|null',
                'status' => 'App\Dto\CustomerStatus|null',
                'tags' => 'list<string|null>|null',
            ],
            $properties['App\Dto\Customer'],
        );
    }
}
