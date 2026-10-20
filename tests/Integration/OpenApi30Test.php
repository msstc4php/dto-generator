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
use MSSTC4PHP\DtoGenerator\Domain\Model\PropertyModel;
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
            ['warning ' . dirname($file) . '/openapi.yaml#/openapi: OpenAPI 3.0.3 is read as 3.1, but for "nullable", which admits null; other keywords that differ in 3.0 (such as a boolean "exclusiveMinimum") are not understood.'],
            array_map(static fn (Diagnostic $d): string => $d->toString(), array_merge($loaded->diagnostics()->all(), $schemas->diagnostics()->all(), $model->diagnostics()->all())),
        );
        $customer = array_values(array_filter($model->classes(), static fn (BuiltClass $class): bool => $class->model()->name()->fqcn() === 'App\Dto\Customer'))[0]->model();
        self::assertSame(
            [
                'name' => 'string|null',
                'address' => 'App\Dto\Address|null',
                'billing' => 'App\Dto\Address|null',
                'status' => 'App\Dto\CustomerStatus|null',
                'tags' => 'list<string|null>|null',
            ],
            array_merge(...array_map(static fn (PropertyModel $property): array => [$property->name() => $property->type()->describe()], $customer->properties())),
        );
    }
}
