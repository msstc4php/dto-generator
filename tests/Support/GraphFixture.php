<?php

declare(strict_types=1);

namespace MSSTC4PHP\DtoGenerator\Tests\Support;

use LogicException;
use MSSTC4PHP\DtoGenerator\Application\Config\SourceConfig;
use MSSTC4PHP\DtoGenerator\Application\Service\Schemas\Load\Action;
use MSSTC4PHP\DtoGenerator\Application\Service\Schemas\Load\Input;
use MSSTC4PHP\DtoGenerator\Domain\Builder\SchemaParser;
use MSSTC4PHP\DtoGenerator\Domain\Diagnostic\Diagnostic;
use MSSTC4PHP\DtoGenerator\Domain\Schema\SchemaGraph;

final class GraphFixture
{
    public const SPEC = '/project/api/openapi.yaml';

    /**
     * @param array<int|string, array<array-key, mixed>> $schemas components/schemas of the spec
     * @param array<string, array<array-key, mixed>> $extraDocuments further files by absolute path
     */
    public static function load(array $schemas, array $extraDocuments = [], ?SourceConfig $source = null): SchemaGraph
    {
        $documents = [self::SPEC => ['openapi' => '3.1.0', 'components' => ['schemas' => $schemas]]] + $extraDocuments;
        $config = ConfigMother::config($source ?? ConfigMother::source(self::SPEC));
        $output = (new Action(new InMemoryDocumentLoader($documents), new SchemaParser()))(new Input($config));
        if ($output->diagnostics()->hasErrors()) {
            throw new LogicException(implode("\n", array_map(static fn (Diagnostic $d): string => $d->toString(), $output->diagnostics()->all())));
        }

        return $output->graph();
    }
}
